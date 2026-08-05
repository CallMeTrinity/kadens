<?php

namespace App\Tests\Command;

use App\Entity\Block;
use App\Entity\Exercise;
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
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:log:backfill` : le rattrapage du réalisé des séances faites avant l'app
 * mobile.
 *
 * Ce que les tests tiennent, c'est la **frontière de l'assiette** — une séance
 * manquée, prévue, déjà loguée ou purement cardio n'est jamais touchée — et la
 * **fidélité de la déduction** : le nombre de séries écrites doit valoir ce que
 * `WorkoutMetrics` comptait déjà comme volume prévu, tours du bloc compris.
 */
final class BackfillLogsCommandTest extends KernelTestCase
{
    use PurgesDatabase;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        $this->purgeDatabase($this->em);
    }

    public function testItWritesTheLogOfAPastDoneStrengthSession(): void
    {
        $user = $this->createUser('athlete@example.com');
        $scheduled = $this->createSession($user, ScheduledStatus::DONE, '-3 days', static function (Block $block, Exercise $exercise): void {
            $block->addPrescribedExercise(
                (new PrescribedExercise())
                    ->setExercise($exercise)
                    ->setPosition(0)
                    ->setPrescriptionType(PrescriptionType::SETS_REPS)
                    ->setSets(4)->setReps(8)->setWeightKg(80.0)->setRpe(8),
            );
        });

        $this->launch(['--force' => true]);

        $this->em->clear();
        $reloaded = $this->em->find(ScheduledWorkout::class, $scheduled->getId());
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->getLoggedExercises());

        $logged = $reloaded->getLoggedExercises()->first();
        self::assertSame('Développé couché', $logged->getExerciseName());
        self::assertNotNull($logged->getSourcePrescribedExercise());
        self::assertCount(4, $logged->getLoggedSets());
        self::assertSame(4, $logged->getWorkingSetCount());

        $first = $logged->getLoggedSets()->first();
        self::assertSame(8, $first->getReps());
        self::assertSame(80.0, $first->getWeightKg());
        self::assertSame(SetType::NORMAL, $first->getSetType());
        // Le RPE prescrit descend sur la série : si le prescrit décrit ce qui a
        // eu lieu, il le décrit entièrement.
        self::assertSame(8, $first->getRpe());
        // Rien n'est inventé de ce qu'on ne sait pas : l'heure d'exécution.
        self::assertNull($first->getCompletedAt());
        self::assertNull($reloaded->getStartedAt());
    }

    public function testSimulationWritesNothing(): void
    {
        $user = $this->createUser('athlete@example.com');
        $scheduled = $this->createSession($user, ScheduledStatus::DONE, '-3 days', static function (Block $block, Exercise $exercise): void {
            $block->addPrescribedExercise(
                (new PrescribedExercise())
                    ->setExercise($exercise)
                    ->setPosition(0)
                    ->setPrescriptionType(PrescriptionType::SETS_REPS)
                    ->setSets(3)->setReps(10)->setWeightKg(60.0),
            );
        });

        $tester = $this->launch([]);

        self::assertStringContainsString('Simulation', $tester->getDisplay());

        $this->em->clear();
        $reloaded = $this->em->find(ScheduledWorkout::class, $scheduled->getId());
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->hasLog());
    }

    public function testDetailedSetsAndBlockRoundsAreHonoured(): void
    {
        $user = $this->createUser('athlete@example.com');
        $scheduled = $this->createSession($user, ScheduledStatus::DONE, '-1 day', static function (Block $block, Exercise $exercise): void {
            // Deux tours de bloc × trois séries détaillées, dont un échauffement.
            $block->setRounds(2);

            $prescribed = (new PrescribedExercise())
                ->setExercise($exercise)
                ->setPosition(0)
                ->setPrescriptionType(PrescriptionType::SETS_REPS)
                ->setSets(2)->setReps(8)->setWeightKg(80.0);

            foreach ([[SetType::WARMUP, 10, 40.0], [SetType::NORMAL, 8, 80.0], [SetType::TO_FAILURE, 6, 85.0]] as $rank => [$type, $reps, $weight]) {
                $prescribed->addDetailedSet(
                    (new PrescribedSet())
                        ->setPosition($rank)
                        ->setSetType($type)
                        ->setReps($reps)
                        ->setWeightKg($weight),
                );
            }

            $block->addPrescribedExercise($prescribed);
        });

        $this->launch(['--force' => true]);

        $this->em->clear();
        $logged = $this->em->find(ScheduledWorkout::class, $scheduled->getId())?->getLoggedExercises()->first();
        self::assertNotFalse($logged);

        // Les détaillées priment sur le scalaire (2 séries), et les tours
        // multiplient : 3 × 2 = 6 lignes, dont 4 de travail.
        self::assertCount(6, $logged->getLoggedSets());
        self::assertSame(4, $logged->getWorkingSetCount());
        self::assertSame(SetType::WARMUP, $logged->getLoggedSets()->first()->getSetType());

        $positions = array_map(
            static fn ($set): ?int => $set->getPosition(),
            $logged->getLoggedSets()->toArray(),
        );
        self::assertSame([0, 1, 2, 3, 4, 5], $positions);
    }

    public function testItSkipsWhatIsNotAPastDoneStrengthSessionWithoutLog(): void
    {
        $user = $this->createUser('athlete@example.com');
        $strength = static function (Block $block, Exercise $exercise): void {
            $block->addPrescribedExercise(
                (new PrescribedExercise())
                    ->setExercise($exercise)
                    ->setPosition(0)
                    ->setPrescriptionType(PrescriptionType::SETS_REPS)
                    ->setSets(3)->setReps(10)->setWeightKg(60.0),
            );
        };

        $missed = $this->createSession($user, ScheduledStatus::MISSED, '-3 days', $strength);
        $planned = $this->createSession($user, ScheduledStatus::PLANNED, '-3 days', $strength);
        $future = $this->createSession($user, ScheduledStatus::DONE, '+3 days', $strength);

        // Faite, passée, mais 100 % cardio : le réalisé ne se logue pas en cardio.
        $running = $this->createSession($user, ScheduledStatus::DONE, '-3 days', function (Block $block) use ($user): void {
            $exercise = (new Exercise())
                ->setOwner($user)
                ->setName('Footing')
                ->setActivity(ActivityType::RUNNING);
            $this->em->persist($exercise);

            $block->addPrescribedExercise(
                (new PrescribedExercise())
                    ->setExercise($exercise)
                    ->setPosition(0)
                    ->setPrescriptionType(PrescriptionType::DISTANCE_PACE)
                    ->setSets(1)->setDistanceMeters(10_000),
            );
        });

        $tester = $this->launch(['--force' => true]);

        self::assertStringContainsString('Aucune séance à rattraper', $tester->getDisplay());

        $this->em->clear();
        foreach ([$missed, $planned, $future, $running] as $untouched) {
            self::assertFalse(
                $this->em->find(ScheduledWorkout::class, $untouched->getId())->hasLog(),
                'Une séance hors assiette a été touchée.',
            );
        }
    }

    public function testAnExistingLogIsNeverReplaced(): void
    {
        $user = $this->createUser('athlete@example.com');
        $scheduled = $this->createSession($user, ScheduledStatus::DONE, '-2 days', static function (Block $block, Exercise $exercise): void {
            $block->addPrescribedExercise(
                (new PrescribedExercise())
                    ->setExercise($exercise)
                    ->setPosition(0)
                    ->setPrescriptionType(PrescriptionType::SETS_REPS)
                    ->setSets(5)->setReps(5)->setWeightKg(100.0),
            );
        });

        // Premier passage : la déduction s'écrit.
        $this->launch(['--force' => true]);
        // Second passage : la séance porte un réalisé, elle n'est plus candidate.
        $tester = $this->launch(['--force' => true]);

        self::assertStringContainsString('Aucune séance à rattraper', $tester->getDisplay());

        $this->em->clear();
        $reloaded = $this->em->find(ScheduledWorkout::class, $scheduled->getId());
        self::assertCount(1, $reloaded->getLoggedExercises());
        self::assertCount(5, $reloaded->getLoggedExercises()->first()->getLoggedSets());
    }

    public function testTheUserFilterScopesTheRun(): void
    {
        $mine = $this->createUser('moi@example.com');
        $other = $this->createUser('autre@example.com');

        $strength = static function (Block $block, Exercise $exercise): void {
            $block->addPrescribedExercise(
                (new PrescribedExercise())
                    ->setExercise($exercise)
                    ->setPosition(0)
                    ->setPrescriptionType(PrescriptionType::SETS_REPS)
                    ->setSets(3)->setReps(8)->setWeightKg(70.0),
            );
        };

        $ours = $this->createSession($mine, ScheduledStatus::DONE, '-2 days', $strength);
        $theirs = $this->createSession($other, ScheduledStatus::DONE, '-2 days', $strength);

        $this->launch(['--force' => true, '--user' => 'moi@example.com']);

        $this->em->clear();
        self::assertTrue($this->em->find(ScheduledWorkout::class, $ours->getId())->hasLog());
        self::assertFalse($this->em->find(ScheduledWorkout::class, $theirs->getId())->hasLog());
    }

    public function testAnUnknownAccountIsRefused(): void
    {
        $tester = $this->launch(['--user' => 'personne@example.com']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('Aucun compte', $tester->getDisplay());
    }

    // --------------------------------------------------------- Fixtures

    /**
     * @param array<string, bool|string> $arguments
     */
    private function launch(array $arguments): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:log:backfill'));
        $tester->execute($arguments);

        return $tester;
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('peu-importe');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Une séance datée d'un seul bloc, garni par `$fill`, à `$date` jours de
     * maintenant.
     *
     * @param callable(Block, Exercise): void $fill
     */
    private function createSession(User $owner, ScheduledStatus $status, string $date, callable $fill): ScheduledWorkout
    {
        $exercise = (new Exercise())
            ->setOwner($owner)
            ->setName('Développé couché')
            ->setActivity(ActivityType::GYM);
        $this->em->persist($exercise);

        $workout = (new Workout())
            ->setOwner($owner)
            ->setTitle('Haut du corps')
            ->setSlug('haut-du-corps-'.bin2hex(random_bytes(4)));
        $this->em->persist($workout);

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $fill($block, $exercise);
        $workout->addBlock($block);
        $this->em->persist($block);

        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setWorkout($workout)
            ->setScheduledDate(new \DateTimeImmutable($date))
            ->setStatus($status);

        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }
}
