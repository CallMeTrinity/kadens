<?php

namespace App\Tests\Controller;

use App\Entity\ApiToken;
use App\Entity\Block;
use App\Entity\Coaching;
use App\Entity\DeletedEntity;
use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\CoachingStatus;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /api/schedule/{uuid}` (KL-15) et `PUT` / `DELETE` (KL-16) : la séance
 * datée vue du téléphone.
 *
 * Cinq tests portent les deux tickets, les autres gardent les bords :
 *
 * 1. `testShowRendersExactlyWhatTheBootstrapRenders` — l'exigence « un seul
 *    désérialiseur côté client » ne se tient qu'avec un seul producteur. Le test
 *    compare les deux réponses **octet pour octet** : c'est la seule formulation
 *    qui échoue le jour où quelqu'un ajoute un champ d'un côté.
 * 2. `testReplayingTheSameDocumentChangesNothing` — l'idempotence, envoyée trois
 *    fois. C'est ce qui rend la file de mutations du mobile rejouable, donc
 *    l'offline-first tenable.
 * 3. `testPutNeverTouchesThePrescribedNorThePlan` — le réalisé vit à côté du
 *    prescrit et n'y écrit jamais (§0.3). Un `PUT` qui déplacerait la séance ou
 *    la détacherait de son plan casserait le `resync`.
 * 4. `testTheCoachCanReadTheLogButNotWriteIt` — `VIEW` d'un côté, `LOG` de
 *    l'autre (KL-06). Tester `EDIT` passerait et donnerait la main au coach.
 * 5. `testInvalidValuesAreRejected` — les trois refus nommés par le ticket, en
 *    422, avec le champ fautif.
 */
final class ApiScheduleTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        $this->purge();
    }

    /**
     * Même précaution qu'`ApiBootstrapTest` : ce fichier laisse des `Workout`
     * derrière lui, et le ménage des tests suivants échouerait sur la clé
     * étrangère `workout.owner_id`. On rend la base telle qu'on l'a trouvée.
     */
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

    // --- KL-15 : la lecture ---------------------------------------------------

    /**
     * **Le test du ticket KL-15.** Le client mobile n'écrit qu'un désérialiseur ;
     * la seule façon de le lui promettre est que les deux endpoints passent par
     * `ScheduledWorkoutPayload`. Comparer les corps entiers, et pas quelques
     * clés, est ce qui rend le test capable d'échouer sur un champ ajouté d'un
     * seul côté.
     */
    public function testShowRendersExactlyWhatTheBootstrapRenders(): void
    {
        $user = $this->createUser('athlete@example.com');
        [$scheduled] = $this->createSession($user, new \DateTimeImmutable('-2 days'), logged: [
            [SetType::WARMUP, 10, 40.0],
            [SetType::NORMAL, 8, 80.0],
        ]);
        $secret = $this->issueToken($user);

        $fromBootstrap = $this->get('/api/bootstrap', $secret)['schedule'][0];
        $alone = $this->get('/api/schedule/'.$scheduled->getUuid(), $secret);

        self::assertSame($fromBootstrap, $alone);
        self::assertSame('Haut du corps', $alone['title']);
        self::assertCount(2, $alone['log'][0]['sets']);
    }

    /** Sans source, un programme vide — pas une erreur (`SET NULL`, KL-02). */
    public function testAFreeformSessionRendersAnEmptyProgram(): void
    {
        $user = $this->createUser('athlete@example.com');
        $scheduled = $this->createFreeform($user, 'Muscu improvisée');

        $payload = $this->get('/api/schedule/'.$scheduled->getUuid(), $this->issueToken($user));

        self::assertTrue($payload['freeform']);
        self::assertSame([], $payload['blocks']);
        self::assertSame('Muscu improvisée', $payload['title']);
    }

    public function testAnUnknownUuidIsNotFound(): void
    {
        $user = $this->createUser('athlete@example.com');

        $this->client->request('GET', '/api/schedule/'.Uuid::v7(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->issueToken($user),
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testTheSessionOfAStrangerIsForbidden(): void
    {
        $owner = $this->createUser('athlete@example.com');
        $stranger = $this->createUser('stranger@example.com');
        [$scheduled] = $this->createSession($owner, new \DateTimeImmutable('-1 day'));

        $this->client->request('GET', '/api/schedule/'.$scheduled->getUuid(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->issueToken($stranger),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testShowRequiresAToken(): void
    {
        $user = $this->createUser('athlete@example.com');
        [$scheduled] = $this->createSession($user, new \DateTimeImmutable('-1 day'));

        $this->client->request('GET', '/api/schedule/'.$scheduled->getUuid());

        self::assertResponseStatusCodeSame(401);
        self::assertEmpty($this->client->getResponse()->headers->getCookies());
    }

    // --- KL-16 : l'écriture ---------------------------------------------------

    /**
     * Une séance libre naît du téléphone : sans source, avec son titre, et **sans
     * qu'aucun `Workout` n'entre en bibliothèque** — case explicite du ticket.
     */
    public function testPutCreatesAFreeformSessionWithoutTouchingTheLibrary(): void
    {
        $user = $this->createUser('athlete@example.com');
        $secret = $this->issueToken($user);
        $uuid = Uuid::v7();

        $payload = $this->put($uuid, $secret, [
            'date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'title' => 'Séance improvisée',
            'status' => 'done',
            'startedAt' => '2026-08-02T18:00:00+02:00',
            'endedAt' => '2026-08-02T19:12:00+02:00',
            'log' => [[
                'name' => 'Tractions',
                'sets' => [
                    ['uuid' => (string) Uuid::v7(), 'type' => 'warmup', 'reps' => 5],
                    ['uuid' => (string) Uuid::v7(), 'type' => 'normal', 'reps' => 8, 'weightKg' => 12.5, 'rpe' => 8],
                ],
            ]],
        ], expected: 201);

        self::assertSame((string) $uuid, $payload['uuid']);
        self::assertTrue($payload['freeform']);
        self::assertSame('done', $payload['status']);
        self::assertSame('Séance improvisée', $payload['title']);
        self::assertCount(2, $payload['log'][0]['sets']);
        self::assertSame(12.5, $payload['log'][0]['sets'][1]['weightKg']);

        // La bibliothèque n'a pas bougé : une séance improvisée n'est pas un
        // programme, et le serveur n'en fabrique pas un.
        self::assertCount(0, $this->em->getRepository(Workout::class)->findAll());
    }

    /**
     * **Le test du ticket KL-16.** Trois envois du même document : une séance,
     * un jeu de séries, et 201 la première fois seulement.
     */
    public function testReplayingTheSameDocumentChangesNothing(): void
    {
        $user = $this->createUser('athlete@example.com');
        $secret = $this->issueToken($user);
        $uuid = Uuid::v7();
        $setUuid = (string) Uuid::v7();

        $document = [
            'date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'title' => 'Séance rejouée',
            'log' => [[
                'name' => 'Tractions',
                'sets' => [['uuid' => $setUuid, 'type' => 'normal', 'reps' => 8]],
            ]],
        ];

        $this->put($uuid, $secret, $document, expected: 201);
        $this->put($uuid, $secret, $document, expected: 200);
        $last = $this->put($uuid, $secret, $document, expected: 200);

        self::assertCount(1, $this->em->getRepository(ScheduledWorkout::class)->findAll());
        self::assertCount(1, $this->em->getRepository(LoggedSet::class)->findAll());
        self::assertSame($setUuid, $last['log'][0]['sets'][0]['uuid']);
    }

    /** Le téléphone fait autorité sur le réalisé : le second document remplace le premier. */
    public function testASecondDocumentReplacesTheLog(): void
    {
        $user = $this->createUser('athlete@example.com');
        $secret = $this->issueToken($user);
        $uuid = Uuid::v7();
        $kept = (string) Uuid::v7();

        $this->put($uuid, $secret, [
            'date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'log' => [[
                'name' => 'Tractions',
                'sets' => [
                    ['uuid' => $kept, 'type' => 'normal', 'reps' => 8],
                    ['uuid' => (string) Uuid::v7(), 'type' => 'normal', 'reps' => 6],
                ],
            ]],
        ], expected: 201);

        $payload = $this->put($uuid, $secret, [
            'date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'log' => [[
                'name' => 'Tractions',
                'sets' => [['uuid' => $kept, 'type' => 'normal', 'reps' => 10, 'weightKg' => 20.0]],
            ]],
        ], expected: 200);

        self::assertCount(1, $payload['log'][0]['sets']);
        self::assertSame(10, $payload['log'][0]['sets'][0]['reps']);
        // La série réécrite garde son identifiant : la seconde écriture est bien
        // un remplacement, pas une accumulation.
        self::assertSame($kept, $payload['log'][0]['sets'][0]['uuid']);
        self::assertCount(1, $this->em->getRepository(LoggedSet::class)->findAll());
    }

    /**
     * **Le troisième test du ticket.** Une séance issue d'un plan qu'on remplit
     * reste une séance de plan, à sa date, avec son programme intact. Sans quoi
     * le `resync` ne la retrouverait plus et le prescrit se ferait écraser par
     * le réalisé — l'inverse exact de §0.3.
     */
    public function testPutNeverTouchesThePrescribedNorThePlan(): void
    {
        $user = $this->createUser('athlete@example.com');
        $date = new \DateTimeImmutable('-1 day');
        [$scheduled, $prescribed] = $this->createSession($user, $date);

        $template = (new PlanTemplate())->setOwner($user)->setTitle('Prépa')->setSlug('prepa-'.bin2hex(random_bytes(4)))->setDurationWeeks(4);
        $this->em->persist($template);
        $scheduled->setSourcePlanTemplate($template);
        $scheduled->setPlanAnchorDate($date);
        $this->em->flush();

        $secret = $this->issueToken($user);
        $prescribedId = $prescribed->getId();

        $payload = $this->put($scheduled->getUuid(), $secret, [
            // Une date volontairement fausse : la programmation appartient au web.
            'date' => (new \DateTimeImmutable('+9 days'))->format('Y-m-d'),
            'title' => 'Titre que le téléphone ne doit pas imposer',
            'status' => 'done',
            'log' => [[
                'sourcePrescribedId' => $prescribedId,
                'sets' => [['uuid' => (string) Uuid::v7(), 'type' => 'normal', 'reps' => 8, 'weightKg' => 85.0]],
            ]],
        ], expected: 200);

        self::assertSame($date->format('Y-m-d'), $payload['date'], 'Le téléphone ne déplace pas une séance.');
        self::assertSame('Haut du corps', $payload['title'], 'Le titre vient de la séance source.');
        self::assertSame('done', $payload['status']);

        // Le prescrit est intact : mêmes blocs, mêmes valeurs. assertEquals et
        // non assertSame : JSON ne distingue pas 80 de 80.0.
        self::assertEquals(80.0, $payload['blocks'][0]['exercises'][0]['sets'][0]['weightKg']);
        self::assertSame($prescribedId, $payload['log'][0]['sourcePrescribedId']);

        $this->em->clear();
        $reloaded = $this->em->getRepository(ScheduledWorkout::class)->findOneBy(['uuid' => $scheduled->getUuid()]);
        self::assertNotNull($reloaded->getSourcePlanTemplate(), 'Le rattachement au plan survit à une écriture du réalisé.');
        self::assertNotNull($reloaded->getPlanAnchorDate());
    }

    /**
     * Le nom est un snapshot, et le serveur sait le former quand le client ne
     * l'envoie pas : c'est une case explicite du ticket.
     */
    public function testTheServerFillsTheExerciseNameFromTheReference(): void
    {
        $user = $this->createUser('athlete@example.com');
        $exercise = $this->createExercise('Soulevé de terre', $user);
        $secret = $this->issueToken($user);

        $payload = $this->put(Uuid::v7(), $secret, [
            'date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'log' => [[
                'exerciseId' => $exercise->getId(),
                'sets' => [['uuid' => (string) Uuid::v7(), 'type' => 'normal', 'reps' => 5, 'weightKg' => 120.0]],
            ]],
        ], expected: 201);

        self::assertSame('Soulevé de terre', $payload['log'][0]['name']);
        self::assertSame($exercise->getId(), $payload['log'][0]['exerciseId']);
    }

    /**
     * **Le cinquième test du ticket** : les trois refus qu'il nomme, plus le
     * champ fautif — un 422 nu obligerait le client à deviner.
     */
    public function testInvalidValuesAreRejected(): void
    {
        $user = $this->createUser('athlete@example.com');
        $secret = $this->issueToken($user);
        $date = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $cases = [
            'poids négatif' => ['uuid' => (string) Uuid::v7(), 'type' => 'normal', 'weightKg' => -10.0],
            '400 répétitions' => ['uuid' => (string) Uuid::v7(), 'type' => 'normal', 'reps' => 400],
            'type de série inconnu' => ['uuid' => (string) Uuid::v7(), 'type' => 'super-serie', 'reps' => 8],
        ];

        foreach ($cases as $label => $set) {
            $problem = $this->putRaw(Uuid::v7(), $secret, [
                'date' => $date,
                'log' => [['name' => 'Tractions', 'sets' => [$set]]],
            ]);

            self::assertResponseStatusCodeSame(422, sprintf('« %s » doit être refusé.', $label));
            self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
            self::assertNotEmpty($problem['violations'], sprintf('« %s » doit dire quel champ.', $label));
        }

        // Rien n'a été écrit : la validation précède l'ingestion, et l'ingestion
        // est de toute façon dans une transaction.
        self::assertCount(0, $this->em->getRepository(ScheduledWorkout::class)->findAll());
    }

    /**
     * Un exercice qu'on ne peut pas voir n'est pas un exercice. Le lier
     * silencieusement à `null` laisserait la séance lisible mais la ferait sortir
     * de l'historique et des records sans que rien ne le signale.
     */
    public function testAnExerciseOfAStrangerIsRejected(): void
    {
        $user = $this->createUser('athlete@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $theirs = $this->createExercise('Variante privée d\'un inconnu', $stranger);

        $problem = $this->putRaw(Uuid::v7(), $this->issueToken($user), [
            'date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'log' => [['exerciseId' => $theirs->getId(), 'name' => 'Peu importe', 'sets' => []]],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('log[0].exerciseId', $problem['violations'][0]['field']);
    }

    /** La ligne de programme d'une autre séance n'apparie rien : elle est refusée. */
    public function testAPrescribedLineFromAnotherSessionIsRejected(): void
    {
        $user = $this->createUser('athlete@example.com');
        [, $elsewhere] = $this->createSession($user, new \DateTimeImmutable('-5 days'));
        [$target] = $this->createSession($user, new \DateTimeImmutable('-1 day'));

        $problem = $this->putRaw($target->getUuid(), $this->issueToken($user), [
            'date' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
            'log' => [['sourcePrescribedId' => $elsewhere->getId(), 'name' => 'Développé couché', 'sets' => []]],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('log[0].sourcePrescribedId', $problem['violations'][0]['field']);
    }

    /**
     * L'`uuid` d'une série est unique dans toute la base : le recycler viserait
     * une séance qu'on n'a pas demandé de toucher. 409, et rien n'est écrit.
     */
    public function testASetUuidBorrowedFromAnotherSessionIsAConflict(): void
    {
        $user = $this->createUser('athlete@example.com');
        $secret = $this->issueToken($user);
        $borrowed = (string) Uuid::v7();

        $this->put(Uuid::v7(), $secret, [
            'date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'log' => [['name' => 'Tractions', 'sets' => [['uuid' => $borrowed, 'type' => 'normal', 'reps' => 8]]]],
        ], expected: 201);

        $this->putRaw(Uuid::v7(), $secret, [
            'date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'log' => [['name' => 'Dips', 'sets' => [['uuid' => $borrowed, 'type' => 'normal', 'reps' => 6]]]],
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertCount(1, $this->em->getRepository(ScheduledWorkout::class)->findAll());
    }

    /**
     * Le contrat d'entrée est du JSON déclaré comme tel. Les deux refus sortent
     * eux aussi en `problem+json` — un client mobile n'a qu'un décodeur d'erreur,
     * et il ne doit pas tomber sur une page HTML au premier corps mal formé.
     */
    public function testAMalformedBodyIsRejectedAsAProblem(): void
    {
        $user = $this->createUser('athlete@example.com');
        $secret = $this->issueToken($user);

        // Du JSON invalide, correctement annoncé.
        $this->client->request('PUT', '/api/schedule/'.Uuid::v7(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$secret,
            'CONTENT_TYPE' => 'application/json',
        ], content: '{ pas du json');
        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        // Du JSON valide, mais annoncé comme du texte.
        $this->client->request('PUT', '/api/schedule/'.Uuid::v7(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$secret,
            'CONTENT_TYPE' => 'text/plain',
        ], content: '{"date":"2026-08-02","log":[]}');
        self::assertResponseStatusCodeSame(415);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    /** Le corps ne peut pas désigner une autre séance que l'URL. */
    public function testAMismatchedUuidIsRejected(): void
    {
        $user = $this->createUser('athlete@example.com');

        $problem = $this->putRaw(Uuid::v7(), $this->issueToken($user), [
            'uuid' => (string) Uuid::v7(),
            'date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'log' => [],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('uuid', $problem['violations'][0]['field']);
    }

    // --- Les gardes de coaching ----------------------------------------------

    /**
     * **Le quatrième test du ticket.** Le coach lit le réalisé de son athlète
     * (`VIEW`), il ne l'écrit pas (`LOG`). Tester `EDIT` — que le coach possède —
     * passerait, et donnerait à quelqu'un le droit de déclarer ce qu'un autre a
     * soulevé.
     */
    public function testTheCoachCanReadTheLogButNotWriteIt(): void
    {
        $athlete = $this->createUser('athlete@example.com');
        $coach = $this->createUser('coach@example.com');
        $this->accept($coach, $athlete);
        [$scheduled] = $this->createSession($athlete, new \DateTimeImmutable('-1 day'), logged: [[SetType::NORMAL, 8, 80.0]]);

        $secret = $this->issueToken($coach);

        $payload = $this->get('/api/schedule/'.$scheduled->getUuid(), $secret);
        self::assertCount(1, $payload['log'][0]['sets']);

        $this->putRaw($scheduled->getUuid(), $secret, [
            'date' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
            'log' => [['name' => 'Développé couché', 'sets' => [['uuid' => (string) Uuid::v7(), 'type' => 'normal', 'reps' => 1]]]],
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('DELETE', '/api/schedule/'.$scheduled->getUuid(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$secret,
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    // --- La suppression -------------------------------------------------------

    public function testDeleteRemovesAFreeformSession(): void
    {
        $user = $this->createUser('athlete@example.com');
        $scheduled = $this->createFreeform($user, 'À jeter');

        $this->client->request('DELETE', '/api/schedule/'.$scheduled->getUuid(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->issueToken($user),
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertCount(0, $this->em->getRepository(ScheduledWorkout::class)->findAll());
    }

    /** Une séance qui porte un programme se retire depuis le web, avec son contexte. */
    public function testDeleteRefusesASessionThatHasAProgram(): void
    {
        $user = $this->createUser('athlete@example.com');
        [$scheduled] = $this->createSession($user, new \DateTimeImmutable('-1 day'));

        $this->client->request('DELETE', '/api/schedule/'.$scheduled->getUuid(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->issueToken($user),
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertCount(1, $this->em->getRepository(ScheduledWorkout::class)->findAll());
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

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function put(Uuid $uuid, string $secret, array $document, int $expected): array
    {
        $payload = $this->putRaw($uuid, $secret, $document);

        self::assertResponseStatusCodeSame($expected);

        return $payload;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function putRaw(Uuid $uuid, string $secret, array $document): array
    {
        $this->client->request(
            'PUT',
            '/api/schedule/'.$uuid,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$secret,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode($document, \JSON_THROW_ON_ERROR),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
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

    private function createFreeform(User $owner, string $title): ScheduledWorkout
    {
        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setTitle($title)
            ->setScheduledDate(new \DateTimeImmutable('-1 day'))
            ->setStatus(ScheduledStatus::DONE);
        $scheduled->addLoggedExercise(
            (new LoggedExercise())
                ->setExerciseName('Tractions')
                ->setPosition(0)
                ->addLoggedSet((new LoggedSet())->setPosition(0)->setSetType(SetType::NORMAL)->setReps(10)),
        );

        $this->em->persist($scheduled);
        $this->em->flush();

        return $scheduled;
    }

    /**
     * Une séance datée avec son programme et, si on lui en donne, son réalisé.
     *
     * @param list<array{SetType, int|null, float|null}> $logged
     *
     * @return array{ScheduledWorkout, PrescribedExercise}
     */
    private function createSession(User $owner, \DateTimeImmutable $date, array $logged = []): array
    {
        $exercise = $this->createExercise('Développé couché', $owner);

        $workout = (new Workout())
            ->setOwner($owner)
            ->setTitle('Haut du corps')
            ->setSlug('haut-du-corps-'.bin2hex(random_bytes(6)));
        $this->em->persist($workout);

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $workout->addBlock($block);
        $this->em->persist($block);

        $prescribed = (new PrescribedExercise())
            ->setExercise($exercise)
            ->setPosition(0)
            ->setPrescriptionType(PrescriptionType::SETS_REPS)
            ->setSets(3)->setReps(8)->setWeightKg(80.0);
        $block->addPrescribedExercise($prescribed);

        $scheduled = (new ScheduledWorkout())
            ->setOwner($owner)
            ->setWorkout($workout)
            ->setScheduledDate($date)
            ->setStatus([] === $logged ? ScheduledStatus::PLANNED : ScheduledStatus::DONE);

        if ([] !== $logged) {
            $loggedExercise = (new LoggedExercise())
                ->setExerciseName($exercise->getName())
                ->setExercise($exercise)
                ->setSourcePrescribedExercise($prescribed)
                ->setPosition(0);

            foreach ($logged as $position => [$type, $reps, $weightKg]) {
                $loggedExercise->addLoggedSet(
                    (new LoggedSet())->setPosition($position)->setSetType($type)->setReps($reps)->setWeightKg($weightKg),
                );
            }

            $scheduled->addLoggedExercise($loggedExercise);
        }

        $this->em->persist($scheduled);
        $this->em->flush();

        return [$scheduled, $prescribed];
    }
}
