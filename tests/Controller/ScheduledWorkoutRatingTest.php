<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Coaching;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Enum\CoachingStatus;
use App\Enum\ScheduledStatus;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le ressenti saisi sur le web. Ce que ces tests tiennent : noter est
 * **consigner** (LOG, le propriétaire seul) et non programmer (EDIT, que le coach
 * possède) — la même frontière que le réalisé série par série, testée par ses
 * deux portes : l'endpoint dédié et le formulaire de statut que le coach poste.
 */
final class ScheduledWorkoutRatingTest extends WebTestCase
{
    use FindsEntities;
    use PurgesDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);
    }

    protected function tearDown(): void
    {
        $this->purgeDatabase(static::getContainer()->get('doctrine.orm.entity_manager'));

        parent::tearDown();
    }

    public function testTheOwnerRatesADoneSessionAndCanClearIt(): void
    {
        $owner = $this->createUser('athlete@example.com');
        $scheduled = $this->createDone($owner);
        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId());
        self::assertSelectorExists('#schedule-rating input[name="feeling"]');
        $form = $crawler->filter('#schedule-rating form')->form(['feeling' => '4', 'sessionRpe' => '8']);
        $this->client->submit($form);

        self::assertResponseRedirects('/schedule/'.$scheduled->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('#schedule-rating', 'En forme');
        self::assertSelectorTextContains('#schedule-rating', 'Difficile');

        $form = $this->client->getCrawler()->filter('#schedule-rating form')->form(['feeling' => '']);
        $this->client->submit($form);

        $this->em->clear();
        $stored = $this->mustFind(ScheduledWorkout::class, $scheduled->getId());
        self::assertNull($stored->getFeeling(), 'Vide = non renseigné : le web peut retirer une note.');
        self::assertSame(8, $stored->getSessionRpe());
    }

    public function testAValueOutOfScaleLeavesTheRatingAlone(): void
    {
        $owner = $this->createUser('athlete@example.com');
        $scheduled = $this->createDone($owner)->setFeeling(3);
        $this->em->flush();
        $this->client->loginUser($owner);

        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/rating', [
            '_token' => $this->token('rating', $scheduled),
            'feeling' => '9',
        ]);

        $this->em->clear();
        self::assertSame(3, $this->mustFind(ScheduledWorkout::class, $scheduled->getId())->getFeeling());
    }

    /** Le coach lit le ressenti de son athlète, il ne le dit pas à sa place. */
    public function testTheCoachReadsTheRatingButCannotWriteIt(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $coach = $this->createUser('coach@example.com');
        $this->em->persist((new Coaching())->setCoach($coach)->setAthlete($athlete)->setStatus(CoachingStatus::ACCEPTED));
        $scheduled = $this->createDone($athlete)->setFeeling(2)->setSessionRpe(9);
        $this->em->flush();

        $this->client->loginUser($coach);
        $this->client->request('GET', '/schedule/'.$scheduled->getId());
        self::assertSelectorTextContains('#schedule-rating', 'Faible');
        self::assertSelectorNotExists('#schedule-rating form');

        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/rating', ['_token' => 'x', 'feeling' => '5']);
        self::assertResponseStatusCodeSame(403);

        // Par le formulaire de statut, que le coach a le droit de poster : le
        // statut passe, le ressenti glissé dans la requête est ignoré.
        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/status', [
            '_token' => $this->token('status', $scheduled),
            'status' => 'done',
            'feeling' => '5',
            'sessionRpe' => '',
        ]);

        $this->em->clear();
        $stored = $this->mustFind(ScheduledWorkout::class, $scheduled->getId());
        self::assertSame(2, $stored->getFeeling());
        self::assertSame(9, $stored->getSessionRpe());
    }

    /** Pas de ressenti à saisir sur une séance qui n'a pas eu lieu. */
    public function testAPlannedSessionOffersNoRating(): void
    {
        $owner = $this->createUser('athlete@example.com');
        $scheduled = $this->createDone($owner)->setStatus(ScheduledStatus::PLANNED);
        $this->em->flush();
        $this->client->loginUser($owner);

        $this->client->request('GET', '/schedule/'.$scheduled->getId());

        self::assertSelectorExists('#schedule-rating[hidden]');
        self::assertSelectorNotExists('#schedule-rating form');
    }

    private function token(string $intent, ScheduledWorkout $scheduled): string
    {
        // Le jeton se lit sur la page, comme le ferait le navigateur : le
        // gestionnaire CSRF de test est lié à la session du client.
        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId());
        $action = 'rating' === $intent ? '/rating' : '/status';

        return (string) $crawler->filter(sprintf('form[action$="%s"] input[name="_token"]', $action))->attr('value');
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createDone(User $owner): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle('Haut du corps')
            ->setScheduledDate(new \DateTimeImmutable('2026-10-20'))
            ->setStatus(ScheduledStatus::DONE);
        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }
}
