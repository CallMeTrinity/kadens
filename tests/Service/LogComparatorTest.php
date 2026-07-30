<?php

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PrescribedExercise;
use App\Entity\PrescribedSet;
use App\Entity\ScheduledWorkout;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\LogDeviation;
use App\Enum\PrescriptionType;
use App\Enum\SetType;
use App\Service\LogComparator;
use App\Service\PlanFlattener;
use App\Service\RegionBreakdown;
use App\Service\SupersetGrouper;
use App\Service\UnitFormatter;
use App\Service\WorkoutEstimator;
use App\Service\WorkoutMetrics;
use PHPUnit\Framework\TestCase;

final class LogComparatorTest extends TestCase
{
    private LogComparator $comparator;

    protected function setUp(): void
    {
        $flattener = new PlanFlattener(
            new UnitFormatter(),
            new WorkoutMetrics(new WorkoutEstimator(), new SupersetGrouper(), new RegionBreakdown()),
            new SupersetGrouper(),
        );

        $this->comparator = new LogComparator($flattener, new UnitFormatter());
    }

    public function testNothingToCompareWithoutAnyLog(): void
    {
        // Une séance seulement planifiée n'a pas d'écart à montrer : la colonne
        // « Réalisé » n'apparaît pas, elle ne s'affiche pas vide.
        $workout = $this->workout([$this->prescribed('Squat', 3, 5, 100.0)]);
        $scheduled = $this->scheduled($workout, []);

        self::assertSame([], $this->comparator->compare($scheduled));
    }

    public function testSetsAreAlignedOneByOneAndHeldWhenIdentical(): void
    {
        $prescribed = $this->prescribed('Développé couché', 3, 8, 80.0);
        $logged = $this->logged('Développé couché', [
            [SetType::NORMAL, 8, 80.0],
            [SetType::NORMAL, 8, 80.0],
            [SetType::NORMAL, 8, 80.0],
        ], $prescribed);

        $comparison = $this->comparator->compare($this->scheduled($this->workout([$prescribed]), [$logged]));

        self::assertCount(1, $comparison);
        self::assertSame('Développé couché', $comparison[0]['name']);
        self::assertSame(LogDeviation::HELD, $comparison[0]['status']);
        self::assertCount(3, $comparison[0]['lines']);

        foreach ($comparison[0]['lines'] as $index => $line) {
            self::assertSame($index + 1, $line['index']);
            self::assertSame(LogDeviation::HELD, $line['status']);
            // La colonne « Réalisé » se rend avec la même forme que « Prévu ».
            self::assertSame($line['planned']['effort'], $line['logged']['effort']);
            self::assertSame(80.0, $line['logged']['weightKg']);
        }
    }

    public function testHeavierMeansExceededAndFewerRepsMeansLightened(): void
    {
        $prescribed = $this->prescribed('Squat', 2, 5, 100.0);
        $logged = $this->logged('Squat', [
            [SetType::NORMAL, 5, 105.0],
            [SetType::NORMAL, 3, 100.0],
        ], $prescribed);

        $lines = $this->comparator->compare($this->scheduled($this->workout([$prescribed]), [$logged]))[0]['lines'];

        self::assertSame(LogDeviation::EXCEEDED, $lines[0]['status']);
        self::assertSame(LogDeviation::LIGHTENED, $lines[1]['status']);
    }

    public function testTonnageDecidesBeforeLoadWhenTheyDisagree(): void
    {
        // Plus lourd mais moins de répétitions : 6 × 82,5 = 495 contre 8 × 80 =
        // 640. C'est moins de travail, donc allégé — la charge seule dirait le
        // contraire.
        $prescribed = $this->prescribed('Développé couché', 1, 8, 80.0);
        $logged = $this->logged('Développé couché', [[SetType::NORMAL, 6, 82.5]], $prescribed);

        $comparison = $this->comparator->compare($this->scheduled($this->workout([$prescribed]), [$logged]));

        self::assertSame(LogDeviation::LIGHTENED, $comparison[0]['lines'][0]['status']);
        self::assertSame(LogDeviation::LIGHTENED, $comparison[0]['status']);
    }

    public function testBodyweightSetIsNotJudgedOnAMissingLoad(): void
    {
        // Aucune charge des deux côtés : l'axe est muet, ce sont les répétitions
        // qui parlent. Un axe absent d'un côté ne doit jamais trancher.
        $prescribed = $this->prescribed('Tractions', 1, 10, null);
        $logged = $this->logged('Tractions', [[SetType::NORMAL, 12, null]], $prescribed);

        $comparison = $this->comparator->compare($this->scheduled($this->workout([$prescribed]), [$logged]));

        self::assertSame(LogDeviation::EXCEEDED, $comparison[0]['status']);
    }

    public function testUnloggedWarmupDoesNotShiftTheWorkingSets(): void
    {
        // Échauffement prescrit, jamais logué : apparier à la file toutes séries
        // confondues décalerait tout d'un cran et lirait la séance « allégée »
        // alors qu'elle a été tenue.
        $prescribed = $this->detailed('Squat', [
            [SetType::WARMUP, 10, 40.0],
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
        ]);
        $logged = $this->logged('Squat', [
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
        ], $prescribed);

        $comparison = $this->comparator->compare($this->scheduled($this->workout([$prescribed]), [$logged]));

        self::assertSame(LogDeviation::HELD, $comparison[0]['status']);

        $lines = $comparison[0]['lines'];
        self::assertCount(3, $lines);
        // L'échauffement reste une ligne visible, simplement non réalisée.
        self::assertSame(SetType::WARMUP, $lines[0]['planned']['type']);
        self::assertNull($lines[0]['logged']);
        self::assertSame(LogDeviation::NOT_LOGGED, $lines[0]['status']);
        self::assertSame(LogDeviation::HELD, $lines[1]['status']);
        self::assertSame(LogDeviation::HELD, $lines[2]['status']);
    }

    public function testExtraSetIsUnplannedAndPushesTheExerciseToExceeded(): void
    {
        $prescribed = $this->prescribed('Curl', 2, 12, 20.0);
        $logged = $this->logged('Curl', [
            [SetType::NORMAL, 12, 20.0],
            [SetType::NORMAL, 12, 20.0],
            [SetType::NORMAL, 10, 20.0],
        ], $prescribed);

        $comparison = $this->comparator->compare($this->scheduled($this->workout([$prescribed]), [$logged]));
        $lines = $comparison[0]['lines'];

        self::assertCount(3, $lines);
        self::assertNull($lines[2]['planned']);
        self::assertSame(LogDeviation::UNPLANNED, $lines[2]['status']);
        self::assertSame(LogDeviation::EXCEEDED, $comparison[0]['status']);
    }

    public function testSkippedExerciseIsDeclared(): void
    {
        $squat = $this->prescribed('Squat', 3, 5, 100.0);
        $lunges = $this->prescribed('Fentes', 3, 10, 40.0);

        $doneSquat = $this->logged('Squat', [
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
        ], $squat);
        // Sauté mais portant une série saisie puis abandonnée : l'état est
        // déclaré, il ne se déduit pas de ce qui reste.
        $skipped = $this->logged('Fentes', [[SetType::NORMAL, 10, 40.0]], $lunges);
        $skipped->setSkipped(true);

        $comparison = $this->comparator->compare($this->scheduled($this->workout([$squat, $lunges]), [$doneSquat, $skipped]));

        self::assertSame(LogDeviation::HELD, $comparison[0]['status']);
        self::assertSame(LogDeviation::SKIPPED, $comparison[1]['status']);
    }

    public function testPrescribedExerciseWithoutAnyLogIsNotLogged(): void
    {
        // Un trou n'est pas une déclaration : « non réalisé » ≠ « sauté ».
        $squat = $this->prescribed('Squat', 3, 5, 100.0);
        $lunges = $this->prescribed('Fentes', 3, 10, 40.0);
        $done = $this->logged('Squat', [
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
        ], $squat);

        $comparison = $this->comparator->compare($this->scheduled($this->workout([$squat, $lunges]), [$done]));

        self::assertSame(LogDeviation::NOT_LOGGED, $comparison[1]['status']);
        self::assertNull($comparison[1]['logged']);
        // Les lignes prescrites restent là, colonne « Réalisé » vide.
        self::assertCount(3, $comparison[1]['lines']);
        self::assertNull($comparison[1]['lines'][0]['logged']);
    }

    public function testExerciseAddedOnTheFlyIsOutOfProgram(): void
    {
        $squat = $this->prescribed('Squat', 3, 5, 100.0);
        $done = $this->logged('Squat', [
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
        ], $squat);
        // Ni source prescrite, ni exercice présent au programme.
        $extra = $this->logged('Presse à cuisses', [[SetType::NORMAL, 10, 180.0]], null);

        $comparison = $this->comparator->compare($this->scheduled($this->workout([$squat]), [$done, $extra]));

        self::assertCount(2, $comparison);
        // Les hors programme viennent à la suite du programme, jamais dedans.
        self::assertSame(LogDeviation::UNPLANNED, $comparison[1]['status']);
        self::assertSame('Presse à cuisses', $comparison[1]['name']);
        self::assertNull($comparison[1]['planned']);
    }

    public function testMatchingFallsBackOnTheExerciseWhenTheSourceIsGone(): void
    {
        // `sourcePrescribedExercise` est en SET NULL : éditer le programme après
        // coup ne doit pas transformer un exercice fait en « hors programme ».
        $prescribed = $this->prescribed('Squat', 3, 5, 100.0);
        $logged = $this->logged('Squat', [
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
        ], null);
        $logged->setExercise($prescribed->getExercise());

        $comparison = $this->comparator->compare($this->scheduled($this->workout([$prescribed]), [$logged]));

        self::assertCount(1, $comparison);
        self::assertSame(LogDeviation::HELD, $comparison[0]['status']);
        self::assertSame($prescribed, $comparison[0]['planned']['prescribed']);
    }

    public function testExplicitSourceWinsOverTheExerciseOrder(): void
    {
        // Deux lignes du même exercice : le lien explicite doit désigner la
        // bonne, même quand un autre log passe en premier dans la collection.
        $heavy = $this->prescribed('Squat', 3, 5, 100.0);
        $light = $this->prescribed('Squat', 3, 12, 60.0, $heavy->getExercise());

        $onLight = $this->logged('Squat', [[SetType::NORMAL, 12, 60.0]], $light);
        $onHeavy = $this->logged('Squat', [[SetType::NORMAL, 5, 100.0]], $heavy);

        // Le log de la SECONDE ligne arrive en premier.
        $comparison = $this->comparator->compare($this->scheduled($this->workout([$heavy, $light]), [$onLight, $onHeavy]));

        self::assertSame($onHeavy, $comparison[0]['logged']);
        self::assertSame($onLight, $comparison[1]['logged']);
    }

    public function testFreeWorkoutHasEverythingOutOfProgram(): void
    {
        // Séance datée sans source : il n'y a pas de programme, donc pas de
        // colonne « Prévu » à remplir.
        $logged = $this->logged('Rowing barre', [[SetType::NORMAL, 10, 60.0]], null);
        $comparison = $this->comparator->compare($this->scheduled(null, [$logged]));

        self::assertCount(1, $comparison);
        self::assertSame(LogDeviation::UNPLANNED, $comparison[0]['status']);
        self::assertNull($comparison[0]['planned']);
        self::assertNull($comparison[0]['prescribedId']);
        self::assertSame('10 reps', $comparison[0]['lines'][0]['logged']['effort']);
    }

    public function testDeletedExerciseKeepsItsSnapshotName(): void
    {
        // SET NULL sur `exercise` : le nom du réalisé survit à la suppression.
        $logged = $this->logged('Rowing barre', [[SetType::NORMAL, 10, 60.0]], null);
        $logged->setExercise(null);

        $comparison = $this->comparator->compare($this->scheduled(null, [$logged]));

        self::assertSame('Rowing barre', $comparison[0]['name']);
    }

    public function testTimedSetsAreComparedOnTheirDuration(): void
    {
        $prescribed = (new PrescribedExercise())
            ->setPrescriptionType(PrescriptionType::SETS_TIME)
            ->setExercise((new Exercise())->setName('Gainage')->setActivity(ActivityType::GYM))
            ->setSets(3)
            ->setDurationSeconds(60);

        $logged = (new LoggedExercise())
            ->setExerciseName('Gainage')
            ->setExercise($prescribed->getExercise())
            ->setSourcePrescribedExercise($prescribed);
        foreach ([60, 60, 45] as $position => $seconds) {
            $logged->addLoggedSet((new LoggedSet())->setPosition($position)->setDurationSeconds($seconds));
        }

        $comparison = $this->comparator->compare($this->scheduled($this->workout([$prescribed]), [$logged]));

        // Aucun tonnage ni charge des deux côtés : c'est la durée qui tranche.
        self::assertSame(LogDeviation::HELD, $comparison[0]['lines'][0]['status']);
        self::assertSame(LogDeviation::LIGHTENED, $comparison[0]['lines'][2]['status']);
        self::assertSame(LogDeviation::LIGHTENED, $comparison[0]['status']);
        self::assertSame('1:00', $comparison[0]['lines'][0]['logged']['effort']);
    }

    public function testCardioPrescriptionIsNotJudgedOnSetsItDoesNotHave(): void
    {
        // Un DISTANCE_PACE n'a pas de séries à apparier (son `sets` compte des
        // intervalles) : on affiche le réalisé sans prétendre mesurer l'écart.
        $prescribed = (new PrescribedExercise())
            ->setPrescriptionType(PrescriptionType::DISTANCE_PACE)
            ->setExercise((new Exercise())->setName('Sortie longue')->setActivity(ActivityType::RUNNING))
            ->setDistanceMeters(10000);

        $logged = $this->logged('Sortie longue', [[SetType::NORMAL, null, null]], $prescribed);

        $comparison = $this->comparator->compare($this->scheduled($this->workout([$prescribed]), [$logged]));

        self::assertSame(LogDeviation::HELD, $comparison[0]['status']);
        self::assertNull($comparison[0]['lines'][0]['planned']);
        self::assertSame(LogDeviation::HELD, $comparison[0]['lines'][0]['status']);
    }

    // ---- Fabriques ---------------------------------------------------------

    private function prescribed(string $name, int $sets, int $reps, ?float $weightKg, ?Exercise $exercise = null): PrescribedExercise
    {
        return (new PrescribedExercise())
            ->setPrescriptionType(PrescriptionType::SETS_REPS)
            ->setExercise($exercise ?? (new Exercise())->setName($name)->setActivity(ActivityType::GYM))
            ->setSets($sets)
            ->setReps($reps)
            ->setWeightKg($weightKg);
    }

    /**
     * @param list<array{SetType, int|null, float|null}> $sets
     */
    private function detailed(string $name, array $sets): PrescribedExercise
    {
        $prescribed = (new PrescribedExercise())
            ->setPrescriptionType(PrescriptionType::SETS_REPS)
            ->setExercise((new Exercise())->setName($name)->setActivity(ActivityType::GYM));

        foreach ($sets as $position => [$type, $reps, $weightKg]) {
            $prescribed->addDetailedSet(
                (new PrescribedSet())->setPosition($position)->setSetType($type)->setReps($reps)->setWeightKg($weightKg),
            );
        }

        return $prescribed;
    }

    /**
     * @param list<array{SetType, int|null, float|null}> $sets
     */
    private function logged(string $name, array $sets, ?PrescribedExercise $source): LoggedExercise
    {
        $logged = (new LoggedExercise())
            ->setExerciseName($name)
            ->setSourcePrescribedExercise($source)
            ->setExercise($source?->getExercise());

        foreach ($sets as $position => [$type, $reps, $weightKg]) {
            $logged->addLoggedSet(
                (new LoggedSet())->setPosition($position)->setSetType($type)->setReps($reps)->setWeightKg($weightKg),
            );
        }

        return $logged;
    }

    /**
     * @param list<PrescribedExercise> $exercises
     */
    private function workout(array $exercises): Workout
    {
        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        foreach ($exercises as $position => $prescribed) {
            $block->addPrescribedExercise($prescribed->setPosition($position));
        }

        return (new Workout())->setTitle('Séance')->setSlug('seance')->addBlock($block);
    }

    /**
     * @param list<LoggedExercise> $logs
     */
    private function scheduled(?Workout $workout, array $logs): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setWorkout($workout)
            ->setScheduledDate(new \DateTimeImmutable('2026-07-30'));

        foreach ($logs as $position => $logged) {
            $scheduled->addLoggedExercise($logged->setPosition($position));
        }

        return $scheduled;
    }
}
