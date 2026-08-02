<?php

namespace App\Tests\Service;

use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Service\PerformanceHistory;
use App\Service\UnitFormatter;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PerformanceHistory lit en base : ses règles (échauffement hors record,
 * isolation par utilisateur, dernière séance) vivent dans le SQL, pas en
 * mémoire. Un test unitaire à double ne garderait rien de ce qui compte ici.
 */
final class PerformanceHistoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PerformanceHistory $history;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        // Câblé à la main : le service est injecté dans d'autres services
        // (BootstrapPayload, PerformanceHistoryPayload) et jamais tiré du
        // conteneur, qui l'inline donc.
        $this->history = new PerformanceHistory(
            $this->em->getRepository(LoggedSet::class),
            new UnitFormatter(),
        );

        foreach ($this->em->getRepository(ScheduledWorkout::class)->findAll() as $scheduled) {
            $this->em->remove($scheduled);
        }
        foreach ($this->em->getRepository(Exercise::class)->findAll() as $exercise) {
            $this->em->remove($exercise);
        }
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            $this->em->remove($user);
        }
        $this->em->flush();
    }

    /** Rien de fait, rien à dire : pas de cadre vide à afficher. */
    public function testNoHistoryReturnsNull(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise('Développé couché');

        self::assertNull($this->history->lastPerformance($user, $exercise));
        self::assertNull($this->history->bestSet($user, $exercise));
        self::assertSame([], $this->history->bulkFor($user, [$exercise]));
    }

    /** La dernière performance, c'est la séance la plus récente, pas la première trouvée. */
    public function testLastPerformanceTakesTheMostRecentSession(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise('Développé couché');

        $this->log($user, $exercise, new \DateTimeImmutable('2026-03-01'), [
            [SetType::NORMAL, 8, 80.0],
            [SetType::NORMAL, 8, 80.0],
        ]);
        $this->log($user, $exercise, new \DateTimeImmutable('2026-03-08'), [
            [SetType::NORMAL, 8, 85.0],
            [SetType::NORMAL, 6, 85.0],
        ]);

        $last = $this->history->lastPerformance($user, $exercise);

        self::assertNotNull($last);
        self::assertSame('2026-03-08', $last['date']->format('Y-m-d'));
        self::assertSame(2, $last['workingSets']);
        self::assertSame(85.0, $last['topWeightKg']);
        // 8×85 + 6×85 = 1190
        self::assertSame(1190.0, $last['tonnageKg']);
    }

    /** Séries consécutives identiques fusionnées, rang réel conservé (comme le prescrit). */
    public function testLastPerformanceCondensesIdenticalConsecutiveSets(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise('Squat');

        $this->log($user, $exercise, new \DateTimeImmutable('2026-03-08'), [
            [SetType::WARMUP, 10, 40.0],
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
            [SetType::DROP_SET, 8, 70.0],
        ]);

        $last = $this->history->lastPerformance($user, $exercise);

        self::assertNotNull($last);
        // L'échauffement n'est pas remonté : 4 séries de travail, 2 groupes.
        self::assertSame(4, $last['workingSets']);
        self::assertCount(2, $last['sets']);

        [$work, $drop] = $last['sets'];
        self::assertSame(3, $work['count']);
        self::assertSame('5 reps @ 100 kg', $work['detail']);
        self::assertSame('5 reps', $work['effort']);
        // Rangs des séries de TRAVAIL : l'échauffement ne décale pas la numérotation.
        self::assertSame(1, $work['firstIndex']);
        self::assertSame(3, $work['lastIndex']);
        self::assertSame(SetType::DROP_SET, $drop['type']);
        self::assertSame('Drop set', $drop['typeLabel']);
        self::assertSame(4, $drop['firstIndex']);
    }

    /** La règle qu'un mauvais filtre casse en premier : un échauffement lourd n'est pas un record. */
    public function testWarmupNeverBecomesTheRecord(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise('Soulevé de terre');

        $this->log($user, $exercise, new \DateTimeImmutable('2026-03-08'), [
            [SetType::WARMUP, 3, 200.0],
            [SetType::NORMAL, 5, 140.0],
        ]);

        $best = $this->history->bestSet($user, $exercise);

        self::assertNotNull($best);
        self::assertSame(140.0, $best['weightKg']);
        self::assertSame(5, $best['reps']);
        self::assertSame('5 reps @ 140 kg', $best['detail']);
    }

    /** Le record est tous temps : il ne suit pas la dernière séance. */
    public function testBestSetLooksAcrossAllSessions(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise('Développé couché');

        $this->log($user, $exercise, new \DateTimeImmutable('2026-02-01'), [[SetType::NORMAL, 3, 110.0]]);
        $this->log($user, $exercise, new \DateTimeImmutable('2026-03-08'), [[SetType::NORMAL, 10, 70.0]]);

        $best = $this->history->bestSet($user, $exercise);
        $last = $this->history->lastPerformance($user, $exercise);

        self::assertNotNull($best);
        self::assertSame(110.0, $best['weightKg']);
        self::assertSame('2026-02-01', $best['date']->format('Y-m-d'));
        self::assertNotNull($last);
        self::assertSame('2026-03-08', $last['date']->format('Y-m-d'));
    }

    /** À charge égale, la série qui compte est celle qui fait le plus de répétitions. */
    public function testTiedRecordIsBrokenByReps(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise('Développé militaire');

        $this->log($user, $exercise, new \DateTimeImmutable('2026-02-01'), [[SetType::NORMAL, 3, 60.0]]);
        $this->log($user, $exercise, new \DateTimeImmutable('2026-03-01'), [[SetType::NORMAL, 6, 60.0]]);

        $best = $this->history->bestSet($user, $exercise);

        self::assertNotNull($best);
        self::assertSame(6, $best['reps']);
    }

    /** Une série au poids du corps ne fait pas un record : il n'y en a pas sans kilos. */
    public function testBodyweightSetsProduceNoRecord(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise('Gainage');

        $this->log($user, $exercise, new \DateTimeImmutable('2026-03-08'), [
            [SetType::NORMAL, null, null, 60],
            [SetType::NORMAL, null, null, 45],
        ]);

        self::assertNull($this->history->bestSet($user, $exercise));

        $last = $this->history->lastPerformance($user, $exercise);
        self::assertNotNull($last);
        // Sans reps, l'effort se lit en durée : le réalisé n'a pas de type de
        // prescription pour trancher, il porte ses valeurs.
        self::assertSame('1:00', $last['sets'][0]['effort']);
        self::assertSame(0.0, $last['tonnageKg']);
    }

    /** Un exercice sauté n'apporte rien, même s'il porte des séries abandonnées. */
    public function testSkippedExerciseIsIgnored(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise('Rowing');

        $this->log($user, $exercise, new \DateTimeImmutable('2026-03-08'), [[SetType::NORMAL, 8, 60.0]], skipped: true);

        self::assertNull($this->history->lastPerformance($user, $exercise));
        self::assertNull($this->history->bestSet($user, $exercise));
    }

    /** Un exercice de la biblio globale est pratiqué par tous : l'historique reste à chacun. */
    public function testHistoryIsScopedToItsOwner(): void
    {
        $mine = $this->createUser('me@example.com');
        $other = $this->createUser('other@example.com');
        $exercise = $this->createExercise('Développé couché'); // global, sans owner

        $this->log($mine, $exercise, new \DateTimeImmutable('2026-03-08'), [[SetType::NORMAL, 5, 90.0]]);
        $this->log($other, $exercise, new \DateTimeImmutable('2026-03-09'), [[SetType::NORMAL, 5, 150.0]]);

        $best = $this->history->bestSet($mine, $exercise);
        $last = $this->history->lastPerformance($mine, $exercise);

        self::assertNotNull($best);
        self::assertSame(90.0, $best['weightKg']);
        self::assertNotNull($last);
        self::assertSame('2026-03-08', $last['date']->format('Y-m-d'));
    }

    /** Deux séances le même jour : c'est la dernière écrite qui fait foi. */
    public function testTwoSessionsOnTheSameDayKeepTheLatest(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise('Traction');

        $this->log($user, $exercise, new \DateTimeImmutable('2026-03-08'), [[SetType::NORMAL, 8, 10.0]]);
        $evening = $this->log($user, $exercise, new \DateTimeImmutable('2026-03-08'), [[SetType::NORMAL, 6, 20.0]]);

        $last = $this->history->lastPerformance($user, $exercise);

        self::assertNotNull($last);
        self::assertSame($evening->getId(), $last['scheduledWorkoutId']);
        self::assertSame(1, $last['workingSets']);
        self::assertSame(20.0, $last['topWeightKg']);
    }

    /**
     * Le point dur du ticket : le bootstrap mobile appelle bulkFor sur toute la
     * bibliothèque. Deux requêtes, quel que soit le nombre d'exercices — sinon
     * l'endpoint est inutilisable.
     */
    public function testBulkForStaysAtTwoQueries(): void
    {
        $user = $this->createUser('owner@example.com');

        $exercises = [];
        for ($i = 1; $i <= 12; ++$i) {
            $exercise = $this->createExercise('Exercice '.$i);
            $exercises[] = $exercise;
            $this->log($user, $exercise, new \DateTimeImmutable('2026-03-0'.(1 + $i % 5)), [
                [SetType::NORMAL, 5, 50.0 + $i],
                [SetType::NORMAL, 5, 50.0 + $i],
            ]);
        }
        $orphan = $this->createExercise('Jamais fait');
        $exercises[] = $orphan;

        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $holder->reset();

        $bulk = $this->history->bulkFor($user, $exercises);

        $queries = $holder->getData()['default'] ?? [];
        self::assertCount(2, $queries, 'bulkFor doit tenir en deux requêtes, quel que soit le nombre d\'exercices.');

        // L'exercice jamais fait est ABSENT, pas présent à null.
        self::assertCount(12, $bulk);
        self::assertArrayNotHasKey($orphan->getId(), $bulk);

        $first = $bulk[$exercises[0]->getId()];
        self::assertNotNull($first['last']);
        self::assertNotNull($first['best']);
        self::assertSame(51.0, $first['best']['weightKg']);
        self::assertSame(2, $first['last']['workingSets']);
    }

    // --- KL-17 : la trajectoire ----------------------------------------------

    /**
     * Les séances les plus récentes d'abord, et **la première est exactement la
     * dernière performance** : c'est ce qui autorise `PerformanceHistoryPayload`
     * à dériver `last` de `sessions[0]` au lieu de le relire. Si les deux
     * lectures cessaient de s'accorder, ce test le dirait ici et pas sur le
     * téléphone.
     */
    public function testRecentSessionsAreOrderedFromTheMostRecent(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise('Développé couché');

        $this->log($user, $exercise, new \DateTimeImmutable('2026-03-01'), [[SetType::NORMAL, 8, 80.0]]);
        $this->log($user, $exercise, new \DateTimeImmutable('2026-03-08'), [[SetType::NORMAL, 8, 85.0]]);
        $this->log($user, $exercise, new \DateTimeImmutable('2026-03-15'), [
            [SetType::NORMAL, 6, 90.0],
            [SetType::NORMAL, 6, 90.0],
        ]);

        $sessions = $this->history->recentSessions($user, $exercise, 10);

        self::assertCount(3, $sessions);
        self::assertSame(
            ['2026-03-15', '2026-03-08', '2026-03-01'],
            array_map(static fn (array $s): string => $s['date']->format('Y-m-d'), $sessions),
        );
        self::assertSame(2, $sessions[0]['workingSets']);
        self::assertSame(1080.0, $sessions[0]['tonnageKg']);
        self::assertEquals($this->history->lastPerformance($user, $exercise), $sessions[0]);
    }

    /**
     * La borne est en SQL, pas en PHP : l'historique d'un exercice grossit sans
     * limite, ramener toutes ses séries pour n'en garder que dix séances
     * marcherait la première année. Deux requêtes, et bornées toutes les deux.
     */
    public function testRecentSessionsAreLimitedInTwoQueries(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise('Squat');

        for ($day = 1; $day <= 14; ++$day) {
            $this->log($user, $exercise, new \DateTimeImmutable(sprintf('2026-03-%02d', $day)), [
                [SetType::WARMUP, 10, 40.0],
                [SetType::NORMAL, 5, 100.0 + $day],
            ]);
        }

        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $holder->reset();

        $sessions = $this->history->recentSessions($user, $exercise, 10);

        self::assertCount(2, $holder->getData()['default'] ?? [], 'La trajectoire tient en deux requêtes, quel que soit l\'historique.');
        self::assertCount(10, $sessions);
        self::assertSame('2026-03-14', $sessions[0]['date']->format('Y-m-d'));
        self::assertSame('2026-03-05', $sessions[9]['date']->format('Y-m-d'));
        // Même périmètre que les deux autres lectures : l'échauffement n'est
        // jamais remonté, il ne compte pas comme une série de travail.
        self::assertSame(1, $sessions[0]['workingSets']);
    }

    /** Rien de fait, pas d'entrée creuse : une liste vide. */
    public function testRecentSessionsAreEmptyWithoutHistory(): void
    {
        $user = $this->createUser('owner@example.com');

        self::assertSame([], $this->history->recentSessions($user, $this->createExercise('Jamais fait'), 10));
    }

    /** Deux séances le même jour restent deux points, départagés par leur rang. */
    public function testTwoSessionsOnTheSameDayAreTwoPoints(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise('Traction');

        $this->log($user, $exercise, new \DateTimeImmutable('2026-03-08'), [[SetType::NORMAL, 8, 10.0]]);
        $this->log($user, $exercise, new \DateTimeImmutable('2026-03-08'), [[SetType::NORMAL, 6, 20.0]]);

        $sessions = $this->history->recentSessions($user, $exercise, 10);

        self::assertCount(2, $sessions);
        self::assertSame(20.0, $sessions[0]['topWeightKg']);
        self::assertSame(10.0, $sessions[1]['topWeightKg']);
    }

    /** L'isolation vaut pour la trajectoire comme pour le record (KL-50). */
    public function testRecentSessionsAreScopedToTheirOwner(): void
    {
        $mine = $this->createUser('me@example.com');
        $other = $this->createUser('other@example.com');
        $exercise = $this->createExercise('Développé couché'); // global, sans owner

        $this->log($mine, $exercise, new \DateTimeImmutable('2026-03-08'), [[SetType::NORMAL, 5, 90.0]]);
        $this->log($other, $exercise, new \DateTimeImmutable('2026-03-09'), [[SetType::NORMAL, 5, 150.0]]);

        $sessions = $this->history->recentSessions($mine, $exercise, 10);

        self::assertCount(1, $sessions);
        self::assertSame(90.0, $sessions[0]['topWeightKg']);
    }

    /** Un exercice fait mais jamais chargé a une dernière perf sans record. */
    public function testBulkKeepsExerciseWithoutRecord(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise('Gainage');
        $this->log($user, $exercise, new \DateTimeImmutable('2026-03-08'), [[SetType::NORMAL, null, null, 60]]);

        $bulk = $this->history->bulkFor($user, [$exercise]);

        self::assertArrayHasKey($exercise->getId(), $bulk);
        self::assertNotNull($bulk[$exercise->getId()]['last']);
        self::assertNull($bulk[$exercise->getId()]['best']);
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
        $exercise = (new Exercise())
            ->setName($name)
            ->setActivity(ActivityType::GYM);

        $this->em->persist($exercise);
        $this->em->flush();

        return $exercise;
    }

    /**
     * Une séance datée qui porte le réalisé d'un seul exercice.
     *
     * @param list<array{0: SetType, 1: int|null, 2: float|null, 3?: int|null}> $sets
     */
    private function log(User $owner, Exercise $exercise, \DateTimeImmutable $date, array $sets, bool $skipped = false): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle('Séance du '.$date->format('d/m'))
            ->setScheduledDate($date)
            ->setStatus(ScheduledStatus::DONE);

        $logged = (new LoggedExercise())
            ->setExercise($exercise)
            ->setExerciseName($exercise->getName())
            ->setPosition(1)
            ->setSkipped($skipped);

        $position = 0;
        foreach ($sets as $set) {
            $logged->addLoggedSet(
                (new LoggedSet())
                    ->setPosition(++$position)
                    ->setSetType($set[0])
                    ->setReps($set[1])
                    ->setWeightKg($set[2])
                    ->setDurationSeconds($set[3] ?? null)
            );
        }

        $scheduled->addLoggedExercise($logged);

        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }
}
