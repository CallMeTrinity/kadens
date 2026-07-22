<?php

namespace App\Tests\Controller;

use App\Entity\Exercise;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\User;
use App\Entity\Workout;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PlanTemplateControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

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

    public function testIndexRedirectsWhenAnonymous(): void
    {
        $this->client->request('GET', '/plan-template');

        self::assertResponseRedirects('/login');
    }

    public function testCreatePlanGeneratesSlug(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/plan-template/new');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Créer', [
            'plan_template[title]' => 'Plan 5k 8 semaines',
            'plan_template[durationWeeks]' => '8',
        ]);

        $created = $this->em->getRepository(PlanTemplate::class)->findOneBy(['title' => 'Plan 5k 8 semaines']);
        self::assertNotNull($created);
        self::assertSame('plan-5k-8-semaines', $created->getSlug());
        self::assertNotNull($created->getCreatedAt());
        self::assertResponseRedirects('/plan-template/'.$created->getId().'/edit');
    }

    public function testAddItemPlacesWorkoutInCell(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $template = $this->createPlanTemplate($user, 'Plan 5k', 1);
        $this->client->loginUser($user);

        $this->client->request('GET', '/plan-template/'.$template->getId().'/edit');
        self::assertResponseIsSuccessful();

        // Première case de la grille = semaine 1, jour 1.
        $this->client->submitForm('Ajouter', [
            'add_item_w1_d1[workout]' => $workout->getId(),
            'add_item_w1_d1[notes]' => 'facile',
        ]);

        $this->em->clear();
        $item = $this->em->getRepository(PlanItem::class)->findOneBy([]);
        self::assertNotNull($item);
        self::assertSame(1, $item->getWeekNumber());
        self::assertSame(1, $item->getDayOfWeek());
        self::assertSame('facile', $item->getNotes());
        self::assertSame($workout->getId(), $item->getWorkout()->getId());
    }

    public function testDuplicateCopiesGrid(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $template = $this->createPlanTemplate($user, 'Plan 5k', 2);

        $item = (new PlanItem())->setWeekNumber(2)->setDayOfWeek(5)->setNotes('tempo');
        $item->setWorkout($workout);
        $template->addPlanItem($item);
        $this->em->persist($item);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/plan-template/'.$template->getId().'/edit');
        $this->client->submitForm('Dupliquer ce plan');

        $copy = $this->em->getRepository(PlanTemplate::class)->findOneBy(['title' => 'Plan 5k (copie)']);
        self::assertNotNull($copy);
        self::assertSame($user->getId(), $copy->getOwner()->getId());
        self::assertSame(2, $copy->getDurationWeeks());
        self::assertNotSame($template->getSlug(), $copy->getSlug());
        self::assertCount(1, $copy->getPlanItems());

        $copiedItem = $copy->getPlanItems()->first();
        self::assertSame(2, $copiedItem->getWeekNumber());
        self::assertSame(5, $copiedItem->getDayOfWeek());
        self::assertSame($workout->getId(), $copiedItem->getWorkout()->getId());
        self::assertResponseRedirects('/plan-template/'.$copy->getId().'/edit');
    }

    public function testShowDeniedToNonOwner(): void
    {
        $owner = $this->createUser('owner@example.com');
        $intruder = $this->createUser('intruder@example.com');
        $template = $this->createPlanTemplate($owner, 'Plan privé', 4);

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/plan-template/'.$template->getId());

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
}
