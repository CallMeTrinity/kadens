<?php

namespace App\Tests\Controller;

use App\Entity\ApiToken;
use App\Entity\Coaching;
use App\Entity\DeletedEntity;
use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\CoachingStatus;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `GET /api/exercises/{id}/history` (KL-17) : la trajectoire d'un exercice.
 *
 * Trois tests portent le ticket, les autres gardent les bords :
 *
 * 1. `testHistoryRendersTheSameShapeAsTheBootstrap` — l'exigence « un seul
 *    désérialiseur côté client » de KL-14/KL-15, appliquée à la performance :
 *    `last` et `best` sont comparés champ pour champ à l'entrée `history` du
 *    bootstrap. Le test échoue le jour où l'un des deux producteurs dérive.
 * 2. `testOnlyTheTenMostRecentSessionsAreReturned` — la borne du ticket, et
 *    l'ordre qui la rend lisible (la plus récente d'abord).
 * 3. `testAnotherUsersExerciseIsNotFound` — 404 et non 403 : l'identifiant est
 *    séquentiel, un 403 dirait la composition de la bibliothèque des autres.
 */
final class ApiExerciseHistoryTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        $this->purge();
    }

    /** Même précaution qu'`ApiBootstrapTest` : on rend la base telle qu'on l'a trouvée. */
    protected function tearDown(): void
    {
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purge();

        parent::tearDown();
    }

    private function purge(): void
    {
        foreach ([ApiToken::class, ScheduledWorkout::class, Coaching::class, PlanTemplate::class, Workout::class, Exercise::class, User::class] as $class) {
            foreach ($this->em->getRepository($class)->findAll() as $entity) {
                $this->em->remove($entity);
            }
        }
        $this->em->flush();

        foreach ($this->em->getRepository(DeletedEntity::class)->findAll() as $tombstone) {
            $this->em->remove($tombstone);
        }
        $this->em->flush();
    }

    // --- Le ticket ------------------------------------------------------------

    /**
     * **Le test du ticket.** Le bootstrap et cet endpoint décrivent la même
     * chose : la dernière performance et le record. La seule façon de promettre
     * au client un désérialiseur unique est qu'un seul service produise la
     * structure — `PerformanceHistoryPayload`. Comparer les sous-documents
     * entiers, et pas quelques clés, est ce qui rend le test capable d'échouer
     * sur un champ ajouté d'un seul côté.
     */
    public function testHistoryRendersTheSameShapeAsTheBootstrap(): void
    {
        $user = $this->createUser('athlete@example.com');
        $exercise = $this->createExercise('Développé couché', $user);
        $secret = $this->issueToken($user);

        $this->log($user, $exercise, new \DateTimeImmutable('-10 days'), [[SetType::NORMAL, 3, 100.0]]);
        $this->log($user, $exercise, new \DateTimeImmutable('-3 days'), [
            [SetType::WARMUP, 10, 40.0],
            [SetType::NORMAL, 8, 80.0],
            [SetType::NORMAL, 8, 80.0],
        ]);

        $fromBootstrap = $this->get('/api/bootstrap', $secret)['history'][0];
        $alone = $this->get('/api/exercises/'.$exercise->getId().'/history', $secret);

        self::assertSame($fromBootstrap['exerciseId'], $alone['exerciseId']);
        self::assertSame($fromBootstrap['last'], $alone['last']);
        self::assertSame($fromBootstrap['best'], $alone['best']);

        // Ce que l'endpoint ajoute, et lui seul : la trajectoire.
        self::assertSame(['exerciseId', 'last', 'best', 'sessions'], array_keys($alone));
        self::assertCount(2, $alone['sessions']);
        // `last` EST le premier point : dérivé de la même lecture, pas relu.
        self::assertSame($alone['sessions'][0], $alone['last']);

        // L'échauffement ne compte pas comme une série de travail, et les deux
        // séries identiques se condensent en un groupe de rang 1 à 2.
        self::assertSame(2, $alone['last']['workingSets']);
        // assertEquals et non assertSame : JSON ne distingue pas 1280 de 1280.0.
        self::assertEquals(1280.0, $alone['last']['tonnageKg']);
        self::assertCount(1, $alone['last']['sets']);
        self::assertEquals(
            ['type' => 'normal', 'count' => 2, 'reps' => 8, 'weightKg' => 80.0, 'durationSeconds' => null, 'firstIndex' => 1, 'lastIndex' => 2],
            $alone['last']['sets'][0],
        );

        // Le record est tous temps : il ne suit pas la dernière séance.
        self::assertEquals(100.0, $alone['best']['weightKg']);
        self::assertSame((new \DateTimeImmutable('-10 days'))->format('Y-m-d'), $alone['best']['date']);
    }

    /** Dix points au plus, la séance la plus récente en tête. */
    public function testOnlyTheTenMostRecentSessionsAreReturned(): void
    {
        $user = $this->createUser('athlete@example.com');
        $exercise = $this->createExercise('Squat', $user);

        for ($day = 20; $day >= 1; --$day) {
            $this->log($user, $exercise, new \DateTimeImmutable("-{$day} days"), [[SetType::NORMAL, 5, 100.0 + $day]]);
        }

        $payload = $this->get('/api/exercises/'.$exercise->getId().'/history', $this->issueToken($user));

        self::assertCount(10, $payload['sessions']);
        self::assertSame((new \DateTimeImmutable('-1 day'))->format('Y-m-d'), $payload['sessions'][0]['date']);
        self::assertSame((new \DateTimeImmutable('-10 days'))->format('Y-m-d'), $payload['sessions'][9]['date']);
        // Le record, lui, regarde tout l'historique — y compris hors des dix points.
        self::assertEquals(120.0, $payload['best']['weightKg']);
    }

    /**
     * **Introuvable et invisible rendent le même 404.** L'identifiant est
     * séquentiel : un 403 confirmerait l'existence de l'exercice perso d'un
     * tiers, donc la taille et la composition de sa bibliothèque, exercice par
     * exercice. C'est la différence de nature de clé avec
     * `GET /api/schedule/{uuid}`, qui distingue les deux.
     */
    public function testAnotherUsersExerciseIsNotFound(): void
    {
        $stranger = $this->createUser('stranger@example.com');
        $exercise = $this->createExercise('Secret de préparation', $stranger);
        $secret = $this->issueToken($this->createUser('athlete@example.com'));

        $this->client->request('GET', '/api/exercises/'.$exercise->getId().'/history', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$secret,
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        // Un identifiant qui n'existe pas rend exactement la même chose.
        $unknown = (string) $this->client->getResponse()->getContent();
        $this->client->request('GET', '/api/exercises/999999/history', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$secret,
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($unknown, (string) $this->client->getResponse()->getContent());
    }

    // --- Les bords ------------------------------------------------------------

    /**
     * Un exercice de la bibliothèque globale se lit par tous ; ce qu'on y a
     * soulevé n'appartient qu'à soi. La garde vit dans `PerformanceHistory`
     * (KL-04) et c'est celle que KL-50 exigera aussi côté web.
     */
    public function testTheHistoryOfAGlobalExerciseIsScopedToItsOwner(): void
    {
        $mine = $this->createUser('me@example.com');
        $other = $this->createUser('other@example.com');
        $exercise = $this->createExercise('Soulevé de terre', null);

        $this->log($mine, $exercise, new \DateTimeImmutable('-2 days'), [[SetType::NORMAL, 5, 90.0]]);
        $this->log($other, $exercise, new \DateTimeImmutable('-1 day'), [[SetType::NORMAL, 5, 200.0]]);

        $payload = $this->get('/api/exercises/'.$exercise->getId().'/history', $this->issueToken($mine));

        self::assertCount(1, $payload['sessions']);
        self::assertEquals(90.0, $payload['best']['weightKg']);
        self::assertEquals(90.0, $payload['sessions'][0]['topWeightKg']);
    }

    /**
     * Le coach lit la fiche de l'exercice perso de son athlète (`VIEW` est la
     * seule règle symétrique du projet) — mais l'historique qu'il y trouve est
     * **le sien**, pas celui de l'athlète. Lire le réalisé d'un athlète a son
     * endroit, `GET /api/schedule/{uuid}`, où la séance dit de qui elle parle.
     */
    public function testTheCoachSeesTheExerciseButNotTheAthletesHistory(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $coach = $this->createUser('coach@example.com');
        $this->accept($coach, $athlete);

        $exercise = $this->createExercise('Variante maison', $athlete);
        $this->log($athlete, $exercise, new \DateTimeImmutable('-1 day'), [[SetType::NORMAL, 8, 60.0]]);

        $payload = $this->get('/api/exercises/'.$exercise->getId().'/history', $this->issueToken($coach));

        self::assertSame([], $payload['sessions']);
        self::assertNull($payload['last']);
        self::assertNull($payload['best']);
    }

    /** Jamais fait : des champs nuls et une liste vide, pas une erreur. */
    public function testAnExerciseWithoutHistoryRendersEmptyFields(): void
    {
        $user = $this->createUser('athlete@example.com');
        $exercise = $this->createExercise('Jamais fait', $user);

        $payload = $this->get('/api/exercises/'.$exercise->getId().'/history', $this->issueToken($user));

        self::assertSame($exercise->getId(), $payload['exerciseId']);
        self::assertNull($payload['last']);
        self::assertNull($payload['best']);
        self::assertSame([], $payload['sessions']);
    }

    public function testHistoryRequiresAToken(): void
    {
        $user = $this->createUser('athlete@example.com');
        $exercise = $this->createExercise('Développé couché', $user);

        $this->client->request('GET', '/api/exercises/'.$exercise->getId().'/history');

        self::assertResponseStatusCodeSame(401);
        self::assertEmpty($this->client->getResponse()->headers->getCookies());
    }

    // --- Utilitaires ----------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function get(string $uri, string $secret): array
    {
        $this->client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);

        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function issueToken(User $owner, string $deviceName = 'Pixel de test'): string
    {
        $secret = ApiToken::generateSecret();

        $this->em->persist(new ApiToken($owner, $deviceName, $secret));
        $this->em->flush();

        return $secret;
    }

    private function createUser(string $email): User
    {
        $user = (new User())->setEmail($email)->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function accept(User $coach, User $athlete): void
    {
        $this->em->persist(
            (new Coaching())->setCoach($coach)->setAthlete($athlete)->setStatus(CoachingStatus::ACCEPTED),
        );
        $this->em->flush();
    }

    private function createExercise(string $name, ?User $owner): Exercise
    {
        $exercise = (new Exercise())->setOwner($owner)->setName($name)->setActivity(ActivityType::GYM);
        $this->em->persist($exercise);
        $this->em->flush();

        return $exercise;
    }

    /**
     * Une séance libre qui porte le réalisé d'un seul exercice : ce ticket ne
     * lit que le réalisé, le prescrit ne l'intéresse pas.
     *
     * @param list<array{SetType, int|null, float|null}> $sets
     */
    private function log(User $owner, Exercise $exercise, \DateTimeImmutable $date, array $sets): void
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle('Séance du '.$date->format('d/m'))
            ->setScheduledDate($date)
            ->setStatus(ScheduledStatus::DONE);

        $logged = (new LoggedExercise())
            ->setExercise($exercise)
            ->setExerciseName($exercise->getName())
            ->setPosition(0);

        foreach ($sets as $position => [$type, $reps, $weightKg]) {
            $logged->addLoggedSet(
                (new LoggedSet())->setPosition($position)->setSetType($type)->setReps($reps)->setWeightKg($weightKg),
            );
        }

        $scheduled->addLoggedExercise($logged);

        $this->em->persist($scheduled);
        $this->em->flush();
    }
}
