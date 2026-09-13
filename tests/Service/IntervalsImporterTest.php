<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\ImportedActivity;
use App\Entity\IntervalsConnection;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Repository\ImportedActivityRepository;
use App\Repository\IntervalsConnectionRepository;
use App\Service\ActivityMatcher;
use App\Service\ActivityStreamAnalyzer;
use App\Service\HeartRateZones;
use App\Service\ImportedActivityMapper;
use App\Service\IntervalsAuthException;
use App\Service\IntervalsClient;
use App\Service\IntervalsImporter;
use App\Service\SecretBox;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * La synchronisation Intervals.icu, contre une vraie base et un faux Intervals.
 *
 * Seul le transport HTTP est simulé (`MockHttpClient`) : le client, le mapping,
 * l'analyse des streams, le rapprochement et l'écriture sont les vrais. Ce que
 * ces tests protègent : le rapprochement **unique ou rien**, l'**idempotence**
 * (relancer ne réimporte rien et ne redemande aucun stream), et la fenêtre.
 */
final class IntervalsImporterTest extends KernelTestCase
{
    use PurgesDatabase;

    private EntityManagerInterface $em;
    private User $user;

    /** @var list<string> */
    private array $requested = [];

    private int $status = 200;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);

        $this->user = (new User())->setEmail('coureur@example.com')->setPassword('x')->setMaxHeartRate(190)->setRestingHeartRate(50);
        $this->em->persist($this->user);

        $run = (new Exercise())->setOwner($this->user)->setName('Footing')->setActivity(ActivityType::RUNNING);
        $this->em->persist($run);

        // Le 12 : une seule séance course prévue, la candidate évidente.
        $this->schedule($this->runWorkout($run), '2026-09-12');
        // Le 13 : deux séances course prévues, l'import ne doit pas choisir.
        $this->schedule($this->runWorkout($run), '2026-09-13');
        $this->schedule($this->runWorkout($run), '2026-09-13');

        $secretBox = static::getContainer()->get(SecretBox::class);
        $this->em->persist(new IntervalsConnection($this->user, $secretBox->seal('cle-de-test'), 'Coureur'));
        $this->em->flush();
    }

    public function testFirstSyncImportsAttachesWhenUnambiguousAndSkipsStravaStubs(): void
    {
        $report = $this->importer()->sync($this->user, new \DateTimeImmutable('2026-09-14'));

        self::assertSame(3, $report->imported);
        self::assertSame(1, $report->attached);
        self::assertSame(1, $report->skipped);
        self::assertSame(0, $report->remaining);

        // Première synchro : les 30 derniers jours.
        self::assertStringContainsString('oldest=2026-08-15', $this->requested[0]);

        $this->em->clear();
        $activities = $this->em->getRepository(ImportedActivity::class);

        $morningRun = $activities->findOneBy(['externalId' => 'i1']);
        self::assertNotNull($morningRun->getScheduledWorkout());
        self::assertSame(ScheduledStatus::DONE, $morningRun->getScheduledWorkout()->getStatus(), 'Rattacher prouve que la séance a eu lieu.');
        self::assertSame([0, 10, 0, 10, 0], $morningRun->getHrZoneSeconds());

        self::assertNull($activities->findOneBy(['externalId' => 'i2'])->getScheduledWorkout(), 'Deux candidates : on ne devine pas.');
        self::assertNull($activities->findOneBy(['externalId' => 'i4'])->getScheduledWorkout(), 'Un type non reconnu ne se rattache jamais seul.');
        self::assertNull($activities->findOneBy(['externalId' => 'i3']), 'Coquille Strava : rien à importer.');

        $connection = static::getContainer()->get(IntervalsConnectionRepository::class)->findForOwner($this->em->find(User::class, $this->user->getId()));
        self::assertSame('2026-09-14', $connection->getSyncedThrough()->format('Y-m-d'));
    }

    public function testSyncingAgainImportsNothingAndFetchesNoStream(): void
    {
        $this->importer()->sync($this->user, new \DateTimeImmutable('2026-09-14'));
        $streamsBefore = \count(array_filter($this->requested, static fn (string $url): bool => str_contains($url, 'streams')));

        $this->em->clear();
        $user = $this->em->find(User::class, $this->user->getId());
        $report = $this->importer()->sync($user, new \DateTimeImmutable('2026-09-14'));

        self::assertSame(0, $report->imported);
        self::assertSame($streamsBefore, \count(array_filter($this->requested, static fn (string $url): bool => str_contains($url, 'streams'))));
        self::assertCount(3, $this->em->getRepository(ImportedActivity::class)->findAll());

        // Fenêtre glissante : la seconde synchro relit depuis syncedThrough − 7 jours.
        self::assertStringContainsString('oldest=2026-09-07', $this->requested[\count($this->requested) - 1]);
    }

    public function testARefusedKeySurfacesAsAnAuthError(): void
    {
        $this->status = 401;

        $this->expectException(IntervalsAuthException::class);
        $this->importer()->sync($this->user, new \DateTimeImmutable('2026-09-14'));
    }

    private function importer(): IntervalsImporter
    {
        $container = static::getContainer();
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requested[] = $url;

            $auth = $options['normalized_headers']['authorization'][0] ?? '';
            self::assertSame('Authorization: Basic '.base64_encode('API_KEY:cle-de-test'), $auth);

            if (200 !== $this->status) {
                return new MockResponse('{}', ['http_code' => $this->status]);
            }

            if (str_contains($url, '/streams.json')) {
                return new MockResponse(json_encode([
                    ['type' => 'time', 'data' => range(0, 20)],
                    ['type' => 'heartrate', 'data' => array_merge([140], array_fill(0, 10, 140), array_fill(0, 10, 170))],
                ]));
            }

            // Du plus récent au plus ancien, comme l'API.
            return new MockResponse(json_encode([
                ['id' => 'i4', 'source' => 'GARMIN_CONNECT', 'type' => 'WeightTraining', 'start_date_local' => '2026-09-12T18:00:00', 'moving_time' => 2400],
                ['id' => 'i3', 'source' => 'STRAVA', 'type' => 'Run', 'start_date_local' => '2026-09-13T12:00:00'],
                ['id' => 'i2', 'source' => 'GARMIN_CONNECT', 'type' => 'Run', 'start_date_local' => '2026-09-13T08:00:00', 'distance' => 8000, 'moving_time' => 2700],
                ['id' => 'i1', 'source' => 'GARMIN_CONNECT', 'type' => 'Run', 'start_date_local' => '2026-09-12T07:00:00', 'start_date' => '2026-09-12T05:00:00Z', 'distance' => 10000, 'moving_time' => 3000, 'average_heartrate' => 150],
            ]));
        }, 'https://intervals.icu/api/v1/');

        return new IntervalsImporter(
            $container->get(IntervalsConnectionRepository::class),
            $container->get(ImportedActivityRepository::class),
            new IntervalsClient($http),
            $container->get(SecretBox::class),
            new ImportedActivityMapper(),
            new ActivityStreamAnalyzer(new HeartRateZones()),
            $container->get(ActivityMatcher::class),
            $this->em,
        );
    }

    private function runWorkout(Exercise $run): Workout
    {
        $workout = (new Workout())->setOwner($this->user)->setTitle('Footing')->setSlug('footing-'.bin2hex(random_bytes(4)));
        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $block->addPrescribedExercise(
            (new PrescribedExercise())
                ->setExercise($run)
                ->setPosition(0)
                ->setPrescriptionType(PrescriptionType::DISTANCE_PACE)
                ->setDistanceMeters(10000),
        );
        $workout->addBlock($block);
        $this->em->persist($workout);
        $this->em->persist($block);

        return $workout;
    }

    private function schedule(Workout $workout, string $date): void
    {
        $this->em->persist(
            (new ScheduledWorkout())
                ->setOwner($this->user)
                ->setWorkout($workout)
                ->setScheduledDate(new \DateTimeImmutable($date))
                ->setStatus(ScheduledStatus::PLANNED),
        );
    }
}
