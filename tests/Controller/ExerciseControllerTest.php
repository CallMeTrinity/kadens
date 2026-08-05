<?php

namespace App\Tests\Controller;

use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ExerciseControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        // Base de test propre : d'abord les séances datées (elles portent le
        // réalisé et citent trame, case et séance), puis les plans (référencent
        // séances via PlanItem et owner), puis les séances et exercices (clé
        // étrangère owner), puis les utilisateurs.
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

    public function testIndexRedirectsWhenAnonymous(): void
    {
        $this->client->request('GET', '/exercise');

        self::assertResponseRedirects('/login');
    }

    public function testIndexShowsOnlyOwnExercises(): void
    {
        $owner = $this->createUser('owner@example.com');
        $other = $this->createUser('other@example.com');
        $this->createExercise($owner, 'Squat');
        $this->createExercise($other, 'Développé couché');

        $this->client->loginUser($owner);
        $this->client->request('GET', '/exercise');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Squat');
        self::assertSelectorTextNotContains('body', 'Développé couché');
    }

    public function testShowDeniesAccessToNonOwner(): void
    {
        $owner = $this->createUser('owner@example.com');
        $intruder = $this->createUser('intruder@example.com');
        $exercise = $this->createExercise($owner, 'Squat');

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/exercise/'.$exercise->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateExercise(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/exercise/new');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Créer', [
            'exercise[name]' => 'Fentes',
            'exercise[activity]' => ActivityType::GYM->value,
        ]);

        self::assertResponseRedirects('/exercise');
        $created = $this->em->getRepository(Exercise::class)->findOneBy(['name' => 'Fentes']);
        self::assertNotNull($created);
        self::assertSame($user->getId(), $created->getOwner()?->getId());
    }

    /**
     * Un admin alimente la bibliothèque GLOBALE : l'exercice naît sans owner, donc
     * visible par tout le monde. Avant, il partait en perso comme n'importe quel
     * membre — invisible pour les autres, ce qui vidait le rôle de son sens.
     */
    public function testAdminCreatesGlobalExercise(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $this->client->loginUser($admin);

        $this->client->request('GET', '/exercise/new');
        $this->client->submitForm('Créer', [
            'exercise[name]' => 'Soulevé de terre',
            'exercise[activity]' => ActivityType::GYM->value,
        ]);

        $created = $this->em->getRepository(Exercise::class)->findOneBy(['name' => 'Soulevé de terre']);
        self::assertNotNull($created);
        self::assertNull($created->getOwner());

        // Un membre lambda le voit dans sa bibliothèque et peut ouvrir sa fiche.
        $this->client->loginUser($this->createUser('member@example.com'));
        $crawler = $this->client->request('GET', '/exercise');
        self::assertStringContainsString('Soulevé de terre', $crawler->html());

        $this->client->request('GET', '/exercise/'.$created->getId());
        self::assertResponseIsSuccessful();
    }

    // --- KL-50 : la trajectoire d'un exercice --------------------------------

    /** Un exercice jamais fait n'affiche rien : pas de cadre vide, pas de graphique à zéro. */
    public function testShowWithoutHistoryRendersNoTrajectoryBlock(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise($user, 'Squat');

        $this->client->loginUser($user);
        $this->client->request('GET', '/exercise/'.$exercise->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.kd-extraj');
    }

    /** Record, dernière performance, courbe et lien vers chaque séance datée. */
    public function testShowRendersRecordLastPerformanceAndSessions(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise($user, 'Développé couché');

        $first = $this->log($user, $exercise, '2026-03-01', [[SetType::WARMUP, 10, 40.0], [SetType::NORMAL, 8, 80.0]]);
        $last = $this->log($user, $exercise, '2026-03-08', [[SetType::NORMAL, 6, 90.0], [SetType::NORMAL, 6, 90.0]]);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/exercise/'.$exercise->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.kd-extraj');
        // Le record est la charge la plus lourde, l'échauffement n'en fait jamais partie.
        self::assertSelectorTextContains('.kd-wk__kpi--accent dd', '90');
        // Deux séances chargées : la courbe existe, avec un point par séance.
        self::assertCount(2, $crawler->filter('.kd-extraj .kd-prog__col'));
        // Chaque séance renvoie vers sa séance datée.
        self::assertSelectorExists('a[href="/schedule/'.$first->getId().'"]');
        self::assertSelectorExists('a[href="/schedule/'.$last->getId().'"]');
    }

    /**
     * Le point dur du ticket : un exercice de la bibliothèque globale est pratiqué
     * par tout le monde. « Est-ce que je progresse » ne peut vouloir dire que
     * « moi » — jamais le réalisé d'un autre, même sur le même exercice.
     */
    public function testGlobalExerciseShowsOnlyTheCurrentUserHistory(): void
    {
        $mine = $this->createUser('me@example.com');
        $other = $this->createUser('other@example.com');

        // Sans owner : bibliothèque globale, visible par les deux.
        $exercise = (new Exercise())->setName('Soulevé de terre')->setActivity(ActivityType::GYM);
        $this->em->persist($exercise);
        $this->em->flush();

        $this->log($mine, $exercise, '2026-03-08', [[SetType::NORMAL, 5, 90.0]]);
        $theirs = $this->log($other, $exercise, '2026-03-09', [[SetType::NORMAL, 5, 180.0]]);

        $this->client->loginUser($mine);
        $this->client->request('GET', '/exercise/'.$exercise->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.kd-wk__kpi--accent dd', '90');
        self::assertSelectorTextNotContains('.kd-extraj', '180');
        // Et surtout : aucun lien vers la séance datée de l'autre.
        self::assertSelectorNotExists('a[href="/schedule/'.$theirs->getId().'"]');
    }

    /**
     * Une séance datée qui porte le réalisé d'un seul exercice.
     *
     * @param list<array{0: SetType, 1: int|null, 2: float|null}> $sets
     */
    private function log(User $owner, Exercise $exercise, string $date, array $sets): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle('Séance du '.$date)
            ->setScheduledDate(new \DateTimeImmutable($date))
            ->setStatus(ScheduledStatus::DONE);

        $logged = (new LoggedExercise())
            ->setExercise($exercise)
            ->setExerciseName((string) $exercise->getName())
            ->setPosition(1);

        $position = 0;
        foreach ($sets as $set) {
            $logged->addLoggedSet(
                (new LoggedSet())
                    ->setPosition(++$position)
                    ->setSetType($set[0])
                    ->setReps($set[1])
                    ->setWeightKg($set[2])
            );
        }

        $scheduled->addLoggedExercise($logged);
        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }

    /** @param list<string> $roles */
    private function createUser(string $email, array $roles = []): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())->setEmail($email)->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'password'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createExercise(User $owner, string $name): Exercise
    {
        $exercise = (new Exercise())
            ->setOwner($owner)
            ->setName($name)
            ->setActivity(ActivityType::GYM);

        $this->em->persist($exercise);
        $this->em->flush();

        return $exercise;
    }
}
