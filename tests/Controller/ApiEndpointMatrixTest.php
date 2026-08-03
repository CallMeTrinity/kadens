<?php

declare(strict_types=1);

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
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Enum\SetType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * KL-18 — les gardes que **tous** les endpoints doivent tenir, tenues au même
 * endroit.
 *
 * Chaque endpoint a déjà son fichier, qui teste ce qu'il fait de particulier
 * (`ApiBootstrapTest`, `ApiScheduleTest`, `ApiExerciseHistoryTest`,
 * `ApiAuthEndpointsTest`, `ApiPairingTest`). Ce fichier-ci ne teste rien de
 * particulier : il teste ce qui ne dépend d'aucun endpoint — être authentifié,
 * ne pas ouvrir de session, ne pas laisser fuiter le bloc-notes privé. Ces
 * règles ne se vérifient utilement que sur la **liste entière**.
 *
 * C'est pour ça que la liste est un fournisseur de données et pas huit tests
 * copiés : un endpoint ajouté demain s'écrit une fois dans `endpoints()` et se
 * retrouve aussitôt soumis aux quatre gardes. Un endpoint oublié dans la liste
 * est le seul trou possible, et il se voit à la lecture — c'est un compromis
 * assumé, il n'existe pas de façon de dériver la liste des routes qui sache
 * aussi fabriquer une ressource valide pour chacune.
 *
 * Ce que la matrice ne couvre pas et qui reste dans les fichiers dédiés : le
 * contenu des réponses, les règles de portée coach, l'idempotence du `PUT`, les
 * refus d'appairage. Ici on ne regarde que la porte.
 */
final class ApiEndpointMatrixTest extends WebTestCase
{
    /**
     * Une sentinelle **sans accent**, et c'est délibéré. `AuthController` rend
     * ses réponses par `$this->json()` (donc sans `JSON_UNESCAPED_UNICODE`, à la
     * différence d'`ApiJson`) : une note écrite « Brouillon privé » sortirait en
     * `Brouillon privé` et `assertStringNotContainsString` passerait sur une
     * vraie fuite. Chercher une chaîne ASCII, c'est la chercher quel que soit
     * l'échappement.
     */
    private const string PRIVATE_NOTE = 'SENTINELLE-BLOC-NOTES-PRIVE';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    private User $owner;
    private User $stranger;
    private Exercise $exercise;
    private ScheduledWorkout $scheduled;
    private ScheduledWorkout $freeform;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        // Le compteur des limiteurs (connexion, appairage) vit dans un pool de
        // cache sur disque : sans ce vidage, un fichier de test qui épuise son
        // quota ferait échouer celui-ci, et l'ordre d'exécution deviendrait
        // significatif (KL-13).
        static::getContainer()->get('cache.rate_limiter')->clear();

        $this->purge();
        $this->fixtures();
    }

    /** Même précaution qu'`ApiBootstrapTest` : on rend la base telle qu'on l'a trouvée. */
    protected function tearDown(): void
    {
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purge();

        parent::tearDown();
    }

    // --- La liste ------------------------------------------------------------

    /**
     * Les endpoints qui exigent un jeton. `POST /api/auth/login` et
     * `POST /api/auth/pair` n'y sont pas : ce sont eux qui **délivrent** le
     * jeton, exiger d'en avoir un serait circulaire (`access_control` les laisse
     * publics). Ils passent quand même sous la garde du cookie, plus bas.
     *
     * `$byFirewall` distingue les routes refusées par `access_control` — donc par
     * l'entry point de `ApiTokenAuthenticator`, qui pose `WWW-Authenticate` — de
     * `POST /api/auth/logout`, qui est sous `^/api/auth` et porte sa garde dans
     * le contrôleur : sans jeton il n'y a rien à révoquer, et il le dit lui-même.
     *
     * @return iterable<string, array{string, string, array<string, mixed>|null, int, bool}>
     */
    public static function endpoints(): iterable
    {
        // [méthode, gabarit d'URL, corps JSON, statut nominal, gardé par le pare-feu]
        yield 'GET /api/ping' => ['GET', '/api/ping', null, 200, true];
        yield 'GET /api/me' => ['GET', '/api/me', null, 200, true];
        yield 'GET /api/bootstrap' => ['GET', '/api/bootstrap', null, 200, true];
        yield 'GET /api/exercises/{id}/history' => ['GET', '/api/exercises/{exercise}/history', null, 200, true];
        yield 'GET /api/schedule/{uuid}' => ['GET', '/api/schedule/{scheduled}', null, 200, true];
        yield 'PUT /api/schedule/{uuid}' => ['PUT', '/api/schedule/{scheduled}', self::DOCUMENT, 200, true];
        yield 'DELETE /api/schedule/{uuid}' => ['DELETE', '/api/schedule/{freeform}', null, 204, true];
        yield 'POST /api/auth/logout' => ['POST', '/api/auth/logout', null, 204, false];
    }

    /**
     * Le document minimal accepté par `PUT /api/schedule/{uuid}`. La `date` est
     * requise par la validation mais ignorée sur une séance connue (déplacer une
     * séance est un geste de programmation, pas de réalisé) : sa valeur en dur
     * n'a donc aucun effet sur les assertions.
     */
    private const array DOCUMENT = [
        'date' => '2026-08-02',
        'log' => [[
            'name' => 'Tractions',
            'sets' => [['uuid' => '0197f2f0-0000-7000-8000-000000000001', 'type' => 'normal', 'reps' => 8]],
        ]],
    ];

    // --- Les quatre gardes ---------------------------------------------------

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('endpoints')]
    public function testAnAnonymousCallIsRefused(string $method, string $template, ?array $body, int $nominal, bool $byFirewall): void
    {
        $this->call($method, $template, $body);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');

        if ($byFirewall) {
            self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        }
    }

    /**
     * Un jeton échu ne vaut pas mieux qu'un jeton inconnu : même 401, sans que
     * la réponse dise lequel des deux cas s'est produit (KL-10).
     *
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('endpoints')]
    public function testAnExpiredTokenIsRefused(string $method, string $template, ?array $body, int $nominal, bool $byFirewall): void
    {
        $expired = $this->issueToken($this->owner, createdAt: new \DateTimeImmutable('-91 days'));

        $this->call($method, $template, $body, $expired);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    /**
     * Révoquer, c'est supprimer la ligne (KL-11, KL-12) : la garde ne dépend
     * d'aucun état à relire, donc aucun endpoint ne peut « oublier » de la
     * consulter. Le test révoque par le vrai geste du mobile —
     * `POST /api/auth/logout` — plutôt qu'en effaçant la ligne à la main.
     *
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('endpoints')]
    public function testARevokedTokenIsRefused(string $method, string $template, ?array $body, int $nominal, bool $byFirewall): void
    {
        $secret = $this->issueToken($this->owner);

        $this->client->request('POST', '/api/auth/logout', server: self::bearer($secret));
        self::assertResponseStatusCodeSame(204);

        $this->call($method, $template, $body, $secret);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Le cas nominal, pour que les trois refus ci-dessus prouvent quelque chose :
     * sans lui, un endpoint cassé rendrait 401 partout et passerait la matrice.
     *
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('endpoints')]
    public function testTheNominalCallSucceeds(string $method, string $template, ?array $body, int $nominal, bool $byFirewall): void
    {
        $this->call($method, $template, $body, $this->issueToken($this->owner));

        self::assertResponseStatusCodeSame($nominal);
    }

    /**
     * `stateless: true` sur `^/api` (KL-10). Vérifié sur les **deux** issues :
     * une réponse d'erreur traverse un autre chemin que la réponse nominale
     * (l'entry point de l'authenticator, `ApiExceptionListener`), et c'est
     * exactement là qu'une session pourrait s'ouvrir sans qu'on la voie.
     *
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('endpoints')]
    public function testNoCookieIsEverSet(string $method, string $template, ?array $body, int $nominal, bool $byFirewall): void
    {
        $this->call($method, $template, $body);
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'Refus : '.$template);
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));

        $this->call($method, $template, $body, $this->issueToken($this->owner));
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'Succès : '.$template);
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));
    }

    /**
     * Les deux endpoints publics ne font pas exception : ils sont sous `^/api`,
     * donc sous le pare-feu stateless. Une session ouverte ici serait la porte
     * dérobée la plus discrète possible — le mobile enverrait ensuite un cookie
     * qu'aucune révocation d'appareil n'atteindrait.
     */
    public function testThePublicAuthEndpointsSetNoCookieEither(): void
    {
        $credentials = ['email' => 'athlete@example.com', 'password' => 'password', 'deviceName' => 'Pixel de test'];

        $this->post('/api/auth/login', [...$credentials, 'password' => 'mauvais']);
        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());

        $this->post('/api/auth/login', $credentials);
        self::assertResponseStatusCodeSame(201);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());

        $this->post('/api/auth/pair', ['code' => 'INCONNU1', 'deviceName' => 'Pixel de test']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    // --- Le bloc-notes privé -------------------------------------------------

    /**
     * `Workout.notes` est le fourre-tout du propriétaire seul : il n'entre pas
     * dans `PlanFlattener`, donc ni dans l'export Excel, ni dans l'ICS, ni dans
     * la page publique — et l'API n'y fait pas exception (`CLAUDE.md` §3).
     *
     * `ApiBootstrapTest` le vérifie sur le bootstrap, qui est l'endroit où la
     * fuite serait le plus probable. Ici on le vérifie sur **tout**, y compris
     * sur les endpoints qui n'ont aucune raison de porter une séance : c'est ce
     * qui rend le test utile plus tard, quand la liste aura grandi. La consigne
     * d'un exercice prescrit, elle, sort — elle s'adresse à qui exécute.
     */
    public function testThePrivateNotesNeverReachAnyEndpoint(): void
    {
        $this->scheduled->getWorkout()?->setNotes(self::PRIVATE_NOTE.' : alléger la semaine 3.');
        $this->em->flush();

        $secret = $this->issueToken($this->owner);
        $seen = 0;

        foreach (self::endpoints() as $name => [$method, $template, $body, $nominal]) {
            // Une réponse vide n'a rien à cacher, et `DELETE` détruirait la
            // séance libre dont les appels suivants ont besoin.
            if (204 === $nominal) {
                continue;
            }

            $this->call($method, $template, $body, $secret);

            self::assertResponseStatusCodeSame($nominal);
            self::assertStringNotContainsString(
                self::PRIVATE_NOTE,
                (string) $this->client->getResponse()->getContent(),
                $name.' laisse fuiter le bloc-notes privé.',
            );
            ++$seen;
        }

        // Garde-fou du garde-fou : une boucle qui ne tourne pas passe aussi.
        self::assertGreaterThan(4, $seen);
    }

    /** Et la consigne d'un exercice prescrit, elle, doit bien descendre. */
    public function testThePrescribedNoteDoesReachTheApi(): void
    {
        $raw = $this->call('GET', '/api/schedule/{scheduled}', null, $this->issueToken($this->owner));

        self::assertStringContainsString('Coudes serrés', $raw);
    }

    // --- La ressource d'un autre --------------------------------------------

    /**
     * Les deux règles de refus coexistent, et elles ne se contredisent pas : ce
     * qui décide, c'est la **nature de la clé**. Un `uuid` posé par le client ne
     * se devine pas, donc 403 (et un coach dont la relation vient d'être rompue
     * comprend ce qui lui arrive) ; un identifiant séquentiel d'exercice
     * s'énumère, et un 403 y dirait la taille de la bibliothèque perso des
     * autres, donc 404.
     */
    public function testAStrangerIsRefusedOnEveryResourceEndpoint(): void
    {
        $secret = $this->issueToken($this->stranger);

        $this->call('GET', '/api/schedule/{scheduled}', null, $secret);
        self::assertResponseStatusCodeSame(403);

        $this->call('PUT', '/api/schedule/{scheduled}', self::DOCUMENT, $secret);
        self::assertResponseStatusCodeSame(403);

        $this->call('DELETE', '/api/schedule/{freeform}', null, $secret);
        self::assertResponseStatusCodeSame(403);

        $this->call('GET', '/api/exercises/{exercise}/history', null, $secret);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Le corollaire du refus : rien n'a bougé. Un 403 rendu **après** l'écriture
     * serait un test vert sur une fuite.
     */
    public function testARefusedWriteChangesNothing(): void
    {
        $before = $this->em->getRepository(LoggedSet::class)->count([]);

        $this->call('PUT', '/api/schedule/{scheduled}', self::DOCUMENT, $this->issueToken($this->stranger));
        self::assertResponseStatusCodeSame(403);

        $this->call('DELETE', '/api/schedule/{freeform}', null, $this->issueToken($this->stranger));
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        self::assertSame($before, $this->em->getRepository(LoggedSet::class)->count([]));
        self::assertCount(2, $this->em->getRepository(ScheduledWorkout::class)->findAll());
    }

    // --- Utilitaires ---------------------------------------------------------

    /**
     * Joue un endpoint de la liste et rend le corps brut. Les gabarits sont
     * résolus ici parce qu'un fournisseur de données est statique : il ne peut
     * pas connaître des fixtures qui n'existent pas encore quand il s'évalue.
     *
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, string $template, ?array $body, ?string $secret = null): string
    {
        $uri = strtr($template, [
            '{exercise}' => (string) $this->exercise->getId(),
            '{scheduled}' => (string) $this->scheduled->getUuid(),
            '{freeform}' => (string) $this->freeform->getUuid(),
        ]);

        $server = null === $secret ? [] : self::bearer($secret);

        if (null !== $body) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        $this->client->request(
            $method,
            $uri,
            server: $server,
            content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
        );

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $uri, array $body): void
    {
        $this->client->request(
            'POST',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function bearer(string $secret): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$secret];
    }

    private function issueToken(User $owner, ?\DateTimeImmutable $createdAt = null): string
    {
        $secret = ApiToken::generateSecret();

        $this->em->persist(new ApiToken($owner, 'Pixel de test', $secret, $createdAt));
        $this->em->flush();

        return $secret;
    }

    /**
     * Deux comptes, un exercice, une séance datée avec son programme et son
     * réalisé, une séance libre (la seule que `DELETE` accepte de supprimer).
     */
    private function fixtures(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->owner = (new User())->setEmail('athlete@example.com');
        $this->owner->setPassword($hasher->hashPassword($this->owner, 'password'));
        $this->stranger = (new User())->setEmail('stranger@example.com')->setPassword('x');
        $this->em->persist($this->owner);
        $this->em->persist($this->stranger);

        $this->exercise = (new Exercise())
            ->setOwner($this->owner)
            ->setName('Développé couché')
            ->setActivity(ActivityType::GYM);
        $this->em->persist($this->exercise);

        $workout = (new Workout())
            ->setOwner($this->owner)
            ->setTitle('Haut du corps')
            ->setSlug('haut-du-corps-'.bin2hex(random_bytes(6)));
        $this->em->persist($workout);

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $workout->addBlock($block);
        $this->em->persist($block);

        $prescribed = (new PrescribedExercise())
            ->setExercise($this->exercise)
            ->setPosition(0)
            ->setPrescriptionType(PrescriptionType::SETS_REPS)
            ->setSets(3)->setReps(8)->setWeightKg(80.0)
            ->setNotes('Coudes serrés.');
        $block->addPrescribedExercise($prescribed);

        $this->scheduled = (new ScheduledWorkout())
            ->setOwner($this->owner)
            ->setWorkout($workout)
            ->setScheduledDate(new \DateTimeImmutable('-2 days'))
            ->setStatus(ScheduledStatus::DONE);
        $this->scheduled->addLoggedExercise(
            (new LoggedExercise())
                ->setExerciseName('Développé couché')
                ->setExercise($this->exercise)
                ->setSourcePrescribedExercise($prescribed)
                ->setPosition(0)
                ->addLoggedSet((new LoggedSet())->setPosition(0)->setSetType(SetType::NORMAL)->setReps(8)->setWeightKg(80.0)),
        );
        $this->em->persist($this->scheduled);

        $this->freeform = (new ScheduledWorkout())
            ->setOwner($this->owner)
            ->setTitle('Muscu improvisée')
            ->setScheduledDate(new \DateTimeImmutable('-1 day'))
            ->setStatus(ScheduledStatus::DONE);
        $this->em->persist($this->freeform);

        $this->em->flush();
    }

    private function purge(): void
    {
        foreach ([ApiToken::class, ScheduledWorkout::class, Coaching::class, PlanTemplate::class, Workout::class, Exercise::class, User::class] as $class) {
            foreach ($this->em->getRepository($class)->findAll() as $entity) {
                $this->em->remove($entity);
            }
        }
        $this->em->flush();

        // En dernier : le ménage ci-dessus vient lui-même d'écrire des pierres
        // tombales (`TombstoneListener`, KL-14).
        foreach ($this->em->getRepository(DeletedEntity::class)->findAll() as $tombstone) {
            $this->em->remove($tombstone);
        }
        $this->em->flush();
    }
}
