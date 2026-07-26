<?php

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Service\ProgressionAggregator;
use App\Service\UnitFormatter;
use App\Service\WorkoutMetrics;
use PHPUnit\Framework\TestCase;

final class ProgressionAggregatorTest extends TestCase
{
    private ProgressionAggregator $aggregator;

    protected function setUp(): void
    {
        $this->aggregator = new ProgressionAggregator(new UnitFormatter(), new WorkoutMetrics());
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

    // ---- Fixtures ----------------------------------------------------------

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
