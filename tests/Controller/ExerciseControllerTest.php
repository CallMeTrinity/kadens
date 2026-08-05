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
use App\Repository\LoggedExerciseRepository;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
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

    // --- KL-51 : le tri de la bibliothèque par usage réel ---------------------

    /**
     * Sans aucun réalisé, les trois tris d'usage ne s'exposent pas : ils ne
     * feraient rien, et le diraient mal.
     */
    public function testIndexHidesUsageSortsWithoutAnyLog(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->createExercise($user, 'Squat');

        $this->client->loginUser($user);
        $this->client->request('GET', '/exercise');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('.kd-sort', 'Les plus faits');
        self::assertSelectorNotExists('.kd-libcard__usage');
    }

    /** Les trois tris, les attributs qui les alimentent, et le compteur discret. */
    public function testIndexExposesUsageSortsAndCounters(): void
    {
        $user = $this->createUser('owner@example.com');
        $done = $this->createExercise($user, 'Squat');
        $never = $this->createExercise($user, 'Fentes');

        $this->log($user, $done, '2026-03-01', [[SetType::NORMAL, 5, 100.0]]);
        $this->log($user, $done, '2026-03-08', [[SetType::NORMAL, 5, 105.0]]);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/exercise');

        self::assertResponseIsSuccessful();
        $sorts = $crawler->filter('.kd-sort option')->each(static fn ($node): string => $node->text());
        self::assertContains('Les plus faits', $sorts);
        self::assertContains('Jamais faits', $sorts);
        self::assertContains('Pas fait depuis', $sorts);

        $card = $crawler->filter('[data-filter-name="Squat"]');
        self::assertSame('2', $card->attr('data-sort-usage'));
        self::assertSame((string) (new \DateTimeImmutable('2026-03-08'))->getTimestamp(), $card->attr('data-sort-last'));
        self::assertStringContainsString('2×', $card->filter('.kd-libcard__usage')->text());

        // Jamais fait : compteur à zéro, et renvoyé en fin du tri « pas fait
        // depuis », qui est croissant — sinon il ferait doublon avec « jamais faits ».
        $unused = $crawler->filter('[data-filter-name="Fentes"]');
        self::assertSame('0', $unused->attr('data-sort-usage'));
        self::assertSame('9999999999', $unused->attr('data-sort-last'));
        self::assertCount(0, $unused->filter('.kd-libcard__usage'));
    }

    /**
     * Même piège que KL-50 : un exercice de la bibliothèque globale est partagé.
     * « Le plus exécuté » veut dire « par moi », jamais « par tout le monde ».
     */
    public function testUsageCountIsScopedToTheCurrentUser(): void
    {
        $mine = $this->createUser('me@example.com');
        $other = $this->createUser('other@example.com');

        $exercise = (new Exercise())->setName('Soulevé de terre')->setActivity(ActivityType::GYM);
        $this->em->persist($exercise);
        $this->em->flush();

        $this->log($mine, $exercise, '2026-03-08', [[SetType::NORMAL, 5, 90.0]]);
        foreach (['2026-03-01', '2026-03-03', '2026-03-05'] as $date) {
            $this->log($other, $exercise, $date, [[SetType::NORMAL, 5, 180.0]]);
        }

        $this->client->loginUser($mine);
        $crawler = $this->client->request('GET', '/exercise');

        self::assertSame('1', $crawler->filter('[data-filter-name="Soulevé de terre"]')->attr('data-sort-usage'));
    }

    /**
     * Un exercice sauté n'a pas été fait : il ne compte pas, et une séance où il
     * n'apparaît que sauté ne devient pas sa dernière exécution.
     */
    public function testSkippedOccurrencesDoNotCount(): void
    {
        $user = $this->createUser('owner@example.com');
        $exercise = $this->createExercise($user, 'Squat');

        $this->log($user, $exercise, '2026-03-01', [[SetType::NORMAL, 5, 100.0]]);
        $this->log($user, $exercise, '2026-03-08', [[SetType::NORMAL, 5, 100.0]], skipped: true);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/exercise');

        $card = $crawler->filter('[data-filter-name="Squat"]');
        self::assertSame('1', $card->attr('data-sort-usage'));
        self::assertSame((string) (new \DateTimeImmutable('2026-03-01'))->getTimestamp(), $card->attr('data-sort-last'));
    }

    /**
     * L'index charge toute la bibliothèque en une fois : l'usage doit tenir en
     * **une** requête d'agrégat, sinon c'est un N+1 pour un affichage discret.
     */
    public function testUsageIsReadInASingleAggregateQuery(): void
    {
        $user = $this->createUser('owner@example.com');
        for ($i = 1; $i <= 8; ++$i) {
            $exercise = $this->createExercise($user, 'Exercice '.$i);
            $this->log($user, $exercise, sprintf('2026-03-%02d', $i), [[SetType::NORMAL, 5, 50.0 + $i]]);
        }

        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $holder->reset();

        $usage = static::getContainer()->get(LoggedExerciseRepository::class)->usageForOwner($user);

        self::assertCount(1, $holder->getData()['default'] ?? []);
        self::assertCount(8, $usage);
    }

    /**
     * Une séance datée qui porte le réalisé d'un seul exercice.
     *
     * @param list<array{0: SetType, 1: int|null, 2: float|null}> $sets
     */
    private function log(User $owner, Exercise $exercise, string $date, array $sets, bool $skipped = false): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle('Séance du '.$date)
            ->setScheduledDate(new \DateTimeImmutable($date))
            ->setStatus(ScheduledStatus::DONE);

        $logged = (new LoggedExercise())
            ->setExercise($exercise)
            ->setExerciseName((string) $exercise->getName())
            ->setPosition(1)
            ->setSkipped($skipped);

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
