<?php

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\PrescribedSet;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Enum\SetType;
use App\Service\PlanFlattener;
use App\Service\SupersetGrouper;
use App\Service\UnitFormatter;
use App\Service\WorkoutEstimator;
use App\Service\WorkoutMetrics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlanFlattenerTest extends TestCase
{
    private PlanFlattener $flattener;

    protected function setUp(): void
    {
        $this->flattener = new PlanFlattener(new UnitFormatter(), new WorkoutMetrics(new WorkoutEstimator(), new SupersetGrouper()), new SupersetGrouper());
    }

    #[DataProvider('summaryCases')]
    public function testSummaryFormatting(PrescribedExercise $prescribed, string $expected): void
    {
        $exercise = (new Exercise())->setName('Test')->setActivity(ActivityType::GYM);
        $prescribed->setExercise($exercise)->setPosition(0);

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $block->addPrescribedExercise($prescribed);

        $workout = (new Workout())->setTitle('Séance')->setSlug('seance');
        $workout->addBlock($block);

        $flat = $this->flattener->flattenWorkout($workout);

        self::assertSame($expected, $flat['blocks'][0]['exercises'][0]['summary']);
    }

    public function testDetailedSetsSummaryGroupsConsecutiveIdenticalSets(): void
    {
        $exercise = (new Exercise())->setName('Développé couché')->setActivity(ActivityType::GYM);
        $prescribed = (new PrescribedExercise())
            ->setPrescriptionType(PrescriptionType::SETS_REPS)
            ->setExercise($exercise)
            ->setPosition(0);
        // Échauffement + 2 séries de travail identiques (regroupées) + drop set.
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(0)->setSetType(SetType::WARMUP)->setReps(10)->setWeightKg(40.0));
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(1)->setSetType(SetType::NORMAL)->setReps(8)->setWeightKg(100.0));
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(2)->setSetType(SetType::NORMAL)->setReps(8)->setWeightKg(100.0));
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(3)->setSetType(SetType::DROP_SET)->setReps(6)->setWeightKg(80.0));

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $block->addPrescribedExercise($prescribed);
        $workout = (new Workout())->setTitle('Séance')->setSlug('seance');
        $workout->addBlock($block);

        $flat = $this->flattener->flattenWorkout($workout)['blocks'][0]['exercises'][0];

        self::assertSame(
            'Échauf 10 reps @ 40 kg · 2× 8 reps @ 100 kg · Drop set 6 reps @ 80 kg',
            $flat['summary'],
        );
        // Structure : 3 groupes (les 2 séries de travail identiques fusionnées).
        self::assertNotNull($flat['sets']);
        self::assertCount(3, $flat['sets']);
        self::assertSame(2, $flat['sets'][1]['count']);
        self::assertNull($flat['sets'][1]['typeLabel']); // NORMAL : pas de libellé
        self::assertSame('Drop set', $flat['sets'][2]['typeLabel']);
    }

    /**
     * La vue déroulée (`setLines`) rend une entrée par série dans les deux modes :
     * un compteur scalaire « 3 × 15 @ 130 kg » vaut trois lignes identiques,
     * exactement comme trois lignes saisies à la main. C'est ce qui fait qu'une
     * séance se lit de la même façon quel que soit le mode de saisie.
     */
    public function testScalarSetsAreUnrolledOneLinePerSet(): void
    {
        $exercise = (new Exercise())->setName('Squat')->setActivity(ActivityType::GYM);
        $prescribed = (new PrescribedExercise())
            ->setPrescriptionType(PrescriptionType::SETS_REPS)
            ->setExercise($exercise)
            ->setPosition(0)
            ->setSets(3)->setReps(15)->setWeightKg(130.0);

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $block->addPrescribedExercise($prescribed);
        $workout = (new Workout())->setTitle('Séance')->setSlug('seance-scalaire');
        $workout->addBlock($block);

        $flat = $this->flattener->flattenWorkout($workout)['blocks'][0]['exercises'][0];

        // La vue condensée reste réservée au mode détaillé (aperçu, résumé).
        self::assertNull($flat['sets']);
        self::assertSame('3 × 15 @ 130 kg', $flat['summary']);

        self::assertCount(3, $flat['setLines']);
        self::assertSame([1, 2, 3], array_column($flat['setLines'], 'index'));
        foreach ($flat['setLines'] as $line) {
            self::assertSame(SetType::NORMAL, $line['type']);
            self::assertSame('15 reps', $line['effort']);
            self::assertSame(130.0, $line['weightKg']);
        }
    }

    /**
     * Le déroulé suit la saisie détaillée série par série, échauffement compris,
     * sans fusionner les lignes identiques comme le fait la vue condensée.
     */
    public function testDetailedSetsAreUnrolledWithoutMerging(): void
    {
        $exercise = (new Exercise())->setName('Développé couché')->setActivity(ActivityType::GYM);
        $prescribed = (new PrescribedExercise())
            ->setPrescriptionType(PrescriptionType::SETS_REPS)
            ->setExercise($exercise)
            ->setPosition(0);
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(0)->setSetType(SetType::WARMUP)->setReps(10)->setWeightKg(40.0));
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(1)->setSetType(SetType::NORMAL)->setReps(8)->setWeightKg(100.0));
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(2)->setSetType(SetType::NORMAL)->setReps(8)->setWeightKg(100.0));

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $block->addPrescribedExercise($prescribed);
        $workout = (new Workout())->setTitle('Séance')->setSlug('seance-detaillee');
        $workout->addBlock($block);

        $flat = $this->flattener->flattenWorkout($workout)['blocks'][0]['exercises'][0];

        // Deux groupes condensés, mais trois lignes déroulées.
        self::assertCount(2, $flat['sets']);
        self::assertCount(3, $flat['setLines']);
        self::assertSame([SetType::WARMUP, SetType::NORMAL, SetType::NORMAL], array_column($flat['setLines'], 'type'));
        self::assertSame('Échauf', $flat['setLines'][0]['typeLabel']);
        self::assertNull($flat['setLines'][1]['typeLabel']);
    }

    /**
     * Le `sets` d'un DISTANCE_PACE compte des intervalles, pas des séries : il ne
     * se déroule pas en tableau (« 8 × 400 m » reste une ligne de résumé).
     */
    public function testIntervalsAreNotUnrolled(): void
    {
        $exercise = (new Exercise())->setName('Fractionné')->setActivity(ActivityType::RUNNING);
        $prescribed = (new PrescribedExercise())
            ->setPrescriptionType(PrescriptionType::DISTANCE_PACE)
            ->setExercise($exercise)
            ->setPosition(0)
            ->setSets(8)
            ->setDistanceMeters(400);

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $block->addPrescribedExercise($prescribed);
        $workout = (new Workout())->setTitle('Piste')->setSlug('piste');
        $workout->addBlock($block);

        $flat = $this->flattener->flattenWorkout($workout)['blocks'][0]['exercises'][0];

        self::assertNull($flat['setLines']);
        self::assertSame('8 × 400 m', $flat['summary']);
    }

    /**
     * Un bloc est livré à la fois à plat (`exercises`, ordre de lecture) et
     * découpé (`segments`). Les deux décrivent le même contenu dans le même
     * ordre : c'est ce qui autorise l'export à ignorer les liaisons pendant que
     * la vue les montre.
     */
    public function testBlockExposesFlatExercisesAndSupersetSegments(): void
    {
        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        foreach ([1, 1, null] as $position => $group) {
            $exercise = (new Exercise())->setName('Ex'.$position)->setActivity(ActivityType::GYM);
            $block->addPrescribedExercise(
                (new PrescribedExercise())
                    ->setPrescriptionType(PrescriptionType::SETS_REPS)
                    ->setExercise($exercise)
                    ->setPosition($position)
                    ->setSupersetGroup($group)
                    ->setSets(3)
                    ->setReps(10)
            );
        }

        $workout = (new Workout())->setTitle('Séance')->setSlug('seance');
        $workout->addBlock($block);

        $flatBlock = $this->flattener->flattenWorkout($workout)['blocks'][0];

        self::assertCount(3, $flatBlock['exercises']);
        self::assertSame(['A1', 'A2', null], array_column($flatBlock['exercises'], 'groupLabel'));

        self::assertCount(2, $flatBlock['segments']);
        self::assertSame('superset', $flatBlock['segments'][0]['kind']);
        self::assertCount(2, $flatBlock['segments'][0]['exercises']);
        self::assertSame('single', $flatBlock['segments'][1]['kind']);
        self::assertNull($flatBlock['segments'][1]['label']);
    }

    /**
     * Le regroupement condense l'affichage, il ne doit pas faire perdre le rang
     * réel des séries : le tableau de lecture affiche « 02 — 03 » sur un groupe
     * de deux, et dérive le « % du max » de la charge brute conservée.
     */
    public function testDetailedSetGroupsKeepSetNumberingAndRawWeight(): void
    {
        $exercise = (new Exercise())->setName('Soulevé de terre')->setActivity(ActivityType::GYM);
        $prescribed = (new PrescribedExercise())
            ->setPrescriptionType(PrescriptionType::SETS_REPS)
            ->setExercise($exercise)
            ->setPosition(0);
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(0)->setSetType(SetType::WARMUP)->setReps(6)->setWeightKg(70.0));
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(1)->setSetType(SetType::NORMAL)->setReps(6)->setWeightKg(140.0));
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(2)->setSetType(SetType::NORMAL)->setReps(6)->setWeightKg(140.0));
        $prescribed->addDetailedSet((new PrescribedSet())->setPosition(3)->setSetType(SetType::TO_FAILURE)->setReps(6)->setWeightKg(140.0));

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $block->addPrescribedExercise($prescribed);
        $workout = (new Workout())->setTitle('Séance')->setSlug('seance-num');
        $workout->addBlock($block);

        $flat = $this->flattener->flattenWorkout($workout)['blocks'][0]['exercises'][0];
        $groups = $flat['sets'];

        self::assertCount(3, $groups);
        // Groupe isolé : rang unique.
        self::assertSame(1, $groups[0]['firstIndex']);
        self::assertSame(1, $groups[0]['lastIndex']);
        // Groupe fusionné : la plage couvre les deux séries d'origine.
        self::assertSame(2, $groups[1]['firstIndex']);
        self::assertSame(3, $groups[1]['lastIndex']);
        // Le groupe suivant reprend la numérotation là où elle en était.
        self::assertSame(4, $groups[2]['firstIndex']);

        self::assertSame(70.0, $groups[0]['weightKg']);
        self::assertSame(140.0, $groups[1]['weightKg']);
        // Référence du pourcentage : la charge la plus lourde de l'exercice.
        self::assertSame(140.0, $flat['topWeightKg']);
    }

    /**
     * L'allure s'affiche dans l'unité naturelle de l'activité de l'exercice :
     * min/km (course), km/h (vélo), min/100m (natation).
     */
    #[DataProvider('paceUnitCases')]
    public function testPaceUnitFollowsActivity(ActivityType $activity, int $distanceMeters, int $paceSecondsPerKm, string $expected): void
    {
        $exercise = (new Exercise())->setName('Test')->setActivity($activity);
        $prescribed = (new PrescribedExercise())
            ->setPrescriptionType(PrescriptionType::DISTANCE_PACE)
            ->setDistanceMeters($distanceMeters)
            ->setPaceSecondsPerKm($paceSecondsPerKm)
            ->setExercise($exercise)
            ->setPosition(0);

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $block->addPrescribedExercise($prescribed);

        $workout = (new Workout())->setTitle('Séance')->setSlug('seance');
        $workout->addBlock($block);

        $flat = $this->flattener->flattenWorkout($workout);

        self::assertSame($expected, $flat['blocks'][0]['exercises'][0]['summary']);
    }

    public static function paceUnitCases(): \Generator
    {
        yield 'course en min/km' => [ActivityType::RUNNING, 5000, 300, '5 km @ 5:00/km'];
        yield 'vélo en km/h' => [ActivityType::CYCLING, 40000, 120, '40 km @ 30 km/h'];
        yield 'natation en min/100m' => [ActivityType::SWIMMING, 2000, 1050, '2 km @ 1:45/100m'];
    }

    public function testFlattenPlanTemplateProducesDenseGrid(): void
    {
        $workout = (new Workout())->setTitle('Sortie longue')->setSlug('sortie-longue');

        $item = (new PlanItem())->setWeekNumber(1)->setDayOfWeek(3)->setNotes('en Z2');
        $item->setWorkout($workout);

        $template = (new PlanTemplate())->setTitle('Plan 5k')->setDurationWeeks(2);
        $template->addPlanItem($item);

        $flat = $this->flattener->flattenPlanTemplate($template);

        // Grille dense : autant de semaines que déclarées, 7 jours chacune.
        self::assertCount(2, $flat['weeks']);
        self::assertCount(7, $flat['weeks'][0]['days']);
        self::assertSame(1, $flat['weeks'][0]['weekNumber']);
        self::assertSame(4, $flat['weeks'][0]['days'][3]['dayOfWeek']);

        // La séance est bien placée en semaine 1, jour 3 (index 2).
        $cell = $flat['weeks'][0]['days'][2];
        self::assertSame(3, $cell['dayOfWeek']);
        self::assertCount(1, $cell['items']);
        self::assertSame('Sortie longue', $cell['items'][0]['workout']['workout']->getTitle());
        self::assertSame('en Z2', $cell['items'][0]['item']->getNotes());

        // Les autres cases sont vides.
        self::assertSame([], $flat['weeks'][0]['days'][0]['items']);
        self::assertSame([], $flat['weeks'][1]['days'][2]['items']);
    }

    public static function summaryCases(): iterable
    {
        yield 'sets_reps avec charge' => [
            (new PrescribedExercise())
                ->setPrescriptionType(PrescriptionType::SETS_REPS)
                ->setSets(4)->setReps(8)->setWeightKg(60.0),
            '4 × 8 @ 60 kg',
        ];

        yield 'sets_time' => [
            (new PrescribedExercise())
                ->setPrescriptionType(PrescriptionType::SETS_TIME)
                ->setSets(3)->setDurationSeconds(45),
            '3 × 0:45',
        ];

        yield 'amrap avec cible' => [
            (new PrescribedExercise())
                ->setPrescriptionType(PrescriptionType::AMRAP)
                ->setDurationSeconds(720)->setTargetReps(100),
            'AMRAP 12:00 · cible 100 reps',
        ];

        yield 'for_time avec cap' => [
            (new PrescribedExercise())
                ->setPrescriptionType(PrescriptionType::FOR_TIME)
                ->setTargetReps(30)->setCapSeconds(300),
            '30 reps for time · cap 5:00',
        ];

        yield 'distance_pace en km' => [
            (new PrescribedExercise())
                ->setPrescriptionType(PrescriptionType::DISTANCE_PACE)
                ->setDistanceMeters(5000)->setPaceSecondsPerKm(300),
            '5 km @ 5:00/km',
        ];

        yield 'duration avec zone (valeur libre héritée)' => [
            (new PrescribedExercise())
                ->setPrescriptionType(PrescriptionType::DURATION)
                ->setDurationSeconds(2400)->setIntensityZone('Z2'),
            '40:00 · Z2',
        ];

        yield 'distance_pace intervalles + zone + dénivelé' => [
            (new PrescribedExercise())
                ->setPrescriptionType(PrescriptionType::DISTANCE_PACE)
                ->setSets(8)->setDistanceMeters(400)->setPaceSecondsPerKm(210)
                ->setIntensityZone('z5')->setElevationGainMeters(100),
            '8 × 400 m @ 3:30/km · Z5 VO2max · D+ 100 m',
        ];

        yield 'duration avec allure et zone Karvonen' => [
            (new PrescribedExercise())
                ->setPrescriptionType(PrescriptionType::DURATION)
                ->setDurationSeconds(2700)->setPaceSecondsPerKm(300)->setIntensityZone('z2'),
            '45:00 @ 5:00/km · Z2 Endurance',
        ];

        yield 'rpe transverse' => [
            (new PrescribedExercise())
                ->setPrescriptionType(PrescriptionType::SETS_REPS)
                ->setSets(5)->setReps(5)->setRpe(9),
            '5 × 5 · RPE 9',
        ];
    }
}
