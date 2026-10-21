<?php

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PrescribedExercise;
use App\Entity\PrescribedSet;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Repository\ScheduledWorkoutRepository;
use App\Service\LineageSeed;
use App\Service\WorkoutCloner;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La lignée d'une séance et la pose « depuis le dernier réalisé ».
 *
 * Ce que ces tests protègent : qu'une copie de plan sache toujours de quelle
 * séance de bibliothèque elle descend (même copiée de copie), et que reposer la
 * séance reprenne ce qui a été FAIT la dernière fois, sans jamais inventer de
 * valeur là où le réalisé ne dit rien (exercice passé, série sans valeur, cardio).
 */
final class LineageSeedTest extends KernelTestCase
{
    use FindsEntities;
    use PurgesDatabase;

    private EntityManagerInterface $em;
    private WorkoutCloner $cloner;
    private LineageSeed $seed;
    private User $user;
    private Exercise $squat;
    private Exercise $bench;
    private Exercise $run;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->cloner = static::getContainer()->get(WorkoutCloner::class);
        $this->seed = static::getContainer()->get(LineageSeed::class);

        $this->purgeDatabase($this->em);

        $this->user = (new User())->setEmail('lineage@example.com')->setPassword('x');
        $this->em->persist($this->user);

        $this->squat = (new Exercise())->setName('Squat')->setActivity(ActivityType::GYM);
        $this->bench = (new Exercise())->setName('Développé couché')->setActivity(ActivityType::GYM);
        $this->run = (new Exercise())->setName('Course')->setActivity(ActivityType::RUNNING);
        $this->em->persist($this->squat);
        $this->em->persist($this->bench);
        $this->em->persist($this->run);
        $this->em->flush();
    }

    public function testAPlanCopyPointsToTheLibraryRootEvenWhenCopiedFromACopy(): void
    {
        $library = $this->libraryWorkout();

        $copy = $this->cloner->cloneWorkout($library, $this->user, null, true);
        $copyOfCopy = $this->cloner->cloneWorkout($copy, $this->user, null, true);
        $duplicate = $this->cloner->cloneWorkout($library, $this->user, 'Upper A (copie)', false);
        $this->em->flush();

        self::assertSame($library, $copy->getOrigin());
        // Duplication de semaine : on clone la copie, la racine reste la biblio.
        self::assertSame($library, $copyOfCopy->getOrigin());
        // Duplication de bibliothèque : une nouvelle séance, sans parenté.
        self::assertNull($duplicate->getOrigin());
    }

    public function testWithoutAnyDoneSessionTheCopyStartsFromTheLibrary(): void
    {
        $library = $this->libraryWorkout();
        // Une séance prévue n'est pas une réalisation.
        $this->schedule($this->cloner->cloneWorkout($library, $this->user, null, true), '2026-10-01', ScheduledStatus::PLANNED);

        $copy = $this->seed->seed($library, $this->user);
        $this->em->flush();

        $squat = $this->prescribedOf($copy, $this->squat);
        self::assertTrue($copy->isPlanLocal());
        self::assertSame($library, $copy->getOrigin());
        self::assertSame(3, $squat->getSets());
        self::assertSame(80.0, $squat->getWeightKg());
    }

    public function testUniformLoggedSetsComeBackInSimpleMode(): void
    {
        $library = $this->libraryWorkout();
        $fork = $this->cloner->cloneWorkout($library, $this->user, null, true);
        $scheduled = $this->schedule($fork, '2026-10-10', ScheduledStatus::DONE);
        $this->log($scheduled, $this->prescribedOf($fork, $this->squat), [
            [SetType::NORMAL, 8, 85.0],
            [SetType::NORMAL, 8, 85.0],
            [SetType::NORMAL, 8, 85.0],
            [SetType::NORMAL, 8, 85.0],
        ]);

        $copy = $this->seed->seed($library, $this->user);
        $this->em->flush();
        $this->em->clear();

        $squat = $this->prescribedOf($this->mustFind(Workout::class, $copy->getId()), $this->squat);
        self::assertFalse($squat->hasDetailedSets());
        self::assertSame(4, $squat->getSets());
        self::assertSame(8, $squat->getReps());
        self::assertSame(85.0, $squat->getWeightKg());
    }

    public function testVariedSetsAndWarmupComeBackInDetailedModeWithASyncedCounter(): void
    {
        $library = $this->libraryWorkout();
        $fork = $this->cloner->cloneWorkout($library, $this->user, null, true);
        $scheduled = $this->schedule($fork, '2026-10-10', ScheduledStatus::DONE);
        $this->log($scheduled, $this->prescribedOf($fork, $this->squat), [
            [SetType::WARMUP, 10, 40.0],
            [SetType::NORMAL, 6, 90.0],
            [SetType::NORMAL, 5, 92.5],
            // Cochée sans valeur : elle ne dit rien à reprendre.
            [SetType::NORMAL, null, 92.5],
        ]);

        $copy = $this->seed->seed($library, $this->user);
        $this->em->flush();
        $this->em->clear();

        $squat = $this->prescribedOf($this->mustFind(Workout::class, $copy->getId()), $this->squat);
        $sets = $squat->getDetailedSets()->toArray();
        usort($sets, static fn (PrescribedSet $a, PrescribedSet $b) => $a->getPosition() <=> $b->getPosition());

        self::assertCount(3, $sets);
        self::assertSame(SetType::WARMUP, $sets[0]->getSetType());
        self::assertSame([6, 90.0], [$sets[1]->getReps(), $sets[1]->getWeightKg()]);
        self::assertSame([5, 92.5], [$sets[2]->getReps(), $sets[2]->getWeightKg()]);
        // Le compteur scalaire suit le décompte de travail, échauffement exclu.
        self::assertSame(2, $squat->getSets());
    }

    /**
     * La base, c'est la séance faite telle qu'elle était : un exercice ajouté en
     * cours de plan est repris. Ce que le réalisé ne décrit pas (exercice passé,
     * cardio) garde le prescrit de CETTE séance, pas celui de la bibliothèque.
     */
    public function testTheLastDoneStructureIsKeptAndSilentExercisesKeepTheirPrescription(): void
    {
        $library = $this->libraryWorkout();
        $fork = $this->cloner->cloneWorkout($library, $this->user, null, true);
        $this->prescribedOf($fork, $this->squat)->setWeightKg(82.5);
        $block = $fork->getBlocks()->first();
        self::assertNotFalse($block);
        $block->addPrescribedExercise($this->prescribed($this->bench, 2, 3, 10, 60.0));
        $scheduled = $this->schedule($fork, '2026-10-10', ScheduledStatus::DONE);

        $skipped = $this->log($scheduled, $this->prescribedOf($fork, $this->squat), [[SetType::NORMAL, 8, 100.0]]);
        $skipped->setSkipped(true);
        $this->em->flush();

        $copy = $this->seed->seed($library, $this->user);
        $this->em->flush();

        self::assertSame(82.5, $this->prescribedOf($copy, $this->squat)->getWeightKg());
        self::assertSame(60.0, $this->prescribedOf($copy, $this->bench)->getWeightKg());
        self::assertSame(5000, $this->prescribedOf($copy, $this->run)->getDistanceMeters());
        // Le titre reste celui de la bibliothèque.
        self::assertSame('Upper A', $copy->getTitle());
    }

    public function testTheMostRecentDoneSessionWinsAndADirectCalendarPoseCounts(): void
    {
        $library = $this->libraryWorkout();
        $fork = $this->cloner->cloneWorkout($library, $this->user, null, true);
        $older = $this->schedule($fork, '2026-09-01', ScheduledStatus::DONE);
        $this->log($older, $this->prescribedOf($fork, $this->squat), [[SetType::NORMAL, 8, 70.0]]);

        // Pose directe au calendrier : elle pointe la bibliothèque, sans fork.
        $direct = $this->schedule($library, '2026-10-15', ScheduledStatus::DONE);
        $this->log($direct, $this->prescribedOf($library, $this->squat), [[SetType::NORMAL, 5, 95.0]]);

        /** @var ScheduledWorkoutRepository $repository */
        $repository = $this->em->getRepository(ScheduledWorkout::class);
        $done = $repository->findDoneInLineage($library);
        self::assertSame([$direct->getId(), $older->getId()], array_map(static fn (ScheduledWorkout $s) => $s->getId(), $done));
        $lastDone = $repository->lastDoneDateByLineage($this->user);
        $libraryId = $library->getId();
        self::assertNotNull($libraryId);
        self::assertArrayHasKey($libraryId, $lastDone);
        self::assertEquals(new \DateTimeImmutable('2026-10-15'), $lastDone[$libraryId]);

        $copy = $this->seed->seed($library, $this->user);
        $this->em->flush();

        self::assertSame(95.0, $this->prescribedOf($copy, $this->squat)->getWeightKg());
    }

    // ---- Fixtures -----------------------------------------------------------

    /** « Upper A » : squat 3 × 8 à 80 kg, puis 5 km de course. */
    private function libraryWorkout(): Workout
    {
        $workout = (new Workout())
            ->setOwner($this->user)
            ->setTitle('Upper A')
            ->setSlug('upper-a-'.uniqid());
        $workout->addBlock(
            (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(1)
                ->addPrescribedExercise($this->prescribed($this->squat, 1, 3, 8, 80.0))
                ->addPrescribedExercise(
                    (new PrescribedExercise())
                        ->setExercise($this->run)
                        ->setPosition(3)
                        ->setPrescriptionType(PrescriptionType::DISTANCE_PACE)
                        ->setDistanceMeters(5000)
                )
        );
        $this->em->persist($workout);
        $this->em->flush();

        return $workout;
    }

    private function prescribed(Exercise $exercise, int $position, int $sets, int $reps, float $weight): PrescribedExercise
    {
        return (new PrescribedExercise())
            ->setExercise($exercise)
            ->setPosition($position)
            ->setPrescriptionType(PrescriptionType::SETS_REPS)
            ->setSets($sets)
            ->setReps($reps)
            ->setWeightKg($weight);
    }

    private function prescribedOf(Workout $workout, Exercise $exercise): PrescribedExercise
    {
        foreach ($workout->getBlocks() as $block) {
            foreach ($block->getPrescribedExercises() as $prescribed) {
                if ($prescribed->getExercise()?->getId() === $exercise->getId()) {
                    return $prescribed;
                }
            }
        }

        throw new \LogicException('Exercice absent de la séance.');
    }

    private function schedule(Workout $workout, string $date, ScheduledStatus $status): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($this->user)
            ->setWorkout($workout)
            ->setScheduledDate(new \DateTimeImmutable($date))
            ->setStatus($status);
        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }

    /**
     * @param list<array{SetType, ?int, ?float}> $sets
     */
    private function log(ScheduledWorkout $scheduled, PrescribedExercise $source, array $sets): LoggedExercise
    {
        $logged = (new LoggedExercise())
            ->setExercise($source->getExercise())
            ->setExerciseName((string) $source->getExercise()?->getName())
            ->setSourcePrescribedExercise($source)
            ->setPosition($scheduled->getLoggedExercises()->count() + 1);

        foreach ($sets as $position => [$type, $reps, $weight]) {
            $logged->addLoggedSet(
                (new LoggedSet())->setPosition($position + 1)->setSetType($type)->setReps($reps)->setWeightKg($weight)
            );
        }

        $scheduled->addLoggedExercise($logged);
        $this->em->flush();

        return $logged;
    }
}
