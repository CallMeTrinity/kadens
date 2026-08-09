<?php

namespace App\Tests\Service;

use App\Enum\BodySide;
use App\Enum\BodySilhouette;
use App\Enum\TargetArea;
use App\Service\BodyLoad;
use App\Service\BodyPlates;
use PHPUnit\Framework\TestCase;

/**
 * Deux familles de tests, et la seconde est la plus importante.
 *
 * Le calcul des paliers se relit ; la correspondance zone ↔ tracé, non. PHP
 * garantit que les seize zones sont dans `AREA_TO_SLUG`, pas que les seize slugs
 * existent encore dans `data/body-plates.php`. Une planche régénérée qui
 * renommerait un muscle laisserait une zone éternellement grise, **en silence** —
 * c'est exactement ce que `BodyMap.test.tsx` protège côté mobile, et ce test en
 * est la transcription.
 */
final class BodyLoadTest extends TestCase
{
    private BodyLoad $load;
    private BodyPlates $plates;

    protected function setUp(): void
    {
        $this->load = new BodyLoad();
        $this->plates = new BodyPlates(\dirname(__DIR__, 2));
    }

    // ---- Cohérence entre les zones et le dessin ----------------------------

    public function testEveryTargetAreaPointsToAMuscleThatIsActuallyDrawn(): void
    {
        $drawn = $this->drawnMuscleSlugs();

        foreach (TargetArea::cases() as $area) {
            if (TargetArea::FULL_BODY === $area) {
                // Elle ne se peint jamais : trois burpees allumeraient tout le corps.
                self::assertArrayNotHasKey($area->value, BodyLoad::AREA_TO_SLUG);

                continue;
            }

            self::assertArrayHasKey($area->value, BodyLoad::AREA_TO_SLUG, sprintf('La zone "%s" n\'a pas de muscle en face.', $area->value));

            $slug = BodyLoad::AREA_TO_SLUG[$area->value];
            self::assertContains($slug, $drawn, sprintf('Le muscle "%s" (zone "%s") n\'est dessiné sur aucune planche.', $slug, $area->value));
        }
    }

    public function testEveryDrawnMuscleHasATargetAreaAndNoneIsClaimedTwice(): void
    {
        $mapped = array_values(BodyLoad::AREA_TO_SLUG);

        self::assertSame($mapped, array_unique($mapped), 'Deux zones pointent le même muscle : la correspondance doit rester bijective.');

        foreach ($this->drawnMuscleSlugs() as $slug) {
            self::assertContains($slug, $mapped, sprintf('Le muscle "%s" est dessiné mais aucune zone ne le remplit : il restera gris.', $slug));
        }
    }

    public function testInertShapesNeverLeakIntoMusclesAndHairIsDrawnLast(): void
    {
        foreach ($this->plates->all() as $key => $plate) {
            $muscles = array_column($plate['muscles'], 'slug');
            $inert = array_column($plate['inert'], 'slug');

            foreach ($inert as $slug) {
                self::assertContains($slug, BodyPlates::INERT_ORDER, sprintf('Forme inerte inconnue sur "%s" : %s', $key, $slug));
                // Une chevelure dans `muscles` finirait un jour teintée en rouge vif.
                self::assertNotContains($slug, $muscles, sprintf('La forme inerte "%s" est aussi déclarée peignable sur "%s".', $slug, $key));
            }

            // Les cheveux passent APRÈS le crâne : la source féminine les déclare
            // dans l'autre sens, ce qui les ferait disparaître dessous.
            self::assertSame('hair', end($inert), sprintf('Sur "%s", la chevelure doit être dessinée en dernier.', $key));
            self::assertNotEmpty($plate['outline'], sprintf('La planche "%s" n\'a pas de contour.', $key));
        }
    }

    public function testTheFourPlatesExistAndCarryTheirOwnViewBox(): void
    {
        $seen = [];

        foreach (BodySilhouette::cases() as $silhouette) {
            foreach (BodySide::cases() as $side) {
                $plate = $this->plates->plate($silhouette, $side);

                self::assertCount(4, explode(' ', $plate['viewBox']));
                self::assertNotEmpty($plate['muscles']);
                $seen[] = $plate['viewBox'];
            }
        }

        self::assertCount(4, $seen);
        // Les quatre diffèrent (le corps féminin de face est plus haut) : c'est ce
        // qui oblige le rendu à déduire la largeur de la hauteur, jamais l'inverse.
        self::assertSame($seen, array_unique($seen));
    }

    // ---- Le calcul des paliers ---------------------------------------------

    public function testLevelsAreThirdsOfTheSessionPeakNotAnAbsoluteScale(): void
    {
        // Pic à 12 : > 8 -> palier 3, > 4 -> palier 2, sinon 1.
        $load = $this->load->for([
            'chest' => 12,
            'triceps' => 6,
            'calves' => 3,
        ]);

        $levels = $load['levels'];
        self::assertSame(3, $levels['chest']);
        self::assertSame(2, $levels['triceps']);
        self::assertSame(1, $levels['calves']);

        // Les mêmes proportions dans une séance quatre fois plus grosse donnent
        // exactement les mêmes paliers : la carte dit « où », pas « combien ».
        $bigger = $this->load->for(['chest' => 48, 'triceps' => 24, 'calves' => 12]);
        self::assertSame($levels, $bigger['levels']);
    }

    public function testAreasAreSortedByVolumeAndPercentUsesTheAttributedTotal(): void
    {
        $load = $this->load->for(['calves' => 2, 'chest' => 8]);

        self::assertSame([TargetArea::CHEST, TargetArea::CALVES], array_column($load['areas'], 'area'));
        self::assertSame(10, $load['attributed']);
        // 8 / 10 et 2 / 10 : la part se calcule sur le total ATTRIBUÉ, sinon elle
        // dépasserait 100 dès le premier exercice polyarticulaire.
        self::assertSame(80.0, $load['areas'][0]['percent']);
        self::assertSame(20.0, $load['areas'][1]['percent']);
    }

    public function testFullBodyIsCountedApartAndPaintsNothing(): void
    {
        $load = $this->load->for(['full_body' => 9, 'abs' => 4]);

        self::assertSame(9, $load['fullBody']);
        self::assertArrayNotHasKey('full_body', $load['levels']);
        self::assertSame([TargetArea::ABS], array_column($load['areas'], 'area'));
        // Elle n'entre pas non plus dans le total attribué, sinon la part des
        // abdominaux fondrait sans qu'aucune zone ne s'allume en face.
        self::assertSame(4, $load['attributed']);
    }

    public function testUnmappedSetsAreCarriedThroughAndZeroSetsAreIgnored(): void
    {
        $load = $this->load->for(['chest' => 0, 'abs' => 5], 3);

        self::assertSame(3, $load['unmapped']);
        self::assertArrayNotHasKey('chest', $load['levels']);
        self::assertSame(5, $load['attributed']);
    }

    public function testAnEmptySessionIsAnEmptyDrawingNotADegradedState(): void
    {
        $load = $this->load->for([]);

        self::assertSame([], $load['areas']);
        self::assertSame([], $load['levels']);
        self::assertSame(0, $load['attributed']);
        self::assertSame(0, $load['fullBody']);
        self::assertSame(0, $load['unmapped']);
    }

    /**
     * Tous les slugs de muscles dessinés, toutes planches confondues.
     *
     * @return list<string>
     */
    private function drawnMuscleSlugs(): array
    {
        $slugs = [];

        foreach ($this->plates->all() as $plate) {
            foreach ($plate['muscles'] as $muscle) {
                $slugs[$muscle['slug']] = $muscle['slug'];
            }
        }

        return array_values($slugs);
    }
}
