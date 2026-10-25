<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ImportedActivity;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivitySource;
use App\Enum\ScheduledStatus;
use App\Repository\ScheduledWorkoutRepository;
use App\Service\SessionRating;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La valeur effective du ressenti : Kadens d'abord, la montre sinon, calculée à
 * la lecture. Ce que ces tests protègent est ce qui rend la recopie inutile —
 * détacher une activité retire sa note sans rien écrire — et le choix du pire
 * quand la montre a produit plusieurs enregistrements.
 */
final class ScheduledWorkoutRatingsTest extends KernelTestCase
{
    use PurgesDatabase;

    private EntityManagerInterface $em;
    private ScheduledWorkoutRepository $repository;
    private User $user;
    private int $sequence = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->repository = static::getContainer()->get(ScheduledWorkoutRepository::class);
        $this->purgeDatabase($this->em);

        $this->user = (new User())->setEmail('athlete@example.com')->setPassword('x');
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->purgeDatabase($this->em);

        parent::tearDown();
    }

    public function testTheKadensRatingWinsOverTheWatch(): void
    {
        $scheduled = $this->done('2026-10-01')->setFeeling(2)->setSessionRpe(8);
        $this->activity($scheduled, feeling: 5, rpe: 3);
        $this->em->flush();

        $rating = $this->ratingOf($scheduled);

        self::assertSame(2, $rating->feeling);
        self::assertSame(8, $rating->sessionRpe);
        self::assertFalse($rating->fromIntervals);
    }

    public function testEachAxisFallsBackToTheWatchOnItsOwn(): void
    {
        $scheduled = $this->done('2026-10-01')->setFeeling(4);
        $this->activity($scheduled, feeling: 1, rpe: 6);
        $this->em->flush();

        $rating = $this->ratingOf($scheduled);

        self::assertSame(4, $rating->feeling);
        self::assertSame(6, $rating->sessionRpe);
        self::assertTrue($rating->fromIntervals);
    }

    /** Une montre relancée en cours de sortie : on garde le pire des deux. */
    public function testSeveralActivitiesKeepTheWorstOfEach(): void
    {
        $scheduled = $this->done('2026-10-01');
        $this->activity($scheduled, feeling: 4, rpe: 5);
        $this->activity($scheduled, feeling: 2, rpe: 7);
        $this->em->flush();

        $rating = $this->ratingOf($scheduled);

        self::assertSame(2, $rating->feeling);
        self::assertSame(7, $rating->sessionRpe);
    }

    public function testDetachingTheActivityTakesItsRatingAway(): void
    {
        $scheduled = $this->done('2026-10-01');
        $activity = $this->activity($scheduled, feeling: 3, rpe: 6);
        $this->em->flush();

        $activity->setScheduledWorkout(null);
        $this->em->flush();

        self::assertSame([], $this->repository->ratingsFor([(int) $scheduled->getId()]));
    }

    /** Une note survit à un statut repassé à « prévue », sans s'afficher. */
    public function testOnlyADoneSessionAnswers(): void
    {
        $planned = $this->done('2026-10-01')->setFeeling(3)->setStatus(ScheduledStatus::PLANNED);
        $this->em->flush();

        self::assertSame([], $this->repository->ratingsFor([(int) $planned->getId()]));
        self::assertSame(3, $planned->getFeeling());
    }

    public function testTheStatsRowsCountUnratedSessionsToo(): void
    {
        $this->done('2026-10-01')->setFeeling(4);
        $this->done('2026-10-02');
        $this->done('2026-09-01')->setSessionRpe(9);
        $this->em->flush();

        $rows = $this->repository->doneRatingsForOwner($this->user, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));

        self::assertCount(2, $rows);
        self::assertSame(4, $rows[0]['rating']->feeling);
        self::assertTrue($rows[1]['rating']->isEmpty());
    }

    public function testTheLineageKeepsTheLastRatedExecutionsFirst(): void
    {
        $root = (new Workout())->setOwner($this->user)->setTitle('Upper A')->setSlug('upper-a');
        $copy = (new Workout())->setOwner($this->user)->setTitle('Upper A')->setSlug('upper-a-s2')->setPlanLocal(true)->setOrigin($root);
        $this->em->persist($root);
        $this->em->persist($copy);

        $this->done('2026-10-01', $root)->setFeeling(2);
        $this->done('2026-10-08', $copy)->setFeeling(3);
        $this->done('2026-10-15', $copy);
        $this->done('2026-10-22', $copy)->setFeeling(5);
        $this->em->flush();

        $byRoot = $this->repository->recentRatingsByLineage($this->user, limit: 2);
        self::assertArrayHasKey((int) $root->getId(), $byRoot);
        $lineage = $byRoot[(int) $root->getId()];

        self::assertSame([5, 3], array_map(static fn (array $entry): ?int => $entry['rating']->feeling, $lineage));
    }

    private function ratingOf(ScheduledWorkout $scheduled): SessionRating
    {
        $id = (int) $scheduled->getId();
        $ratings = $this->repository->ratingsFor([$id]);
        self::assertArrayHasKey($id, $ratings);

        return $ratings[$id];
    }

    private function done(string $date, ?Workout $workout = null): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($this->user)
            ->setWorkout($workout)
            ->setTitle('Sortie')
            ->setScheduledDate(new \DateTimeImmutable($date))
            ->setStatus(ScheduledStatus::DONE);
        $this->em->persist($scheduled);

        return $scheduled;
    }

    private function activity(ScheduledWorkout $scheduled, ?int $feeling, ?int $rpe): ImportedActivity
    {
        $date = $scheduled->getScheduledDate();
        $activity = (new ImportedActivity($this->user, ActivitySource::INTERVALS, 'i'.++$this->sequence, 'Run', $date, $date))
            ->setScheduledWorkout($scheduled)
            ->setFeeling($feeling)
            ->setSessionRpe($rpe);
        $this->em->persist($activity);

        return $activity;
    }
}
