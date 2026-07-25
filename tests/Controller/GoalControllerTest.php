<?php

namespace App\Tests\Controller;

use App\Entity\Exercise;
use App\Entity\Goal;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\GoalPriority;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class GoalControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        foreach ($this->em->getRepository(Goal::class)->findAll() as $goal) {
            $this->em->remove($goal);
        }
        foreach ($this->em->getRepository(ScheduledWorkout::class)->findAll() as $scheduled) {
            $this->em->remove($scheduled);
        }
        foreach ($this->em->getRepository(PlanTemplate::class)->findAll() as $template) {
            $this->em->remove($template);
        }
        foreach ($this->em->getRepository(Workout::class)->findAll() as $workout) {
            $this->em->remove($workout);
        }
        // Exercices laissés par d'autres classes de test : référencent user, à
        // purger avant les utilisateurs (FK).
        foreach ($this->em->getRepository(Exercise::class)->findAll() as $exercise) {
            $this->em->remove($exercise);
        }
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            $this->em->remove($user);
        }
        $this->em->flush();
    }

    public function testIndexRedirectsWhenAnonymous(): void
    {
        $this->client->request('GET', '/goal');

        self::assertResponseRedirects('/login');
    }

    public function testIndexRendersUpcomingGoal(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->createGoal($user, 'Trail des Templiers', new \DateTimeImmutable('+30 days'));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/goal');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Trail des Templiers', $crawler->html());
    }

    public function testNewCreatesGoal(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/goal/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Créer l\'objectif')->form();
        $form['goal[title]'] = 'Marathon de Lyon';
        $form['goal[targetDate]'] = (new \DateTimeImmutable('+90 days'))->format('Y-m-d');
        $form['goal[priority]'] = GoalPriority::A->value;
        $this->client->submit($form);

        self::assertResponseRedirects();
        $goal = $this->em->getRepository(Goal::class)->findOneBy(['title' => 'Marathon de Lyon']);
        self::assertNotNull($goal);
        self::assertSame($user->getId(), $goal->getOwner()->getId());
    }

    public function testShowIsForbiddenForNonOwner(): void
    {
        $owner = $this->createUser('owner@example.com');
        $intruder = $this->createUser('intruder@example.com');
        $goal = $this->createGoal($owner, 'Objectif privé', new \DateTimeImmutable('+10 days'));

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/goal/'.$goal->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteRemovesGoal(): void
    {
        $user = $this->createUser('owner@example.com');
        $goal = $this->createGoal($user, 'À supprimer', new \DateTimeImmutable('+5 days'));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/goal/'.$goal->getId());
        $this->client->submit($crawler->selectButton('Supprimer')->form());

        self::assertResponseRedirects('/goal');
        self::assertNull($this->em->getRepository(Goal::class)->find($goal->getId()));
    }

    public function testPrepareAnchorsPlanLastWeekOnGoal(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        // Plan 2 semaines, une séance en semaine 2, lundi (jour ISO 1).
        $template = $this->createPlanTemplate($user, 'Plan 2 sem', 2);
        $this->createPlanItem($template, $workout, 2, 1);

        // Échéance future (la section « préparer » n'apparaît que si elle n'est
        // pas passée). Lundi ISO de la semaine de l'échéance = cible attendue.
        $target = new \DateTimeImmutable('+60 days');
        $goalMonday = $target->setTime(0, 0)->modify(sprintf('-%d days', (int) $target->format('N') - 1));
        $goal = $this->createGoal($user, 'Course cible', $target);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/goal/'.$goal->getId());

        $form = $crawler->selectButton('Ancrer sur l\'échéance')->form();
        $form['planTemplate'] = (string) $template->getId();
        $this->client->submit($form);

        self::assertResponseRedirects('/goal/'.$goal->getId());

        $scheduled = $this->em->getRepository(ScheduledWorkout::class)->findAll();
        self::assertCount(1, $scheduled);
        // Dernière semaine (semaine 2, lundi) calée sur la semaine de l'échéance.
        self::assertSame($goalMonday->format('Y-m-d'), $scheduled[0]->getScheduledDate()->format('Y-m-d'));
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

    private function createGoal(User $owner, string $title, \DateTimeImmutable $date): Goal
    {
        $goal = (new Goal())
            ->setOwner($owner)
            ->setTitle($title)
            ->setTargetDate($date)
            ->setPriority(GoalPriority::A);

        $this->em->persist($goal);
        $this->em->flush();

        return $goal;
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

    private function createPlanItem(PlanTemplate $template, Workout $workout, int $week, int $day): PlanItem
    {
        $item = (new PlanItem())
            ->setPlanTemplate($template)
            ->setWorkout($workout)
            ->setWeekNumber($week)
            ->setDayOfWeek($day);

        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }
}
