<?php

namespace App\Tests\Controller;

use App\Entity\Exercise;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ScheduledStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Une séance datée SANS séance source. C'est le cas que le passage de
 * `ScheduledWorkout.workout` en ON DELETE SET NULL rend possible, et c'est ce
 * qui garde debout une séance réellement faite quand on nettoie la bibliothèque
 * (cf. docs/feature-live-tracking.md §2.3 point 1).
 *
 * Ces tests gardent la règle et, depuis KL-08, le seul point de mise en forme qui
 * en découle : la marque « sans programme » de la pastille. L'affichage du
 * réalisé, lui, vit dans ScheduledWorkoutLogTest (KL-07).
 */
final class ScheduledWorkoutSourcelessTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        foreach ($this->em->getRepository(ScheduledWorkout::class)->findAll() as $scheduled) {
            $this->em->remove($scheduled);
        }
        foreach ($this->em->getRepository(PlanTemplate::class)->findAll() as $template) {
            $this->em->remove($template);
        }
        foreach ($this->em->getRepository(Workout::class)->findAll() as $workout) {
            $this->em->remove($workout);
        }
        foreach ($this->em->getRepository(Exercise::class)->findAll() as $exercise) {
            $this->em->remove($exercise);
        }
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            $this->em->remove($user);
        }
        $this->em->flush();
    }

    /**
     * Le test qui garde le SET NULL : supprimer une séance de la bibliothèque
     * laisse la séance datée debout, avec son titre snapshot. En CASCADE, la
     * ligne disparaissait — et avec elle, demain, tout le réalisé qu'elle porte.
     */
    public function testDeletingLibraryWorkoutKeepsItsDatedSessions(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $scheduled = $this->createScheduled($user, $workout, new \DateTimeImmutable('2026-03-15'));
        $id = $scheduled->getId();

        $this->em->remove($workout);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(ScheduledWorkout::class)->find($id);
        self::assertNotNull($reloaded, 'La séance datée doit survivre à la suppression de sa source.');
        self::assertNull($reloaded->getWorkout());
        self::assertSame('Sortie longue', $reloaded->getDisplayTitle());
    }

    /** Le snapshot de titre se pose tout seul à la pose, pas à la main. */
    public function testTitleIsSnapshottedOnPersist(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Fractionné');
        $scheduled = $this->createScheduled($user, $workout, new \DateTimeImmutable('2026-03-15'));

        self::assertSame('Fractionné', $scheduled->getTitle());
        self::assertNotNull($scheduled->getUuid());
    }

    /** L'uuid sert de clé d'idempotence à l'API mobile : il doit être retrouvable. */
    public function testFindByUuid(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Fractionné');
        $scheduled = $this->createScheduled($user, $workout, new \DateTimeImmutable('2026-03-15'));
        $uuid = $scheduled->getUuid();

        $this->em->clear();

        $found = $this->em->getRepository(ScheduledWorkout::class)->findByUuid($uuid);
        self::assertNotNull($found);
        self::assertSame($scheduled->getId(), $found->getId());
    }

    /** Le calendrier la liste au lieu de la faire disparaître (jointure externe). */
    public function testCalendarRendersSessionWithoutSource(): void
    {
        $user = $this->createUser('owner@example.com');
        $scheduled = $this->createFree($user, 'Séance improvisée', new \DateTimeImmutable('2026-03-15'));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/calendar/2026/3');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Séance improvisée', $crawler->html());
        self::assertStringContainsString('/schedule/'.$scheduled->getId(), $crawler->html());
    }

    public function testWeekViewRendersSessionWithoutSource(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->createFree($user, 'Séance improvisée', new \DateTimeImmutable('2026-03-15'));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/calendar/week/2026-03-15');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Séance improvisée', $crawler->html());
    }

    /**
     * La marque « sans programme » ne se pose que sur les séances qui n'ont pas
     * de source, et sur les deux vues. Le test cadre un mois qui contient les
     * deux cas : sans le second, la marque pourrait être posée sur tout le monde
     * et le test passerait quand même.
     */
    public function testOnlySourcelessSessionsCarryTheFreeformMark(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        // Deux dates de la même semaine ISO (9→15 mars) : la vue semaine et la
        // vue mois cadrent alors toutes les deux les deux séances.
        $this->createScheduled($user, $workout, new \DateTimeImmutable('2026-03-14'));
        $this->createFree($user, 'Séance improvisée', new \DateTimeImmutable('2026-03-15'));

        $this->client->loginUser($user);

        foreach (['/calendar/2026/3', '/calendar/week/2026-03-15'] as $url) {
            $crawler = $this->client->request('GET', $url);

            self::assertResponseIsSuccessful();
            // Deux séances affichées, une seule marquée. Le compte se fait sur la
            // zone centrale de la pastille : la modale, qui vit à l'intérieur du
            // même `.kd-calevent`, porte la sienne et la doublerait.
            self::assertCount(2, $crawler->filter('.kd-calevent'), $url);
            self::assertCount(1, $crawler->filter('.kd-calevent__open .kd-freeform'), $url);
            self::assertCount(1, $crawler->filter('.kd-modal__meta .kd-freeform'), $url);
            self::assertSelectorTextContains('.kd-freeform', 'Libre');
        }
    }

    /**
     * Le flux Turbo qui re-rend la pastille après un changement de statut passe
     * par le même composant : la marque doit survivre au cycle, sinon elle
     * disparaîtrait au premier clic sur « fait ».
     */
    public function testFreeformMarkSurvivesTheStatusStream(): void
    {
        $user = $this->createUser('owner@example.com');
        $scheduled = $this->createFree($user, 'Séance improvisée', new \DateTimeImmutable('2026-03-15'));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/calendar/2026/3');

        $this->client->request(
            'POST',
            '/schedule/'.$scheduled->getId().'/cycle-status',
            ['_token' => $crawler->filter('.kd-calevent__statusform input[name="_token"]')->attr('value')],
            [],
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']
        );

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('kd-freeform', $this->client->getResponse()->getContent());
    }

    /** La page datée se rend : date, statut, titre — mais pas de programme. */
    public function testScheduledShowRendersWithoutSource(): void
    {
        $user = $this->createUser('owner@example.com');
        $scheduled = $this->createFree($user, 'Séance improvisée', new \DateTimeImmutable('2026-03-15'));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/schedule/'.$scheduled->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.kd-wk__title', 'Séance improvisée');
        self::assertSelectorTextContains('.kd-wk__date', 'Dimanche 15 mars 2026');
        self::assertSelectorTextContains('.kd-done', 'Marquer fait');
        self::assertStringNotContainsString('kd-wk__panel', $crawler->html());
    }

    /** L'export Excel l'ignore (aucun programme à écrire) sans planter. */
    public function testScheduleExportSkipsSessionWithoutSource(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->createFree($user, 'Séance improvisée', new \DateTimeImmutable('2026-03-15'));

        $this->client->loginUser($user);
        $this->client->request('GET', '/export/schedule/2026/3');

        self::assertResponseIsSuccessful();
    }

    /** Le flux ICS retombe sur le titre snapshot au lieu d'appeler null. */
    public function testIcsFeedFallsBackOnTitle(): void
    {
        $user = $this->createUser('owner@example.com');
        $user->setCalendarFeedToken(bin2hex(random_bytes(8)));
        $this->em->flush();
        $this->createFree($user, 'Séance improvisée', new \DateTimeImmutable('2026-03-15'));

        $this->client->request('GET', '/feed/'.$user->getCalendarFeedToken().'.ics');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Séance improvisée', $this->client->getResponse()->getContent());
    }

    private function createUser(string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())->setEmail($email);
        $user->setPassword($hasher->hashPassword($user, 'password'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createWorkout(User $owner, string $title): Workout
    {
        $workout = (new Workout())
            ->setOwner($owner)
            ->setTitle($title)
            ->setSlug(strtolower(str_replace(' ', '-', $title)));

        $this->em->persist($workout);
        $this->em->flush();

        return $workout;
    }

    private function createScheduled(User $owner, Workout $workout, \DateTimeImmutable $date): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setWorkout($workout)
            ->setScheduledDate($date)
            ->setStatus(ScheduledStatus::PLANNED);

        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }

    /** Séance datée sans source : le cas de la séance libre. */
    private function createFree(User $owner, string $title, \DateTimeImmutable $date): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle($title)
            ->setScheduledDate($date)
            ->setStatus(ScheduledStatus::PLANNED);

        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }
}
