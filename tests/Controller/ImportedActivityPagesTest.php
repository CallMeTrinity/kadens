<?php

namespace App\Tests\Controller;

use App\Entity\Coaching;
use App\Entity\ImportedActivity;
use App\Entity\IntervalsConnection;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Enum\ActivitySource;
use App\Enum\ActivityType;
use App\Enum\CoachingStatus;
use App\Enum\ScheduledStatus;
use App\Service\SecretBox;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les écrans de l'import d'activités : la carte Intervals.icu de
 * `/profile/settings` et le réalisé cardio de `/schedule/{id}`.
 *
 * Aucun appel réseau ici : connecter une clé en demande un, il est couvert par
 * `IntervalsImporterTest` avec un transport simulé. Ce qui est protégé ici, ce
 * sont les **droits** — rattacher, c'est consigner (LOG), donc le coach lit et
 * ne rattache pas — et le fait qu'aucune action ne se passe de CSRF.
 */
final class ImportedActivityPagesTest extends WebTestCase
{
    use PurgesDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);
    }

    public function testSettingsOffersToConnectWhenNothingIsConnected(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));
        $crawler = $this->client->request('GET', '/profile/settings');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#intervals-panel form[action="/profile/intervals/connect"]'));
        self::assertCount(0, $crawler->filter('#intervals-panel form[action="/profile/intervals/sync"]'));
    }

    /** Connecté : la clé n'est jamais réaffichée, même scellée. */
    public function testSettingsShowsTheConnectionWithoutEverPrintingTheKey(): void
    {
        $user = $this->createUser('athlete@example.com');
        $connection = $this->connect($user);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/profile/settings');

        self::assertStringContainsString('Coureur Grenoblois', $crawler->filter('#intervals-panel')->text());
        self::assertCount(1, $crawler->filter('#intervals-panel form[action="/profile/intervals/sync"]'));
        self::assertStringNotContainsString($connection->getSealedApiKey(), $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('cle-secrete', $this->client->getResponse()->getContent());
    }

    public function testConnectingWithoutACsrfTokenIsRefused(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));
        $this->client->request('POST', '/profile/intervals/connect', ['apiKey' => 'x']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->em->getRepository(IntervalsConnection::class)->findAll());
    }

    /** Déconnecter garde les activités, sauf demande explicite. */
    public function testDisconnectingKeepsActivitiesUnlessAskedToPurge(): void
    {
        $user = $this->createUser('athlete@example.com');
        $this->connect($user);
        $this->activity($user, 'i1');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/profile/settings');
        $token = $crawler->filter('form[action="/profile/intervals/disconnect"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/profile/intervals/disconnect', ['_token' => $token]);
        self::assertResponseRedirects('/profile/settings#intervals-panel');
        self::assertSame([], $this->em->getRepository(IntervalsConnection::class)->findAll());
        self::assertCount(1, $this->em->getRepository(ImportedActivity::class)->findAll());

        $this->client->request('POST', '/profile/intervals/disconnect', ['_token' => $token, 'purge' => '1']);
        self::assertSame([], $this->em->getRepository(ImportedActivity::class)->findAll());
    }

    public function testTheOwnerAttachesAnActivityOfTheDayAndTheSessionBecomesDone(): void
    {
        $user = $this->createUser('athlete@example.com');
        $scheduled = $this->scheduled($user, '2026-09-12');
        $activity = $this->activity($user, 'i1');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId());

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/schedule/'.$scheduled->getId().'/activity/'.$activity->getId().'/attach"]');
        self::assertCount(1, $form);

        $this->client->request('POST', $form->attr('action'), ['_token' => $form->filter('input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects('/schedule/'.$scheduled->getId());

        $this->em->clear();
        $reloaded = $this->em->find(ImportedActivity::class, $activity->getId());
        self::assertSame($scheduled->getId(), $reloaded->getScheduledWorkout()?->getId());
        self::assertSame(ScheduledStatus::DONE, $reloaded->getScheduledWorkout()->getStatus());

        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId());
        self::assertStringContainsString('10 km', $crawler->filter('.kd-actimport')->text());
    }

    /**
     * Le coach lit le réalisé importé de son athlète (VIEW) mais ne choisit pas
     * quelle sortie il a courue (LOG) : ni bouton, ni route.
     */
    public function testTheCoachReadsTheActivityButCannotAttachOrDetach(): void
    {
        $coach = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $athlete = $this->createUser('athlete@example.com');
        $this->em->persist((new Coaching())->setCoach($coach)->setAthlete($athlete)->setStatus(CoachingStatus::ACCEPTED)->setRequestedBy($coach)->setRespondedAt(new \DateTimeImmutable()));

        $scheduled = $this->scheduled($athlete, '2026-09-12');
        $attached = $this->activity($athlete, 'i1')->setScheduledWorkout($scheduled);
        $free = $this->activity($athlete, 'i2');
        $this->em->flush();

        $this->client->loginUser($coach);
        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.kd-actimport__item'));
        self::assertCount(0, $crawler->filter('.kd-actimport form'));

        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/activity/'.$free->getId().'/attach');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/activity/'.$attached->getId().'/detach');
        self::assertResponseStatusCodeSame(403);
    }

    /** L'activité d'un autre compte rend 404 : le refus ne confirme pas qu'elle existe. */
    public function testAttachingSomeoneElsesActivityIsNotFound(): void
    {
        $user = $this->createUser('athlete@example.com');
        $scheduled = $this->scheduled($user, '2026-09-12');
        $foreign = $this->activity($this->createUser('autre@example.com'), 'i9');

        $this->client->loginUser($user);
        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/activity/'.$foreign->getId().'/attach');

        self::assertResponseStatusCodeSame(404);
    }

    private function createUser(string $email, array $roles = []): User
    {
        $user = (new User())->setEmail($email)->setRoles($roles)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function connect(User $user): IntervalsConnection
    {
        $connection = new IntervalsConnection($user, static::getContainer()->get(SecretBox::class)->seal('cle-secrete'), 'Coureur Grenoblois');
        $this->em->persist($connection);
        $this->em->flush();

        return $connection;
    }

    private function scheduled(User $owner, string $date): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle('Sortie longue')
            ->setScheduledDate(new \DateTimeImmutable($date))
            ->setStatus(ScheduledStatus::PLANNED);
        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }

    private function activity(User $owner, string $externalId): ImportedActivity
    {
        $activity = (new ImportedActivity($owner, ActivitySource::INTERVALS, $externalId, 'Run', new \DateTimeImmutable('2026-09-12 07:00'), new \DateTimeImmutable('2026-09-12')))
            ->setActivity(ActivityType::RUNNING)
            ->setName('Footing du matin')
            ->setDistanceMeters(10000)
            ->setMovingSeconds(3000);
        $this->em->persist($activity);
        $this->em->flush();

        return $activity;
    }
}
