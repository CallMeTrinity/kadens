<?php

namespace App\Tests\Controller;

use App\Entity\ApiToken;
use App\Entity\Coaching;
use App\Entity\DeletedEntity;
use App\Entity\Exercise;
use App\Entity\ExerciseSetup;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\CoachingStatus;
use App\Tests\FindsEntities;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `PUT /api/exercises/{id}/setup` et la clé `setups` du bootstrap (#8 mobile) :
 * les réglages de machine, une note par pratiquant et par exercice.
 *
 * Trois tests portent la fonctionnalité, les autres gardent les bords :
 *
 * 1. `testTheSetupRoundTripsThroughTheBootstrap` — ce que le `PUT` écrit est ce
 *    que le bootstrap redescend, sous la même forme : un seul désérialiseur
 *    côté client.
 * 2. `testAnEmptyTextDeletesTheSetup` — « aucun réglage » n'a qu'une écriture
 *    en base, et c'est l'absence de ligne.
 * 3. `testTheSetupIsPrivateToItsAuthor` — le coach ne reçoit pas les crans de
 *    poulie de son athlète, même sur un exercice qu'ils voient tous les deux.
 */
final class ApiExerciseSetupTest extends WebTestCase
{
    use FindsEntities;

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

    // --- La fonctionnalité ----------------------------------------------------

    public function testTheSetupRoundTripsThroughTheBootstrap(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $press = $this->createExercise('Presse à cuisses', null);
        $secret = $this->issueToken($athlete);

        $written = $this->put($press, ['text' => "  dossier : 5\njambe : 1  "], $secret);

        // Les espaces de bord ne sont pas un réglage.
        self::assertSame("dossier : 5\njambe : 1", $written['text']);
        self::assertSame($press->getId(), $written['exerciseId']);
        self::assertNotNull($written['updatedAt']);

        self::assertSame([$written], $this->bootstrap($secret)['setups']);
    }

    public function testRewritingReplacesTheTextInsteadOfStackingIt(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $press = $this->createExercise('Presse à cuisses', null);
        $secret = $this->issueToken($athlete);

        $this->put($press, ['text' => 'dossier : 5'], $secret);
        $this->put($press, ['text' => 'dossier : 6'], $secret);

        $setups = $this->bootstrap($secret)['setups'];

        self::assertCount(1, $setups);
        self::assertSame('dossier : 6', $setups[0]['text']);
        self::assertSame(1, $this->em->getRepository(ExerciseSetup::class)->count([]));
    }

    /**
     * Rejouer le même `PUT` — la file du téléphone le fait après une coupure —
     * ne fait pas croire à une modification : l'horodatage ne bouge pas.
     */
    public function testReplayingTheSameTextKeepsItsTimestamp(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $press = $this->createExercise('Presse à cuisses', null);
        $secret = $this->issueToken($athlete);

        $first = $this->put($press, ['text' => 'dossier : 5'], $secret);
        $second = $this->put($press, ['text' => 'dossier : 5'], $secret);

        self::assertSame($first, $second);
    }

    public function testAnEmptyTextDeletesTheSetup(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $press = $this->createExercise('Presse à cuisses', null);
        $secret = $this->issueToken($athlete);

        $this->put($press, ['text' => 'dossier : 5'], $secret);
        $cleared = $this->put($press, ['text' => "   \n "], $secret);

        self::assertSame(['exerciseId' => $press->getId(), 'text' => null, 'updatedAt' => null], $cleared);
        self::assertSame([], $this->bootstrap($secret)['setups']);
        self::assertSame(0, $this->em->getRepository(ExerciseSetup::class)->count([]));

        // Effacer ce qui n'existe pas est un succès : la mutation rejouée après
        // une réponse perdue ne doit pas rester bloquée en file.
        $again = $this->put($press, ['text' => null], $secret);
        self::assertNull($again['text']);
    }

    public function testTheSetupIsPrivateToItsAuthor(): void
    {
        $coach = $this->createUser('coach@example.com');
        $athlete = $this->createUser('athlete@example.com');
        $this->accept($coach, $athlete);

        // Un exercice que les deux voient : celui du coach, prescrit à l'athlète.
        $press = $this->createExercise('Presse maison', $coach);

        $this->put($press, ['text' => 'dossier : 5'], $this->issueToken($athlete));

        self::assertSame([], $this->bootstrap($this->issueToken($coach))['setups']);
    }

    /**
     * La garde est `VIEW`, pas `EDIT` : l'athlète règle la machine d'un exercice
     * qu'il n'a pas le droit de modifier — global, ou maison de son coach.
     */
    public function testAReadableExerciseCanBeSetUpWithoutBeingEditable(): void
    {
        $coach = $this->createUser('coach@example.com');
        $athlete = $this->createUser('athlete@example.com');
        $this->accept($coach, $athlete);
        $secret = $this->issueToken($athlete);

        $this->put($this->createExercise('Presse maison', $coach), ['text' => 'cran 3'], $secret);
        $this->put($this->createExercise('Poulie haute', null), ['text' => 'cran 12'], $secret);

        self::assertCount(2, $this->bootstrap($secret)['setups']);
    }

    // --- Les bords ------------------------------------------------------------

    /** Même règle que l'historique : identifiant séquentiel, donc 404 et pas 403. */
    public function testAnotherUsersExerciseIsNotFound(): void
    {
        $owner = $this->createUser('owner@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $private = $this->createExercise('Variante maison', $owner);

        $this->request($private, ['text' => 'dossier : 5'], $this->issueToken($stranger));

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->em->getRepository(ExerciseSetup::class)->count([]));
    }

    public function testAnUnknownExerciseIsNotFound(): void
    {
        $secret = $this->issueToken($this->createUser('athlete@example.com'));

        $this->client->request(
            'PUT',
            '/api/exercises/999999/setup',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json'],
            content: '{"text":"dossier : 5"}',
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testATextTooLongIsRefusedWithItsField(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $press = $this->createExercise('Presse à cuisses', null);

        $this->request($press, ['text' => str_repeat('x', ExerciseSetup::MAX_LENGTH + 1)], $this->issueToken($athlete));

        self::assertResponseStatusCodeSame(422);
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('text', $problem['violations'][0]['field']);
    }

    /**
     * Un réglage sur l'exercice d'un coach reste en base quand la relation se
     * termine, mais l'exercice ne descend plus : son réglage non plus.
     */
    public function testASetupOnAnExerciseNoLongerVisibleDoesNotGoDown(): void
    {
        $coach = $this->createUser('coach@example.com');
        $athlete = $this->createUser('athlete@example.com');
        $this->accept($coach, $athlete);
        $secret = $this->issueToken($athlete);

        $this->put($this->createExercise('Presse maison', $coach), ['text' => 'dossier : 5'], $secret);

        foreach ($this->em->getRepository(Coaching::class)->findAll() as $coaching) {
            $this->em->remove($coaching);
        }
        $this->em->flush();

        self::assertSame([], $this->bootstrap($secret)['setups']);
    }

    public function testDeletingTheExerciseDeletesItsSetups(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $press = $this->createExercise('Presse maison', $athlete);

        $this->put($press, ['text' => 'dossier : 5'], $this->issueToken($athlete));

        // La suppression arrive par une autre requête, comme sur le web : c'est
        // la base qui nettoie (CASCADE), aucune entité ne connaît la relation.
        $this->em->clear();
        $this->em->remove($this->mustFind(Exercise::class, $press->getId()));
        $this->em->flush();

        self::assertSame(0, $this->em->getRepository(ExerciseSetup::class)->count([]));
    }

    // --- Utilitaires ----------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function put(Exercise $exercise, array $body, string $secret): array
    {
        $this->request($exercise, $body, $secret);

        self::assertResponseStatusCodeSame(200);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(Exercise $exercise, array $body, string $secret): void
    {
        $this->client->request(
            'PUT',
            '/api/exercises/'.$exercise->getId().'/setup',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function bootstrap(string $secret): array
    {
        $this->client->request('GET', '/api/bootstrap', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);

        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function issueToken(User $owner): string
    {
        $secret = ApiToken::generateSecret();

        $this->em->persist(new ApiToken($owner, 'Pixel de test', $secret));
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

    private function purge(): void
    {
        foreach ([ExerciseSetup::class, ApiToken::class, ScheduledWorkout::class, Coaching::class, PlanTemplate::class, Workout::class, Exercise::class, User::class] as $class) {
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
}
