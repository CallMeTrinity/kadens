<?php

namespace App\Tests\Service;

use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\ScheduledWorkout;
use App\Enum\ActivityType;
use App\Enum\SetType;
use App\Enum\TargetArea;
use App\Enum\TargetRegion;
use App\Service\LogMetrics;
use App\Service\RegionBreakdown;
use PHPUnit\Framework\TestCase;

final class LogMetricsTest extends TestCase
{
    private LogMetrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = new LogMetrics(new RegionBreakdown());
    }

    public function testSummaryIsNullWithoutAnyLoggedExercise(): void
    {
        // Une séance simplement cochée « faite » n'a pas de réalisé à résumer :
        // l'appelant n'a pas à distinguer « zéro » de « rien ».
        $scheduled = $this->scheduled();
        $scheduled->setStartedAt(new \DateTimeImmutable('2026-07-30 18:00:00'));
        $scheduled->setEndedAt(new \DateTimeImmutable('2026-07-30 19:00:00'));

        self::assertNull($this->metrics->summary($scheduled));
    }

    public function testWarmupCountsNeitherAsWorkingSetNorAsTonnage(): void
    {
        // 1 échauffement 10 @ 40, 2 travail 8 @ 100, 1 drop 6 @ 80.
        $logged = $this->logged('Développé couché', [TargetArea::CHEST], [
            [SetType::WARMUP, 10, 40.0],
            [SetType::NORMAL, 8, 100.0],
            [SetType::NORMAL, 8, 100.0],
            [SetType::DROP_SET, 6, 80.0],
        ]);

        $summary = $this->metrics->summary($this->scheduled([$logged]));

        self::assertNotNull($summary);
        self::assertSame(3, $summary['workingSets']);
        // 8×100 + 8×100 + 6×80 = 2080, l'échauffement (400) est hors volume.
        self::assertSame(2080.0, $summary['tonnageKg']);
        self::assertSame(1, $summary['exerciseCount']);
        self::assertSame(3, $summary['regions'][0]['sets']);
    }

    public function testWarmupNeverBecomesTheTopLift(): void
    {
        // Échauffement plus lourd que le travail : cas absurde en salle, mais
        // c'est exactement ce qu'un mauvais filtre laisserait passer en record.
        $logged = $this->logged('Squat', [TargetArea::QUADRICEPS], [
            [SetType::WARMUP, 3, 200.0],
            [SetType::NORMAL, 5, 120.0],
        ]);

        $summary = $this->metrics->summary($this->scheduled([$logged]));

        self::assertNotNull($summary);
        self::assertNotNull($summary['topLift']);
        self::assertSame(120.0, $summary['topLift']['weightKg']);
        self::assertSame('Squat', $summary['topLift']['exercise']);
    }

    public function testAverageRpeIsWeightedByWorkingSets(): void
    {
        // 4 séries à RPE 9, 2 à RPE 6 : une moyenne par exercice donnerait 7,5.
        // Le RPE étant porté par la série, la pondération est naturelle → 8.
        $squat = $this->logged('Squat', [TargetArea::QUADRICEPS], [
            [SetType::NORMAL, 5, 120.0, 9],
            [SetType::NORMAL, 5, 120.0, 9],
            [SetType::NORMAL, 5, 120.0, 9],
            [SetType::NORMAL, 5, 120.0, 9],
        ]);
        $curl = $this->logged('Curl', [TargetArea::BICEPS], [
            [SetType::NORMAL, 12, 20.0, 6],
            [SetType::NORMAL, 12, 20.0, 6],
        ]);

        $summary = $this->metrics->summary($this->scheduled([$squat, $curl]));

        self::assertNotNull($summary);
        self::assertSame(8.0, $summary['averageRpe']);
        self::assertSame(6, $summary['workingSets']);
    }

    public function testUnmeasuredSetCountsNowhere(): void
    {
        // Une série cochée sans rien saisir (« ? » à l'écran) ou ramenée à zéro
        // répétition : elle a eu lieu, mais elle ne mesure aucun travail. La
        // charge seule ne la sauve pas — 140 kg × 0 rep, c'est une barre qu'on
        // n'a pas soulevée, et la laisser passer en ferait un record.
        $logged = $this->logged('Squat', [TargetArea::QUADRICEPS], [
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, null, 140.0, 9],
            [SetType::NORMAL, 0, 140.0],
        ]);

        $summary = $this->metrics->summary($this->scheduled([$logged]));

        self::assertNotNull($summary);
        self::assertSame(2, $summary['workingSets']);
        self::assertSame(1000.0, $summary['tonnageKg']);
        self::assertSame(2, $summary['regions'][0]['sets']);
        self::assertNotNull($summary['topLift']);
        self::assertSame(100.0, $summary['topLift']['weightKg']);
        // Le RPE d'une série qui ne compte pas ne pèse pas non plus dans la
        // moyenne : elle n'est pas dans le volume qui la pondère.
        self::assertNull($summary['averageRpe']);
    }

    public function testTimedSetCountsWithoutAnyRepetition(): void
    {
        // Le pendant, sans quoi la règle mangerait le gainage : une série en
        // durée n'a pas de répétitions et reste du volume de travail.
        $logged = $this->logged('Gainage', [TargetArea::ABS], [
            [SetType::NORMAL, null, null, null, 60],
            [SetType::NORMAL, null, null, null, 45],
        ]);

        $summary = $this->metrics->summary($this->scheduled([$logged]));

        self::assertNotNull($summary);
        self::assertSame(2, $summary['workingSets']);
        self::assertSame(0.0, $summary['tonnageKg']);
        self::assertSame(2, $summary['regions'][0]['sets']);
    }

    public function testSkippedExerciseIsCountedApartAndBringsNoVolume(): void
    {
        $done = $this->logged('Squat', [TargetArea::QUADRICEPS], [
            [SetType::NORMAL, 5, 100.0],
        ]);
        // Un exercice sauté peut porter des séries (saisies puis abandonnées) :
        // elles ne doivent pas entrer dans le volume pour autant.
        $skipped = $this->logged('Fentes', [TargetArea::GLUTES], [
            [SetType::NORMAL, 10, 40.0],
        ]);
        $skipped->setSkipped(true);

        $summary = $this->metrics->summary($this->scheduled([$done, $skipped]));

        self::assertNotNull($summary);
        self::assertSame(1, $summary['skipped']);
        self::assertSame(1, $summary['exerciseCount']);
        self::assertSame(1, $summary['workingSets']);
        self::assertSame(500.0, $summary['tonnageKg']);
    }

    public function testVolumeSurvivesAnExerciseDeletedFromTheLibrary(): void
    {
        // SET NULL sur `exercise` + snapshot du nom : nettoyer la bibliothèque ne
        // doit pas effacer le tonnage d'une séance réellement faite. Seule la
        // ventilation par région disparaît, faute de zones ciblées.
        $orphan = $this->logged('Rowing barre', [TargetArea::BACK], [
            [SetType::NORMAL, 10, 60.0],
        ]);
        $orphan->setExercise(null);

        $summary = $this->metrics->summary($this->scheduled([$orphan]));

        self::assertNotNull($summary);
        self::assertSame(600.0, $summary['tonnageKg']);
        self::assertSame(1, $summary['workingSets']);
        self::assertSame([], $summary['regions']);
        self::assertNotNull($summary['topLift']);
        self::assertSame('Rowing barre', $summary['topLift']['exercise']);
    }

    public function testRegionsGroupWorkingSetsByAnatomicalRegion(): void
    {
        // 4 séries jambes (2 zones ciblées → 8 attribuées),
        // 2 séries bras (1 zone → 2 attribuées). Total attribué = 10.
        $squat = $this->logged('Squat', [TargetArea::QUADRICEPS, TargetArea::GLUTES], [
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
        ]);
        $curl = $this->logged('Curl', [TargetArea::BICEPS], [
            [SetType::NORMAL, 12, 20.0],
            [SetType::NORMAL, 12, 20.0],
        ]);

        $regions = $this->metrics->summary($this->scheduled([$squat, $curl]))['regions'];

        self::assertCount(2, $regions);
        self::assertSame(TargetRegion::LOWER_BODY, $regions[0]['region']);
        self::assertSame(8, $regions[0]['sets']);
        self::assertSame(80.0, $regions[0]['percent']);
        self::assertSame(TargetRegion::UPPER_BODY, $regions[1]['region']);
        self::assertSame(20.0, $regions[1]['percent']);
    }

    public function testDurationUsesRealBoundsAndNeedsBoth(): void
    {
        $scheduled = $this->scheduled([$this->logged('Squat', [], [[SetType::NORMAL, 5, 100.0]])]);
        $scheduled->setStartedAt(new \DateTimeImmutable('2026-07-30 18:05:00'));

        // Séance commencée, pas encore finie : une durée « jusqu'à maintenant »
        // bougerait à chaque rafraîchissement.
        self::assertNull($this->metrics->summary($scheduled)['durationSeconds']);

        $scheduled->setEndedAt(new \DateTimeImmutable('2026-07-30 19:20:00'));
        self::assertSame(4500, $this->metrics->summary($scheduled)['durationSeconds']);

        // Horloge du téléphone rattrapée entre les deux écritures : on plafonne
        // à 0 plutôt que d'afficher une durée négative.
        $scheduled->setEndedAt(new \DateTimeImmutable('2026-07-30 18:04:00'));
        self::assertSame(0, $this->metrics->summary($scheduled)['durationSeconds']);
    }

    public function testLoggedAtFallsBackOnTheLastCompletedSet(): void
    {
        $logged = $this->logged('Squat', [], [
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
        ]);
        $sets = $logged->getLoggedSets();
        $sets[0]->setCompletedAt(new \DateTimeImmutable('2026-07-30 18:10:00'));
        $sets[1]->setCompletedAt(new \DateTimeImmutable('2026-07-30 18:14:00'));

        // Saisie a posteriori : pas de bornes, les séries portent seules la date.
        $scheduled = $this->scheduled([$logged]);
        self::assertEquals(
            new \DateTimeImmutable('2026-07-30 18:14:00'),
            $this->metrics->summary($scheduled)['loggedAt'],
        );

        // La fin d'exécution, quand elle existe, prime.
        $scheduled->setEndedAt(new \DateTimeImmutable('2026-07-30 18:30:00'));
        self::assertEquals(
            new \DateTimeImmutable('2026-07-30 18:30:00'),
            $this->metrics->summary($scheduled)['loggedAt'],
        );
    }

    public function testFlatShapeMatchesTheWorkoutSummaryKeys(): void
    {
        // C'est ce qui permet au bandeau de KPI de `_workout_read` de se rendre
        // tel quel sur du réalisé : les clés du prescrit doivent toutes exister.
        $summary = $this->metrics->summary($this->scheduled([
            $this->logged('Squat', [TargetArea::QUADRICEPS], [[SetType::NORMAL, 5, 100.0]]),
        ]));

        foreach (['tonnageKg', 'workingSets', 'exerciseCount', 'blockCount', 'supersets', 'circuits', 'averageRpe', 'topLift', 'regions'] as $key) {
            self::assertArrayHasKey($key, $summary);
        }

        // Le réalisé est plat : ni blocs ni liaisons, qui n'existent que dans
        // l'intention.
        self::assertSame(0, $summary['blockCount']);
        self::assertSame(0, $summary['supersets']);
        self::assertSame(0, $summary['circuits']);
    }

    /**
     * @param list<LoggedExercise> $exercises
     */
    private function scheduled(array $exercises = []): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setScheduledDate(new \DateTimeImmutable('2026-07-30'));

        foreach ($exercises as $i => $exercise) {
            $exercise->setPosition($i);
            $scheduled->addLoggedExercise($exercise);
        }

        return $scheduled;
    }

    /**
     * @param list<TargetArea>                                              $areas
     * @param list<array{SetType, int|null, float|null, 3?: int, 4?: int}> $sets type, reps, charge, RPE, durée
     */
    private function logged(string $name, array $areas, array $sets): LoggedExercise
    {
        $exercise = (new Exercise())
            ->setName($name)
            ->setActivity(ActivityType::GYM)
            ->setTargetAreas($areas);

        $logged = (new LoggedExercise())
            ->setExercise($exercise)
            ->setExerciseName($name)
            ->setPosition(0);

        foreach ($sets as $i => [$type, $reps, $weight]) {
            $set = (new LoggedSet())
                ->setPosition($i)
                ->setSetType($type)
                ->setReps($reps)
                ->setWeightKg($weight);

            if (isset($sets[$i][3])) {
                $set->setRpe($sets[$i][3]);
            }

            if (isset($sets[$i][4])) {
                $set->setDurationSeconds($sets[$i][4]);
            }

            $logged->addLoggedSet($set);
        }

        return $logged;
    }
}
