<?php

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Service\LogMetrics;
use App\Service\ProgressionAggregator;
use App\Service\RegionBreakdown;
use App\Service\SupersetGrouper;
use App\Service\UnitFormatter;
use App\Service\WorkoutEstimator;
use App\Service\WorkoutMetrics;
use PHPUnit\Framework\TestCase;

final class ProgressionAggregatorTest extends TestCase
{
    private ProgressionAggregator $aggregator;

    protected function setUp(): void
    {
        $regions = new RegionBreakdown();
        $this->aggregator = new ProgressionAggregator(
            new UnitFormatter(),
            new WorkoutMetrics(new WorkoutEstimator(), new SupersetGrouper(), $regions),
            new LogMetrics($regions),
        );
    }

    public function testWeightRampAcrossWeeksIsTraced(): void
    {
        // Même exercice (id partagé) posé 3 semaines à charge croissante.
        $squat = $this->exercise(1, 'Squat barre', ActivityType::GYM);
        $template = $this->template(3, [
            $this->item(1, 1, $this->workoutWith($squat, PrescriptionType::SETS_REPS, ['sets' => 5, 'reps' => 5, 'weightKg' => 60.0])),
            $this->item(2, 1, $this->workoutWith($squat, PrescriptionType::SETS_REPS, ['sets' => 5, 'reps' => 5, 'weightKg' => 65.0])),
            $this->item(3, 1, $this->workoutWith($squat, PrescriptionType::SETS_REPS, ['sets' => 5, 'reps' => 5, 'weightKg' => 70.0])),
        ]);

        $trajectories = $this->aggregator->exerciseTrajectories($template);

        self::assertCount(1, $trajectories);
        $traj = $trajectories[0];
        self::assertSame('weight', $traj['metric']);
        self::assertFalse($traj['lowerIsBetter']);
        self::assertSame(3, $traj['weeksPresent']);
        self::assertSame('up', $traj['direction']);
        self::assertSame([60.0, 65.0, 70.0], array_map(static fn (array $p): ?float => $p['value'], $traj['points']));
        // Barres échelonnées entre min et max : plancher 15 % pour la 1re, 100 % pour la 3e.
        self::assertSame(15, $traj['points'][0]['heightPct']);
        self::assertSame(100, $traj['points'][2]['heightPct']);
    }

    public function testPaceTrajectoryIsLowerIsBetterAndImprovesWhenFaster(): void
    {
        // Allure qui descend de 300 s/km à 270 s/km = progression (plus rapide).
        $run = $this->exercise(2, 'Sortie tempo', ActivityType::RUNNING);
        $template = $this->template(2, [
            $this->item(1, 3, $this->workoutWith($run, PrescriptionType::DISTANCE_PACE, ['distanceMeters' => 5000, 'paceSecondsPerKm' => 300])),
            $this->item(2, 3, $this->workoutWith($run, PrescriptionType::DISTANCE_PACE, ['distanceMeters' => 5000, 'paceSecondsPerKm' => 270])),
        ]);

        $traj = $this->aggregator->exerciseTrajectories($template)[0];

        self::assertSame('pace', $traj['metric']);
        self::assertTrue($traj['lowerIsBetter']);
        // Plus rapide = progression, malgré une valeur numérique qui baisse.
        self::assertSame('up', $traj['direction']);
        // Barre inversée : la semaine la plus rapide (min) est la plus haute.
        self::assertSame(100, $traj['points'][1]['heightPct']);
    }

    public function testExercisePresentOnlyOnceIsNotATrajectory(): void
    {
        $squat = $this->exercise(1, 'Squat barre', ActivityType::GYM);
        $template = $this->template(3, [
            $this->item(1, 1, $this->workoutWith($squat, PrescriptionType::SETS_REPS, ['sets' => 5, 'reps' => 5, 'weightKg' => 60.0])),
        ]);

        self::assertSame([], $this->aggregator->exerciseTrajectories($template));
    }

    public function testWeeklyVolumeKeepsOnlyNonEmptySeriesAndOnePointPerWeek(): void
    {
        $squat = $this->exercise(1, 'Squat barre', ActivityType::GYM);
        $w1 = $this->workoutWith($squat, PrescriptionType::SETS_REPS, ['sets' => 5, 'reps' => 5, 'weightKg' => 60.0]);
        $w1->setEstimatedDurationMinutes(45);
        $w2 = $this->workoutWith($squat, PrescriptionType::SETS_REPS, ['sets' => 5, 'reps' => 5, 'weightKg' => 70.0]);
        $w2->setEstimatedDurationMinutes(50);

        $template = $this->template(2, [
            $this->item(1, 1, $w1),
            $this->item(2, 1, $w2),
        ]);

        $volume = $this->aggregator->weeklyVolume($template);
        $keys = array_map(static fn (array $s): string => $s['key'], $volume);

        // Séries présentes : temps, tonnage, séries. Pas de distances (aucune course).
        self::assertContains('minutes', $keys);
        self::assertContains('tonnage', $keys);
        self::assertContains('sets', $keys);
        self::assertNotContains('running', $keys);

        foreach ($volume as $series) {
            self::assertCount(2, $series['points']); // une valeur par semaine
        }
    }

    // ---- KL-49 : le réalisé superposé à la rampe ----------------------------

    /**
     * Un plan jamais posé au calendrier n'a pas de réalisé du tout — pas un
     * réalisé à zéro, qui réserverait à l'écran une place vide.
     */
    public function testNeverInstantiatedPlanHasNoRealizedAtAll(): void
    {
        $template = $this->gymPlan();

        self::assertNull($this->aggregator->realizedRun($template, []));

        foreach ($this->aggregator->weeklyVolume($template) as $series) {
            self::assertFalse($series['hasRealized']);
            foreach ($series['points'] as $point) {
                self::assertNull($point['realValue']);
                self::assertSame(0, $point['realHeightPct']);
            }
        }
    }

    /** Le réalisé se replie sur les semaines de la trame, tonnage et séries compris. */
    public function testRealizedIsFoldedOntoThePlanWeeks(): void
    {
        $template = $this->gymPlan();

        // Semaine 1 : 3 × 5 @ 100 kg (1500 kg), semaine 2 : 2 × 5 @ 110 kg (1100 kg).
        $realized = $this->aggregator->realizedRun($template, [
            $this->scheduled($template, 1, '2026-03-04', ScheduledStatus::DONE, [
                [SetType::WARMUP, 10, 40.0],
                [SetType::NORMAL, 5, 100.0],
                [SetType::NORMAL, 5, 100.0],
                [SetType::NORMAL, 5, 100.0],
            ]),
            $this->scheduled($template, 2, '2026-03-11', ScheduledStatus::DONE, [
                [SetType::NORMAL, 5, 110.0],
                [SetType::NORMAL, 5, 110.0],
            ]),
        ]);

        self::assertNotNull($realized);
        // L'échauffement ne compte ni en tonnage ni en séries, ici comme ailleurs.
        self::assertSame(1500.0, $realized['weeks'][1]['tonnage']);
        self::assertSame(3, $realized['weeks'][1]['sets']);
        self::assertSame(1100.0, $realized['weeks'][2]['tonnage']);
        self::assertSame(2, $realized['weeks'][2]['sets']);

        $tonnage = $this->seriesByKey($this->aggregator->weeklyVolume($template, $realized['weeks']), 'tonnage');
        self::assertTrue($tonnage['hasRealized']);
        self::assertSame([1500.0, 1100.0], array_map(static fn (array $p): ?float => $p['realValue'], $tonnage['points']));
        // Une seule échelle : le maximum couvre le prévu ET le réalisé, sinon deux
        // barres de même hauteur vaudraient deux valeurs différentes. Le sommet
        // est ici le prévu de la semaine 2 (3 × 5 × 105 = 1575 kg), et le réalisé
        // de la semaine 1 (1500 kg) se mesure dessus.
        self::assertSame(1575.0, $tonnage['max']);
        self::assertSame(100, $tonnage['points'][1]['heightPct']);
        self::assertSame(95, $tonnage['points'][0]['realHeightPct']);
    }

    /** « 11 séances tenues sur 14 » : le décompte porte sur toute l'instanciation. */
    public function testAdherenceCountsTheWholeRun(): void
    {
        $template = $this->gymPlan();

        $adherence = $this->aggregator->realizedRun($template, [
            $this->scheduled($template, 1, '2026-03-04', ScheduledStatus::DONE, [[SetType::NORMAL, 5, 100.0]]),
            $this->scheduled($template, 1, '2026-03-06', ScheduledStatus::MISSED, []),
            $this->scheduled($template, 2, '2026-03-11', ScheduledStatus::PLANNED, []),
            $this->scheduled($template, 2, '2026-03-13', ScheduledStatus::DONE, []),
        ])['adherence'];

        self::assertSame(4, $adherence['total']);
        self::assertSame(2, $adherence['done']);
        self::assertSame(1, $adherence['missed']);
        self::assertSame(1, $adherence['planned']);
        self::assertSame(50, $adherence['percent']);
        // Une séance cochée « faite » sans détail série par série n'est pas loguée.
        self::assertSame(1, $adherence['logged']);
    }

    /**
     * La case d'origine fait foi, pas la date : déplacer une séance ne la fait
     * pas changer de semaine dans le plan qui l'a posée.
     */
    public function testWeekComesFromThePlanItemNotTheDate(): void
    {
        $template = $this->gymPlan();

        // Case de la semaine 1, mais reportée à une date de la semaine 2.
        $realized = $this->aggregator->realizedRun($template, [
            $this->scheduled($template, 1, '2026-03-12', ScheduledStatus::DONE, [[SetType::NORMAL, 5, 100.0]]),
        ]);

        self::assertSame(1, $realized['weeks'][1]['sets']);
        self::assertSame(0, $realized['weeks'][2]['sets']);
    }

    /**
     * Rien n'est superposé sur ce qui ne se logue pas : la course, le vélo et la
     * natation n'ont pas de deuxième barre — et surtout pas une barre à zéro, qui
     * se lirait « rien fait ».
     */
    public function testDistanceSeriesNeverCarriesRealized(): void
    {
        $run = $this->exercise(2, 'Sortie tempo', ActivityType::RUNNING);
        $template = $this->template(2, [
            $this->item(1, 3, $this->workoutWith($run, PrescriptionType::DISTANCE_PACE, ['distanceMeters' => 8000])),
            $this->item(2, 3, $this->workoutWith($run, PrescriptionType::DISTANCE_PACE, ['distanceMeters' => 10000])),
        ]);

        $realized = $this->aggregator->realizedRun($template, [
            $this->scheduled($template, 1, '2026-03-04', ScheduledStatus::DONE, []),
        ]);

        $running = $this->seriesByKey($this->aggregator->weeklyVolume($template, $realized['weeks']), 'running');

        self::assertFalse($running['hasRealized']);
        foreach ($running['points'] as $point) {
            self::assertNull($point['realValue']);
            self::assertSame(0, $point['realHeightPct']);
        }
    }

    /** La trajectoire d'un exercice porte la charge réellement soulevée, semaine par semaine. */
    public function testExerciseTrajectoryCarriesTheLoggedTopSet(): void
    {
        $squat = $this->exercise(1, 'Squat barre', ActivityType::GYM);
        $template = $this->gymPlan($squat);

        $realized = $this->aggregator->realizedRun($template, [
            $this->scheduled($template, 1, '2026-03-04', ScheduledStatus::DONE, [
                [SetType::WARMUP, 10, 130.0], // un échauffement lourd n'est pas un top set
                [SetType::NORMAL, 5, 102.5],
            ], $squat),
            $this->scheduled($template, 2, '2026-03-11', ScheduledStatus::DONE, [
                [SetType::NORMAL, 5, 107.5],
            ], $squat),
        ]);

        $traj = $this->aggregator->exerciseTrajectories($template, $realized['exercises'])[0];

        self::assertTrue($traj['hasRealized']);
        self::assertSame([102.5, 107.5], array_map(static fn (array $p): ?float => $p['realValue'], $traj['points']));
        self::assertSame('107,5 kg', $traj['points'][1]['realLabel']);
    }

    /** Un exercice sauté n'apporte rien, même s'il porte des séries abandonnées. */
    public function testSkippedExerciseCountsForNothing(): void
    {
        $squat = $this->exercise(1, 'Squat barre', ActivityType::GYM);
        $template = $this->gymPlan($squat);

        $realized = $this->aggregator->realizedRun($template, [
            $this->scheduled($template, 1, '2026-03-04', ScheduledStatus::DONE, [
                [SetType::NORMAL, 5, 100.0],
            ], $squat, skipped: true),
        ]);

        self::assertSame(0, $realized['weeks'][1]['sets']);
        self::assertSame([], $realized['exercises']);
    }

    // ---- Fixtures ----------------------------------------------------------

    /** Une trame de deux semaines, une case par semaine, même exercice de force. */
    private function gymPlan(?Exercise $exercise = null): PlanTemplate
    {
        $exercise ??= $this->exercise(1, 'Squat barre', ActivityType::GYM);

        return $this->template(2, [
            $this->item(1, 3, $this->workoutWith($exercise, PrescriptionType::SETS_REPS, ['sets' => 3, 'reps' => 5, 'weightKg' => 100.0])),
            $this->item(2, 3, $this->workoutWith($exercise, PrescriptionType::SETS_REPS, ['sets' => 3, 'reps' => 5, 'weightKg' => 105.0])),
        ]);
    }

    /**
     * Une séance datée issue d'une case du plan, avec son réalisé. `$sets` vide =
     * une séance sans détail série par série (statut seul).
     *
     * @param list<array{0: SetType, 1: int|null, 2: float|null}> $sets
     */
    private function scheduled(
        PlanTemplate $template,
        int $week,
        string $date,
        ScheduledStatus $status,
        array $sets,
        ?Exercise $exercise = null,
        bool $skipped = false,
    ): ScheduledWorkout {
        $item = null;
        foreach ($template->getPlanItems() as $candidate) {
            if ($candidate->getWeekNumber() === $week) {
                $item = $candidate;
            }
        }
        self::assertNotNull($item);

        $scheduled = (new ScheduledWorkout())
            ->setWorkout($item->getWorkout())
            ->setSourcePlanTemplate($template)
            ->setSourcePlanItem($item)
            ->setPlanAnchorDate(new \DateTimeImmutable('2026-03-02'))
            ->setScheduledDate(new \DateTimeImmutable($date))
            ->setStatus($status);

        if ([] === $sets) {
            return $scheduled;
        }

        $exercise ??= $this->exercise(1, 'Squat barre', ActivityType::GYM);
        $logged = (new LoggedExercise())
            ->setExercise($exercise)
            ->setExerciseName((string) $exercise->getName())
            ->setPosition(0)
            ->setSkipped($skipped);

        $position = 0;
        foreach ($sets as $set) {
            $logged->addLoggedSet(
                (new LoggedSet())
                    ->setPosition($position++)
                    ->setSetType($set[0])
                    ->setReps($set[1])
                    ->setWeightKg($set[2])
            );
        }

        return $scheduled->addLoggedExercise($logged);
    }

    /**
     * @param list<array{key: string, points: list<array<string, mixed>>, hasRealized: bool, max: float, label: string, modifier: string}> $series
     *
     * @return array{key: string, points: list<array<string, mixed>>, hasRealized: bool, max: float, label: string, modifier: string}
     */
    private function seriesByKey(array $series, string $key): array
    {
        foreach ($series as $candidate) {
            if ($candidate['key'] === $key) {
                return $candidate;
            }
        }

        self::fail(sprintf('Série « %s » absente du volume hebdomadaire.', $key));
    }

    private function exercise(int $id, string $name, ActivityType $activity): Exercise
    {
        $exercise = (new Exercise())->setName($name)->setActivity($activity);
        // Id partagé entre semaines : c'est ce qui relie les occurrences d'un même exo.
        (new \ReflectionProperty(Exercise::class, 'id'))->setValue($exercise, $id);

        return $exercise;
    }

    /**
     * @param array<string, int|float> $params
     */
    private function workoutWith(Exercise $exercise, PrescriptionType $type, array $params): Workout
    {
        $pe = (new PrescribedExercise())->setPrescriptionType($type)->setExercise($exercise)->setPosition(0);
        foreach ($params as $setter => $value) {
            $pe->{'set'.ucfirst($setter)}($value);
        }

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $block->addPrescribedExercise($pe);

        return (new Workout())->setTitle('Séance')->setSlug('seance-'.uniqid())->addBlock($block);
    }

    private function item(int $week, int $day, Workout $workout): PlanItem
    {
        return (new PlanItem())->setWeekNumber($week)->setDayOfWeek($day)->setWorkout($workout);
    }

    /**
     * @param list<PlanItem> $items
     */
    private function template(int $weeks, array $items): PlanTemplate
    {
        $template = (new PlanTemplate())->setTitle('Plan')->setDurationWeeks($weeks);
        foreach ($items as $item) {
            $template->addPlanItem($item);
        }

        return $template;
    }
}
