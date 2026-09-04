<?php

namespace App\Tests\Service;

use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use App\Enum\TargetArea;
use App\Service\LogMetrics;
use App\Service\StatsPeriod;
use App\Service\TrainingLog;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le journal du réalisé.
 *
 * **Ce que ces tests protègent : que le journal dise d'une séance exactement ce
 * que `LogMetrics` en dit.** Ce sont les mêmes chiffres lus autrement — en
 * agrégats SQL au lieu d'entités hydratées, parce qu'une liste « depuis le
 * début » ne peut pas remonter l'historique série par série. Le jour où les deux
 * divergent, la fiche du coach et la page de séance affichent deux tonnages
 * différents pour la même séance.
 *
 * Les fixtures posent donc délibérément les trois cas frontière du projet :
 * l'échauffement (hors volume), l'exercice sauté (une information, pas du
 * volume) et la série cochée sans valeur (elle a eu lieu, elle ne mesure rien).
 */
final class TrainingLogTest extends KernelTestCase
{
    use PurgesDatabase;

    private EntityManagerInterface $em;
    private TrainingLog $log;
    private User $user;
    private Exercise $squat;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->log = static::getContainer()->get(TrainingLog::class);

        $this->purgeDatabase($this->em);

        $this->user = (new User())->setEmail('athlete-log@example.com')->setPassword('x');
        $this->em->persist($this->user);

        $this->squat = (new Exercise())
            ->setName('Squat')
            ->setActivity(ActivityType::GYM)
            ->setTargetAreas([TargetArea::QUADRICEPS]);
        $this->em->persist($this->squat);
        $this->em->flush();
    }

    /**
     * Le périmètre du volume, en une seule séance : deux séries de travail
     * comptées, l'échauffement et la série sans valeur écartées du chiffre, et
     * le tonnage qui n'additionne que ce qui portait une charge ET des reps.
     */
    public function testWorkingVolumeExcludesWarmupAndUnmeasuredSets(): void
    {
        $this->logSession('2026-03-08', [
            [SetType::WARMUP, 10, 40.0],
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 5, 100.0],
            // Cochée, sans valeur : elle a eu lieu, elle ne mesure rien — la
            // charge seule ne la sauve pas.
            [SetType::NORMAL, null, 140.0],
        ]);

        $entry = $this->log->recent($this->user, 10)[0];

        self::assertSame(2, $entry['workingSets']);
        self::assertSame(1000.0, $entry['tonnageKg']);
    }

    /**
     * Un exercice sauté est une information : il ne gonfle pas le compte
     * d'exercices, il se dit à part. Même règle que `LogMetrics::summary()`.
     */
    public function testASkippedExerciseIsCountedApartFromTheVolume(): void
    {
        $scheduled = $this->logSession('2026-03-08', [[SetType::NORMAL, 5, 100.0]]);

        $skipped = (new LoggedExercise())
            ->setExercise($this->squat)
            ->setExerciseName('Squat')
            ->setPosition(2)
            ->setSkipped(true);
        $scheduled->addLoggedExercise($skipped);
        $this->em->flush();

        $entry = $this->log->recent($this->user, 10)[0];

        self::assertSame(1, $entry['exercises']);
        self::assertSame(1, $entry['skipped']);
        self::assertSame(1, $entry['workingSets']);
    }

    /**
     * Une séance sans volume mesuré garde sa ligne : une mobilité consignée en
     * séries sans valeur a bien eu lieu. La faire disparaître effacerait un fait.
     */
    public function testASessionWithoutMeasuredVolumeKeepsItsLine(): void
    {
        $this->logSession('2026-03-08', [[SetType::NORMAL, null, null]]);

        $entries = $this->log->recent($this->user, 10);

        self::assertCount(1, $entries);
        self::assertSame(0, $entries[0]['workingSets']);
        self::assertSame(0.0, $entries[0]['tonnageKg']);
    }

    /**
     * Une séance simplement cochée « faite » n'entre pas au journal : le réalisé
     * est la condition, pas le statut.
     */
    public function testASessionTickedDoneWithoutALogIsAbsent(): void
    {
        $bare = (new ScheduledWorkout())
            ->setOwner($this->user)
            ->setTitle('Sortie longue')
            ->setScheduledDate(new \DateTimeImmutable('2026-03-09'))
            ->setStatus(ScheduledStatus::DONE);
        $this->em->persist($bare);
        $this->em->flush();

        self::assertSame([], $this->log->recent($this->user, 10));
        self::assertSame(0, $this->log->total($this->user));
    }

    /**
     * La limite compte des SÉANCES, pas des lignes de jointure : une séance à
     * plusieurs exercices ne doit pas en manger plusieurs.
     */
    public function testTheLimitCountsSessionsNotJoinedRows(): void
    {
        foreach (['2026-03-01', '2026-03-02', '2026-03-03'] as $date) {
            $scheduled = $this->logSession($date, [[SetType::NORMAL, 5, 100.0]]);
            $second = (new LoggedExercise())
                ->setExercise($this->squat)
                ->setExerciseName('Squat')
                ->setPosition(2);
            $second->addLoggedSet(
                (new LoggedSet())->setPosition(1)->setSetType(SetType::NORMAL)->setReps(5)->setWeightKg(60.0),
            );
            $scheduled->addLoggedExercise($second);
            $this->em->flush();
        }

        $entries = $this->log->recent($this->user, 2);

        self::assertCount(2, $entries);
        // La plus récente d'abord.
        self::assertSame('2026-03-03', $entries[0]['date']->format('Y-m-d'));
        self::assertSame(3, $this->log->total($this->user));
    }

    /**
     * Le titre suit la règle de l'entité : celui de la séance de bibliothèque
     * s'il y en a une, sinon le titre propre de la séance datée.
     */
    public function testTheTitleFollowsTheLibraryWorkoutWhenThereIsOne(): void
    {
        $workout = (new Workout())
            ->setOwner($this->user)
            ->setTitle('Séance 2 — Jambes')
            ->setSlug('seance-2-jambes-'.bin2hex(random_bytes(4)));
        $this->em->persist($workout);

        $scheduled = $this->logSession('2026-03-08', [[SetType::NORMAL, 5, 100.0]]);
        $scheduled->setWorkout($workout)->setTitle('Titre figé');
        $this->em->flush();

        self::assertSame('Séance 2 — Jambes', $this->log->recent($this->user, 10)[0]['title']);
    }

    /**
     * Le groupement mensuel porte ses sous-totaux, et l'ordre reste du plus
     * récent au plus ancien — d'un mois à l'autre comme à l'intérieur d'un mois.
     */
    public function testMonthlyGroupsCarryTheirSubtotalsInReverseOrder(): void
    {
        $this->logSession('2026-02-20', [[SetType::NORMAL, 5, 100.0]]);
        $this->logSession('2026-03-05', [[SetType::NORMAL, 5, 100.0]]);
        $this->logSession('2026-03-12', [[SetType::NORMAL, 10, 50.0]]);

        $groups = $this->log->monthly($this->user, StatsPeriod::allTime());

        self::assertCount(2, $groups);
        self::assertSame('2026-03', $groups[0]['key']);
        self::assertSame('mars 2026', $groups[0]['label']);
        self::assertSame(2, $groups[0]['sessions']);
        self::assertSame(2, $groups[0]['workingSets']);
        self::assertSame(1000.0, $groups[0]['tonnageKg']);
        self::assertSame('2026-02', $groups[1]['key']);
        self::assertSame(1, $groups[1]['sessions']);
    }

    /** La fenêtre borne la liste ; le total, lui, ne se laisse pas borner. */
    public function testTheWindowBoundsTheListButNotTheTotal(): void
    {
        $this->logSession('2024-01-15', [[SetType::NORMAL, 5, 80.0]]);
        $this->logSession('2026-03-08', [[SetType::NORMAL, 5, 100.0]]);

        $window = StatsPeriod::month(2026, 3);

        self::assertCount(1, $this->log->over($this->user, $window));
        self::assertSame(2, $this->log->total($this->user));
    }

    /** Le journal d'un autre n'entre jamais dans le mien. */
    public function testItIsScopedToItsOwner(): void
    {
        $other = (new User())->setEmail('autre@example.com')->setPassword('x');
        $this->em->persist($other);
        $this->em->flush();

        $this->logSession('2026-03-08', [[SetType::NORMAL, 5, 100.0]]);

        self::assertSame([], $this->log->recent($other, 10));
        self::assertSame(0, $this->log->total($other));
    }

    /**
     * La durée est celle des bornes d'exécution écrites par le mobile, et null
     * dès qu'il en manque une : une séance en cours n'a pas de durée qui
     * changerait à chaque rafraîchissement.
     */
    public function testDurationNeedsBothBounds(): void
    {
        $scheduled = $this->logSession('2026-03-08', [[SetType::NORMAL, 5, 100.0]]);
        $scheduled->setStartedAt(new \DateTimeImmutable('2026-03-08 18:00:00'));
        $this->em->flush();

        self::assertNull($this->log->recent($this->user, 10)[0]['durationSeconds']);

        $scheduled->setEndedAt(new \DateTimeImmutable('2026-03-08 19:30:00'));
        $this->em->flush();

        self::assertSame(5400, $this->log->recent($this->user, 10)[0]['durationSeconds']);
    }

    /**
     * L'invariant qui tient tout le reste : sur la MÊME séance, le journal et
     * `LogMetrics` disent la même chose. L'un est lu dans une liste, l'autre sur
     * la page de la séance — deux chiffres différents pour un même fait seraient
     * indéfendables.
     */
    public function testItAgreesWithLogMetricsOnTheSameSession(): void
    {
        $scheduled = $this->logSession('2026-03-08', [
            [SetType::WARMUP, 10, 40.0],
            [SetType::NORMAL, 5, 100.0],
            [SetType::NORMAL, 8, 90.0],
            [SetType::NORMAL, null, 140.0],
        ]);
        $scheduled->setStartedAt(new \DateTimeImmutable('2026-03-08 18:00:00'))
            ->setEndedAt(new \DateTimeImmutable('2026-03-08 19:30:00'));
        $this->em->flush();

        $summary = static::getContainer()->get(LogMetrics::class)->summary($scheduled);
        $entry = $this->log->recent($this->user, 10)[0];

        self::assertNotNull($summary);
        self::assertSame($summary['workingSets'], $entry['workingSets']);
        self::assertSame($summary['tonnageKg'], $entry['tonnageKg']);
        self::assertSame($summary['durationSeconds'], $entry['durationSeconds']);
        self::assertSame($summary['skipped'], $entry['skipped']);
        self::assertSame($scheduled->getDisplayTitle(), $entry['title']);
    }

    // ---------------------------------------------------------------- helpers

    /** @param list<array{0: SetType, 1: int|null, 2: float|null}> $sets */
    private function logSession(string $date, array $sets): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($this->user)
            ->setTitle('Séance du '.$date)
            ->setScheduledDate(new \DateTimeImmutable($date))
            ->setStatus(ScheduledStatus::DONE);

        $logged = (new LoggedExercise())
            ->setExercise($this->squat)
            ->setExerciseName('Squat')
            ->setPosition(1);

        $position = 0;
        foreach ($sets as $set) {
            $logged->addLoggedSet(
                (new LoggedSet())
                    ->setPosition(++$position)
                    ->setSetType($set[0])
                    ->setReps($set[1])
                    ->setWeightKg($set[2]),
            );
        }

        $scheduled->addLoggedExercise($logged);
        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }
}
