<?php

namespace App\Tests\Controller;

use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ScheduledStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CalendarControllerTest extends WebTestCase
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
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            $this->em->remove($user);
        }
        $this->em->flush();
    }

    public function testIndexRedirectsWhenAnonymous(): void
    {
        $this->client->request('GET', '/calendar');

        self::assertResponseRedirects('/login');
    }

    public function testMonthRendersWithScheduledWorkout(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $this->createScheduled($user, $workout, new \DateTimeImmutable('2026-03-15'));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/calendar/2026/3');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mars 2026');
        self::assertStringContainsString('Sortie longue', $crawler->html());
    }

    public function testAddPlacesIsolatedWorkoutOnDate(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Fractionné');

        $this->client->loginUser($user);
        $this->client->request('GET', '/calendar/2026/3');

        $this->client->submitForm('Planifier', [
            'schedule_workout[workout]' => $workout->getId(),
            'schedule_workout[scheduledDate]' => '2026-03-10',
        ]);

        $this->em->clear();
        $scheduled = $this->em->getRepository(ScheduledWorkout::class)->findOneBy([]);
        self::assertNotNull($scheduled);
        self::assertSame('2026-03-10', $scheduled->getScheduledDate()->format('Y-m-d'));
        self::assertSame(ScheduledStatus::PLANNED, $scheduled->getStatus());
        self::assertSame($workout->getId(), $scheduled->getWorkout()->getId());
        self::assertResponseRedirects('/calendar/2026/3');
    }

    public function testInstantiatePlanCreatesDatedWorkouts(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $template = $this->createPlanTemplate($user, 'Plan 5k', 2);

        // Semaine 1, mercredi (jour ISO 3).
        $item = (new PlanItem())->setWeekNumber(1)->setDayOfWeek(3)->setWorkout($workout);
        $template->addPlanItem($item);
        $this->em->persist($item);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/calendar/2026/1');

        // 2026-01-07 est un mercredi -> ancre lundi 2026-01-05, item tombe le 07.
        $this->client->submitForm('Instancier', [
            'plan_instantiation[planTemplate]' => $template->getId(),
            'plan_instantiation[startDate]' => '2026-01-07',
        ]);

        $this->em->clear();
        $scheduled = $this->em->getRepository(ScheduledWorkout::class)->findAll();
        self::assertCount(1, $scheduled);
        self::assertSame('2026-01-07', $scheduled[0]->getScheduledDate()->format('Y-m-d'));
        self::assertNotNull($scheduled[0]->getSourcePlanTemplate());
    }

    public function testMoveChangesDate(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $scheduled = $this->createScheduled($user, $workout, new \DateTimeImmutable('2026-03-15'));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/calendar/2026/3');

        // On soumet le vrai formulaire de déplacement rendu (token + session).
        $form = $crawler->filter('form[action*="/move"]')->form();
        $form['scheduledDate'] = '2026-03-20';
        $this->client->submit($form);

        $this->em->clear();
        $moved = $this->em->getRepository(ScheduledWorkout::class)->find($scheduled->getId());
        self::assertSame('2026-03-20', $moved->getScheduledDate()->format('Y-m-d'));
        self::assertResponseRedirects('/calendar/2026/3');
    }

    public function testDeleteRemovesScheduledWorkout(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $scheduled = $this->createScheduled($user, $workout, new \DateTimeImmutable('2026-03-15'));
        $id = $scheduled->getId();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/calendar/2026/3');

        $form = $crawler->filter('form[action*="/delete"]')->form();
        $this->client->submit($form);

        $this->em->clear();
        self::assertNull($this->em->getRepository(ScheduledWorkout::class)->find($id));
    }

    public function testMoveDeniedToNonOwner(): void
    {
        $owner = $this->createUser('owner@example.com');
        $intruder = $this->createUser('intruder@example.com');
        $workout = $this->createWorkout($owner, 'Sortie longue');
        $scheduled = $this->createScheduled($owner, $workout, new \DateTimeImmutable('2026-03-15'));

        // Le voter tranche avant la vérification CSRF : un token bidon suffit à
        // prouver le refus d'accès (403) sur une séance qui n'est pas la sienne.
        $this->client->loginUser($intruder);
        $this->client->request('POST', '/schedule/'.$scheduled->getId().'/move', [
            '_token' => 'invalid',
            'scheduledDate' => '2026-03-20',
        ]);

        self::assertResponseStatusCodeSame(403);
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

    private function createPlanTemplate(User $owner, string $title, int $weeks): PlanTemplate
    {
        $template = (new PlanTemplate())
            ->setOwner($owner)
            ->setTitle($title)
            ->setDurationWeeks($weeks)
            ->setSlug(strtolower(str_replace(' ', '-', $title)));

        $this->em->persist($template);
        $this->em->flush();

        return $template;
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
}
