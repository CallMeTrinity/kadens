<?php

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PrescribedExercise;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Enum\TargetArea;
use App\Service\WorkoutMetrics;
use PHPUnit\Framework\TestCase;

final class WorkoutMetricsTest extends TestCase
{
    private WorkoutMetrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = new WorkoutMetrics();
    }

    public function testDistinctActivitiesInOrderOfAppearance(): void
    {
        $workout = $this->workout([
            $this->block(BlockRole::MAIN, 1, [
                $this->prescribed(ActivityType::GYM, PrescriptionType::SETS_REPS, [TargetArea::CHEST]),
                $this->prescribed(ActivityType::RUNNING, PrescriptionType::DISTANCE_PACE, []),
                $this->prescribed(ActivityType::GYM, PrescriptionType::SETS_REPS, [TargetArea::BACK]),
            ]),
        ]);

        self::assertSame(
            [ActivityType::GYM, ActivityType::RUNNING],
            $this->metrics->distinctActivities($workout),
        );
        self::assertSame(3, $this->metrics->exerciseCount($workout));
    }

    public function testGymVolumeAttributesSetsPerAreaWithRoundsAndTonnage(): void
    {
        // Bloc à 2 tours : un exercice 3×10 @ 50 kg ciblant pectoraux + triceps.
        $pe = $this->prescribed(ActivityType::GYM, PrescriptionType::SETS_REPS, [TargetArea::CHEST, TargetArea::TRICEPS]);
        $pe->setSets(3)->setReps(10)->setWeightKg(50.0);

        $workout = $this->workout([$this->block(BlockRole::MAIN, 2, [$pe])]);
        $vol = $this->metrics->volume($workout);

        // 3 séries × 2 tours = 6 séries, attribuées à chaque groupe ciblé.
        self::assertSame(6, $vol['gym']['setsByArea']['chest']);
        self::assertSame(6, $vol['gym']['setsByArea']['triceps']);
        self::assertSame(6, $vol['gym']['totalSets']);
        // Tonnage = 6 séries × 10 reps × 50 kg.
        self::assertSame(3000.0, $vol['gym']['tonnageKg']);
    }

    public function testEnduranceVolumeSumsDistanceAndDurationByActivity(): void
    {
        $run = $this->prescribed(ActivityType::RUNNING, PrescriptionType::DISTANCE_PACE, []);
        $run->setDistanceMeters(5000);
        $bike = $this->prescribed(ActivityType::CYCLING, PrescriptionType::DISTANCE_PACE, []);
        $bike->setDistanceMeters(20000);

        $workout = $this->workout([$this->block(BlockRole::MAIN, 1, [$run, $bike])]);
        $vol = $this->metrics->volume($workout);

        self::assertSame(5000, $vol['running']['meters']);
        self::assertSame(20000, $vol['cycling']['meters']);
        self::assertSame(0, $vol['swimming']['meters']);
        // Pas de salle : aucun groupe musculaire.
        self::assertSame([], $vol['gym']['setsByArea']);
        self::assertSame(0.0, $vol['gym']['tonnageKg']);
    }

    /**
     * @param list<Block> $blocks
     */
    private function workout(array $blocks): Workout
    {
        $workout = (new Workout())->setTitle('Séance')->setSlug('seance-'.uniqid());
        foreach ($blocks as $block) {
            $workout->addBlock($block);
        }

        return $workout;
    }

    /**
     * @param list<PrescribedExercise> $exercises
     */
    private function block(BlockRole $role, int $rounds, array $exercises): Block
    {
        $block = (new Block())->setRole($role)->setRounds($rounds)->setPosition(0);
        foreach ($exercises as $i => $exercise) {
            $exercise->setPosition($i);
            $block->addPrescribedExercise($exercise);
        }

        return $block;
    }

    /**
     * @param list<TargetArea> $areas
     */
    private function prescribed(ActivityType $activity, PrescriptionType $type, array $areas): PrescribedExercise
    {
        $exercise = (new Exercise())->setName('Ex')->setActivity($activity)->setTargetAreas($areas);

        return (new PrescribedExercise())->setPrescriptionType($type)->setExercise($exercise);
    }
}
