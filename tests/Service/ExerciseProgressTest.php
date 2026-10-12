<?php

namespace App\Tests\Service;

use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\ProgressRange;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Service\ExerciseProgress;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les pics par séance sont calculés en SQL (une ligne par séance, quelle que
 * soit la fenêtre) : c'est là que vivent les règles — échauffement exclu,
 * borne du 1RM estimé, poids du corps — et c'est donc en base qu'on les teste.
 */
final class ExerciseProgressTest extends KernelTestCase
{
    use PurgesDatabase;

    private EntityManagerInterface $em;
    private ExerciseProgress $progress;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->progress = static::getContainer()->get(ExerciseProgress::class);

        $this->purgeDatabase($this->em);
    }

    protected function tearDown(): void
    {
        $this->purgeDatabase($this->em);

        parent::tearDown();
    }

    /**
     * Charge max et 1RM estimé (Epley) par séance. L'échauffement lourd n'entre
     * dans aucune des deux ; une série au-delà de douze répétitions reste dans la
     * charge max mais ne fait pas de 1RM.
     */
    public function testLoadedChartsReadTheWorkingSetsOnly(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise('Squat');

        $this->log($user, $exercise, '-20 days', [
            [SetType::WARMUP, 3, 150.0],
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 20, 110.0],
        ]);
        $this->log($user, $exercise, '-10 days', [
            [SetType::NORMAL, 1, 120.0],
        ]);

        $result = $this->progress->for($user, $exercise, 'all');
        $charts = array_column($result['charts'], null, 'key');

        self::assertSame(ProgressRange::ALL, $result['range']);
        self::assertSame(2, $result['sessions']);

        self::assertSame([110.0, 120.0], array_column($charts['top']['points'], 'value'));
        // 5 × 100 → 100 × 35 / 30 = 116,7 ; les 20 reps à 110 ne comptent pas.
        // Une seule répétition vaut sa charge : 120.
        self::assertSame([116.7, 120.0], array_column($charts['e1rm']['points'], 'value'));
        self::assertSame('up', $charts['e1rm']['direction']);

        // Rien sans charge ajoutée ni en durée : pas de courbe à montrer.
        self::assertArrayNotHasKey('reps', $charts);
        self::assertArrayNotHasKey('duration', $charts);
    }

    /** Au poids du corps, la courbe suit les répétitions ; une série lestée n'y entre pas. */
    public function testUnloadedRepsIgnoreWeightedSets(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise('Traction');

        $this->log($user, $exercise, '-20 days', [
            [SetType::NORMAL, 8, null],
            [SetType::NORMAL, 15, 10.0],
        ]);
        $this->log($user, $exercise, '-10 days', [
            [SetType::NORMAL, 10, 0.0],
        ]);

        $charts = array_column($this->progress->for($user, $exercise, '6m')['charts'], null, 'key');

        self::assertSame([8.0, 10.0], array_column($charts['reps']['points'], 'value'));
        self::assertSame('10 reps', $charts['reps']['last']['label']);
        // Une seule séance lestée : pas de courbe de charge.
        self::assertArrayNotHasKey('top', $charts);
    }

    /** Une fenêtre qui ne garde qu'une séance n'a pas de pente : aucune courbe. */
    public function testASingleSessionInTheWindowDrawsNothing(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise('Squat');

        $this->log($user, $exercise, '-400 days', [[SetType::NORMAL, 5, 90.0]]);
        $this->log($user, $exercise, '-10 days', [[SetType::NORMAL, 5, 100.0]]);

        $result = $this->progress->for($user, $exercise, '1y');

        self::assertSame(1, $result['sessions']);
        self::assertSame([], $result['charts']);
    }

    /** @param list<array{0: SetType, 1: int|null, 2: float|null}> $sets */
    private function log(User $owner, Exercise $exercise, string $offset, array $sets): void
    {
        $date = (new \DateTimeImmutable($offset))->setTime(0, 0);
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle('Séance du '.$date->format('d/m'))
            ->setScheduledDate($date)
            ->setStatus(ScheduledStatus::DONE);

        $logged = (new LoggedExercise())
            ->setExercise($exercise)
            ->setExerciseName((string) $exercise->getName())
            ->setPosition(1);

        $position = 0;
        foreach ($sets as $set) {
            $logged->addLoggedSet(
                (new LoggedSet())
                    ->setPosition(++$position)
                    ->setSetType($set[0])
                    ->setReps($set[1])
                    ->setWeightKg($set[2])
            );
        }

        $scheduled->addLoggedExercise($logged);
        $this->em->persist($scheduled);
        $this->em->flush();
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createExercise(string $name): Exercise
    {
        $exercise = (new Exercise())->setName($name)->setActivity(ActivityType::GYM);
        $this->em->persist($exercise);
        $this->em->flush();

        return $exercise;
    }
}
