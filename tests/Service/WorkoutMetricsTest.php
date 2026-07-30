<?php

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PrescribedExercise;
use App\Entity\PrescribedSet;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Enum\SetType;
use App\Enum\TargetArea;
use App\Enum\TargetRegion;
use App\Service\RegionBreakdown;
use App\Service\SupersetGrouper;
use App\Service\WorkoutEstimator;
use App\Service\WorkoutMetrics;
use PHPUnit\Framework\TestCase;

final class WorkoutMetricsTest extends TestCase
{
    private WorkoutMetrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = new WorkoutMetrics(new WorkoutEstimator(), new SupersetGrouper(), new RegionBreakdown());
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

    public function testDetailedSetsCountWorkingSetsAndTonnagePerRow(): void
    {
        // Séries hétérogènes : 1 échauffement 10 @ 40, 2 travail 8 @ 100, 1 drop 6 @ 80.
        // L'échauffement ne compte NI dans les séries de travail NI dans le tonnage.
        $pe = $this->prescribed(ActivityType::GYM, PrescriptionType::SETS_REPS, [TargetArea::CHEST]);
        $pe->setSets(4)->setReps(8)->setWeightKg(100.0); // scalaire ignoré une fois détaillé
        $pe->addDetailedSet((new PrescribedSet())->setPosition(0)->setSetType(SetType::WARMUP)->setReps(10)->setWeightKg(40.0));
        $pe->addDetailedSet((new PrescribedSet())->setPosition(1)->setSetType(SetType::NORMAL)->setReps(8)->setWeightKg(100.0));
        $pe->addDetailedSet((new PrescribedSet())->setPosition(2)->setSetType(SetType::NORMAL)->setReps(8)->setWeightKg(100.0));
        $pe->addDetailedSet((new PrescribedSet())->setPosition(3)->setSetType(SetType::DROP_SET)->setReps(6)->setWeightKg(80.0));

        $workout = $this->workout([$this->block(BlockRole::MAIN, 2, [$pe])]);
        $vol = $this->metrics->volume($workout);

        // 3 séries de travail (hors échauffement) × 2 tours = 6.
        self::assertSame(6, $vol['gym']['totalSets']);
        self::assertSame(6, $vol['gym']['setsByArea']['chest']);
        // Tonnage hors échauffement : (8×100 + 8×100 + 6×80) = 2080, × 2 tours = 4160.
        self::assertSame(4160.0, $vol['gym']['tonnageKg']);
    }

    public function testSummaryWeightsRpeByWorkingSetsAndKeepsTopLift(): void
    {
        // Squat 4×5 @ 120 kg RPE 9 / Curl 2×12 @ 20 kg RPE 6.
        // Une moyenne simple donnerait 7,5 ; pondérée par les séries : 8.
        $squat = $this->prescribed(ActivityType::GYM, PrescriptionType::SETS_REPS, [TargetArea::QUADRICEPS]);
        $squat->setSets(4)->setReps(5)->setWeightKg(120.0)->setRpe(9);
        $squat->getExercise()->setName('Squat');

        $curl = $this->prescribed(ActivityType::GYM, PrescriptionType::SETS_REPS, [TargetArea::BICEPS]);
        $curl->setSets(2)->setReps(12)->setWeightKg(20.0)->setRpe(6);
        $curl->getExercise()->setName('Curl');

        $workout = $this->workout([$this->block(BlockRole::MAIN, 1, [$squat, $curl])]);
        $summary = $this->metrics->summary($workout);

        self::assertSame(8.0, $summary['averageRpe']);
        self::assertSame(6, $summary['workingSets']);
        self::assertSame(2, $summary['exerciseCount']);
        // Deux exercices dans un même bloc ne sont PAS un superset : il faut
        // qu'ils soient liés. Sans liaison, la séance est à plat.
        self::assertSame(0, $summary['supersets']);
        self::assertSame(0, $summary['circuits']);
        self::assertNotNull($summary['topLift']);
        self::assertSame('Squat', $summary['topLift']['exercise']);
        self::assertSame(120.0, $summary['topLift']['weightKg']);
        // 4×5×120 + 2×12×20 = 2400 + 480.
        self::assertSame(2880.0, $summary['tonnageKg']);
    }

    public function testSummaryCountsLinkedGroupsNotBlocks(): void
    {
        // Un bloc de 5 exercices : 2 liés (superset), 3 liés (circuit), 1 isolé.
        // L'ancienne règle « un bloc de 2 exercices = un superset » aurait vu ici
        // un unique circuit ; la nouvelle compte les deux enchaînements réels.
        $exercises = [];
        foreach ([1, 1, null, 2, 2, 2] as $i => $group) {
            $pe = $this->prescribed(ActivityType::GYM, PrescriptionType::SETS_REPS, [TargetArea::CHEST]);
            $pe->setSets(3)->setReps(10)->setWeightKg(40.0)->setSupersetGroup($group);
            $exercises[] = $pe;
        }

        $summary = $this->metrics->summary($this->workout([$this->block(BlockRole::MAIN, 1, $exercises)]));

        self::assertSame(1, $summary['supersets']);
        self::assertSame(1, $summary['circuits']);
    }

    public function testSummaryGroupsVolumeByAnatomicalRegion(): void
    {
        // 4 séries jambes (2 zones ciblées → 8 séries attribuées),
        // 2 séries bras (1 zone → 2 attribuées). Total attribué = 10.
        $squat = $this->prescribed(ActivityType::GYM, PrescriptionType::SETS_REPS, [TargetArea::QUADRICEPS, TargetArea::GLUTES]);
        $squat->setSets(4)->setReps(5)->setWeightKg(100.0);

        $curl = $this->prescribed(ActivityType::GYM, PrescriptionType::SETS_REPS, [TargetArea::BICEPS]);
        $curl->setSets(2)->setReps(12)->setWeightKg(20.0);

        $workout = $this->workout([$this->block(BlockRole::MAIN, 1, [$squat, $curl])]);
        $regions = $this->metrics->summary($workout)['regions'];

        self::assertCount(2, $regions);
        // Trié par volume décroissant.
        self::assertSame(TargetRegion::LOWER_BODY, $regions[0]['region']);
        self::assertSame(8, $regions[0]['sets']);
        self::assertSame(80.0, $regions[0]['percent']);
        self::assertSame(TargetRegion::UPPER_BODY, $regions[1]['region']);
        self::assertSame(20.0, $regions[1]['percent']);
    }

    public function testSummaryIsEmptyButValidOnAnEmptyWorkout(): void
    {
        $summary = $this->metrics->summary($this->workout([]));

        self::assertSame(0.0, $summary['tonnageKg']);
        self::assertSame(0, $summary['workingSets']);
        self::assertSame(0, $summary['blockCount']);
        self::assertNull($summary['averageRpe']);
        self::assertNull($summary['topLift']);
        self::assertSame([], $summary['regions']);
    }

    public function testBlockBreakdownSumsToTheWorkoutTotals(): void
    {
        $bench = $this->prescribed(ActivityType::GYM, PrescriptionType::SETS_REPS, [TargetArea::CHEST]);
        $bench->setSets(3)->setReps(8)->setWeightKg(80.0)->setRestSeconds(120);

        $row = $this->prescribed(ActivityType::GYM, PrescriptionType::SETS_REPS, [TargetArea::BACK]);
        $row->setSets(3)->setReps(10)->setWeightKg(60.0)->setRestSeconds(90);

        $workout = $this->workout([
            $this->block(BlockRole::WARMUP, 1, [$bench]),
            $this->block(BlockRole::MAIN, 2, [$row]),
        ]);

        $blocks = $this->metrics->blockBreakdown($workout);
        $summary = $this->metrics->summary($workout);

        self::assertCount(2, $blocks);
        self::assertSame(3, $blocks[0]['workingSets']);
        // Bloc à 2 tours : 3 séries × 2.
        self::assertSame(6, $blocks[1]['workingSets']);

        self::assertSame(
            $summary['workingSets'],
            $blocks[0]['workingSets'] + $blocks[1]['workingSets'],
        );
        self::assertSame(
            $summary['tonnageKg'],
            $blocks[0]['tonnageKg'] + $blocks[1]['tonnageKg'],
        );
        // La durée déléguée à l'estimateur doit retomber sur son total.
        self::assertSame(
            (new WorkoutEstimator())->estimateSeconds($workout),
            $blocks[0]['seconds'] + $blocks[1]['seconds'],
        );
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
