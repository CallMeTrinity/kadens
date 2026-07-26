<?php

namespace App\Tests\Controller;

use App\Entity\Exercise;
use App\Entity\Goal;
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

        // Ordre FK-safe : les plans portent la jointure vers les objectifs, ils
        // passent donc avant eux, et les deux avant les utilisateurs.
        foreach ($this->em->getRepository(PlanTemplate::class)->findAll() as $template) {
            $this->em->remove($template);
        }
        foreach ($this->em->getRepository(Goal::class)->findAll() as $goal) {
            $this->em->remove($goal);
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

    /**
     * La création est un simple POST depuis l'index : pas d'écran de formulaire,
     * on atterrit sur l'éditeur avec un brouillon de 4 semaines dont le titre
     * s'ouvre d'emblée (`rename=1`).
     */
    public function testCreatePlanLandsOnEditorWithFourWeeks(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/plan-template');
        $this->client->submitForm('Nouveau plan');

        $created = $this->em->getRepository(PlanTemplate::class)->findOneBy(['title' => 'Nouveau plan']);
        self::assertNotNull($created);
        self::assertSame('nouveau-plan', $created->getSlug());
        self::assertSame(4, $created->getDurationWeeks());
        self::assertNotNull($created->getCreatedAt());
        self::assertResponseRedirects('/plan-template/'.$created->getId().'/edit?rename=1');
    }

    /**
     * Les semaines s'ajoutent une par une ou par paquet, et le plafond borne le
     * paquet au lieu de le refuser entièrement.
     */
    public function testAddWeeksOneByOneAndInBatch(): void
    {
        $user = $this->createUser('owner@example.com');
        $template = $this->createPlanTemplate($user, 'Plan 5k', 4);
        $this->client->loginUser($user);

        $url = '/plan-template/'.$template->getId().'/weeks/add';
        $crawler = $this->client->request('GET', '/plan-template/'.$template->getId().'/edit');
        $token = $crawler->filter('form[action="'.$url.'"] input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, ['_token' => $token]);
        $this->em->clear();
        self::assertSame(5, $this->em->getRepository(PlanTemplate::class)->find($template->getId())->getDurationWeeks());

        $this->client->request('POST', $url, ['_token' => $token, 'count' => 6]);
        $this->em->clear();
        self::assertSame(11, $this->em->getRepository(PlanTemplate::class)->find($template->getId())->getDurationWeeks());

        // Paquet qui dépasse : borné à 52, pas rejeté.
        $this->client->request('POST', $url, ['_token' => $token, 'count' => 90]);
        $this->em->clear();
        self::assertSame(52, $this->em->getRepository(PlanTemplate::class)->find($template->getId())->getDurationWeeks());
    }

    /**
     * Le rattachement se pose aussi depuis l'éditeur de plan (bandeau #plan-goals),
     * pas seulement depuis la fiche objectif : la relation se navigue dans les deux
     * sens.
     */
    public function testAttachGoalFromThePlanEditor(): void
    {
        $user = $this->createUser('owner@example.com');
        $template = $this->createPlanTemplate($user, 'Prépa trail', 4);

        $goal = (new Goal())
            ->setOwner($user)
            ->setTitle('Trail 42k')
            ->setTargetDate(new \DateTimeImmutable('+10 weeks'));
        $this->em->persist($goal);
        $this->em->flush();

        $templateId = $template->getId();
        $url = '/plan-template/'.$templateId.'/goals';

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/plan-template/'.$templateId.'/edit');
        $token = $crawler->filter('form[action="'.$url.'"] input[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, ['_token' => $token, 'action' => 'attach', 'goalId' => $goal->getId()]);
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(PlanTemplate::class)->find($templateId)->getGoals());

        $this->client->request('POST', $url, ['_token' => $token, 'action' => 'detach', 'goalId' => $goal->getId()]);
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(PlanTemplate::class)->find($templateId)->getGoals());
    }

    public function testPlaceItemForksWorkoutInCell(): void
    {
        $user = $this->createUser('owner@example.com');
        $workout = $this->createWorkout($user, 'Sortie longue');
        $template = $this->createPlanTemplate($user, 'Plan 5k', 1);
        $this->client->loginUser($user);

        // La pose se fait par la palette (mode tampon / glisser-déposer) : POST
        // vers /place avec workoutId + week + day. Le jeton CSRF est porté par la
        // palette dans la page d'édition.
        $crawler = $this->client->request('GET', '/plan-template/'.$template->getId().'/edit');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('[data-place-token]')->attr('data-place-token');

        $this->client->request('POST', '/plan-template/'.$template->getId().'/place', [
            '_token' => $token,
            'workoutId' => $workout->getId(),
            'week' => 1,
            'day' => 1,
        ]);

        $this->em->clear();
        $item = $this->em->getRepository(PlanItem::class)->findOneBy([]);
        self::assertNotNull($item);
        self::assertSame(1, $item->getWeekNumber());
        self::assertSame(1, $item->getDayOfWeek());
        // Fork à la pose : la case porte une COPIE privée (planLocal), pas la
        // séance de bibliothèque elle-même.
        self::assertNotSame($workout->getId(), $item->getWorkout()->getId());
        self::assertTrue($item->getWorkout()->isPlanLocal());
        self::assertSame('Sortie longue', $item->getWorkout()->getTitle());
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
        // Duplication de plan = plans indépendants : la case porte une COPIE clonée
        // (planLocal), pas la même séance, pour que les progressions ne se partagent pas.
        self::assertNotSame($workout->getId(), $copiedItem->getWorkout()->getId());
        self::assertSame('Sortie longue', $copiedItem->getWorkout()->getTitle());
        self::assertTrue($copiedItem->getWorkout()->isPlanLocal());
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
