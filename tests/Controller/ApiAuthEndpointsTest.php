<?php

namespace App\Tests\Controller;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Les endpoints d'authentification de l'API (KL-11) : `POST /api/auth/login`,
 * `POST /api/auth/logout`, `GET /api/me`.
 *
 * Le test qui porte le ticket est `testUnknownEmailAndWrongPasswordAnswerTheSame` :
 * une connexion qui distingue « email inconnu » de « mot de passe faux » est un
 * oracle d'existence de compte. Vient ensuite
 * `testTheSecretIsReturnedOnlyOnceAndNeverStored` : le secret sort de la réponse
 * de connexion et de nulle part ailleurs.
 *
 * Le pare-feu lui-même est gardé par `ApiAuthenticationTest` (KL-10).
 */
final class ApiAuthEndpointsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        // La connexion est limitée à 5 tentatives par IP et par minute (KL-13),
        // et le compteur vit dans un pool de cache **sur disque** : sans ce
        // vidage, un test qui épuise le quota ferait échouer les suivants et
        // l'ordre des tests deviendrait significatif. Le passer en mémoire ne
        // marcherait pas — un `ArrayAdapter` est remis à zéro entre deux requêtes
        // du même test par le `services_resetter`.
        static::getContainer()->get('cache.rate_limiter')->clear();

        foreach ($this->em->getRepository(ApiToken::class)->findAll() as $token) {
            $this->em->remove($token);
        }
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            $this->em->remove($user);
        }
        $this->em->flush();
    }

    // --- Connexion ----------------------------------------------------------

    public function testLoginReturnsATokenThatAuthenticatesTheApi(): void
    {
        $this->createUser('athlete@example.com');

        $this->post('/api/auth/login', [
            'email' => 'athlete@example.com',
            'password' => 'password',
            'deviceName' => 'Pixel 8',
        ]);

        // 201 : l'appel enregistre un appareil, il ne fait pas que lire.
        self::assertResponseStatusCodeSame(201);
        $payload = $this->json();

        self::assertArrayHasKey('token', $payload);
        self::assertSame('athlete@example.com', $payload['user']['email']);
        self::assertContains('ROLE_USER', $payload['user']['roles']);
        self::assertFalse($payload['user']['coach']);

        // Le jeton rendu ouvre bien l'API.
        $this->client->request('GET', '/api/ping', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$payload['token']]);
        self::assertResponseIsSuccessful();
    }

    public function testLoginRegistersTheDeviceName(): void
    {
        $user = $this->createUser('athlete@example.com');

        $this->post('/api/auth/login', [
            'email' => 'athlete@example.com',
            'password' => 'password',
            'deviceName' => 'Pixel 8 de salle',
        ]);

        self::assertResponseStatusCodeSame(201);

        $tokens = $this->em->getRepository(ApiToken::class)->findForOwner($user);
        self::assertCount(1, $tokens);
        self::assertSame('Pixel 8 de salle', $tokens[0]->getDeviceName());
        // Un appareil qui vient de s'appairer n'a pas encore synchronisé.
        self::assertNull($tokens[0]->getLastBootstrapAt());
    }

    /**
     * Le test qui porte le ticket : la connexion ne doit rien apprendre sur
     * l'existence d'un compte. Même statut, même corps, au caractère près.
     */
    public function testUnknownEmailAndWrongPasswordAnswerTheSame(): void
    {
        $this->createUser('athlete@example.com');

        $this->post('/api/auth/login', [
            'email' => 'athlete@example.com',
            'password' => 'mauvais-mot-de-passe',
            'deviceName' => 'Pixel 8',
        ]);
        self::assertResponseStatusCodeSame(401);
        $wrongPassword = $this->client->getResponse()->getContent();

        $this->post('/api/auth/login', [
            'email' => 'personne@example.com',
            'password' => 'password',
            'deviceName' => 'Pixel 8',
        ]);
        self::assertResponseStatusCodeSame(401);

        self::assertSame($wrongPassword, $this->client->getResponse()->getContent());
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testFailedLoginIssuesNoToken(): void
    {
        $this->createUser('athlete@example.com');

        $this->post('/api/auth/login', [
            'email' => 'athlete@example.com',
            'password' => 'mauvais-mot-de-passe',
            'deviceName' => 'Pixel 8',
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->em->getRepository(ApiToken::class)->findAll());
    }

    /**
     * Le secret n'existe que dans la réponse qui le crée. Ni la base, ni `/api/me`
     * ne permettent de le retrouver.
     */
    public function testTheSecretIsReturnedOnlyOnceAndNeverStored(): void
    {
        $this->createUser('athlete@example.com');

        $this->post('/api/auth/login', [
            'email' => 'athlete@example.com',
            'password' => 'password',
            'deviceName' => 'Pixel 8',
        ]);
        $secret = $this->json()['token'];

        $stored = $this->em->getConnection()->fetchOne('SELECT token_hash FROM api_token');
        self::assertNotSame($secret, $stored);
        self::assertSame(hash('sha256', $secret), $stored);

        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($secret, $this->client->getResponse()->getContent());
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLoginBodies')]
    public function testInvalidBodiesAreRejected(array $body): void
    {
        $this->createUser('athlete@example.com');

        $this->post('/api/auth/login', $body);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidLoginBodies(): iterable
    {
        yield 'corps vide' => [[]];
        yield 'sans mot de passe' => [['email' => 'athlete@example.com', 'deviceName' => 'Pixel 8']];
        yield 'sans nom d\'appareil' => [['email' => 'athlete@example.com', 'password' => 'password']];
        yield 'nom d\'appareil blanc' => [['email' => 'athlete@example.com', 'password' => 'password', 'deviceName' => '   ']];
        yield 'email non textuel' => [['email' => 42, 'password' => 'password', 'deviceName' => 'Pixel 8']];
    }

    /**
     * La borne du VARCHAR(100) se refuse ici, pas en base : une chaîne trop longue
     * doit rendre 400, jamais une erreur SQL en 500.
     */
    public function testAnOverlongDeviceNameIsRejected(): void
    {
        $this->createUser('athlete@example.com');

        $this->post('/api/auth/login', [
            'email' => 'athlete@example.com',
            'password' => 'password',
            'deviceName' => str_repeat('a', 101),
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->em->getRepository(ApiToken::class)->findAll());
    }

    public function testMalformedJsonIsRejected(): void
    {
        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{ceci-n-est-pas-du-json',
        );

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Le contrat client de KL-11 : l'authenticator se déclenche sur la seule
     * présence d'un `Bearer`, y compris sur une route publique. Un jeton périmé
     * présenté à la connexion la fait donc échouer **avant** le contrôleur — le
     * mobile doit effacer son jeton local avant de se reconnecter.
     */
    public function testAnInvalidBearerBreaksLoginBeforeTheController(): void
    {
        $this->createUser('athlete@example.com');

        $this->client->request(
            'POST',
            '/api/auth/login',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ce-jeton-n-a-jamais-existe',
            ],
            content: json_encode([
                'email' => 'athlete@example.com',
                'password' => 'password',
                'deviceName' => 'Pixel 8',
            ]),
        );

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->em->getRepository(ApiToken::class)->findAll());
    }

    /**
     * Le mot de passe est le seul secret de l'API choisi par un humain : c'est
     * celui qu'on essaie en boucle. Cinq tentatives par IP et par minute, plus
     * serré que l'appairage, dont le code se retape après une faute de frappe.
     */
    public function testLoginIsRateLimitedByIp(): void
    {
        $this->createUser('athlete@example.com');

        for ($i = 0; $i < 5; ++$i) {
            $this->post('/api/auth/login', [
                'email' => 'athlete@example.com',
                'password' => 'mauvais-mot-de-passe',
                'deviceName' => 'Pixel 8',
            ]);
            self::assertResponseStatusCodeSame(401);
        }

        $this->post('/api/auth/login', [
            'email' => 'athlete@example.com',
            'password' => 'mauvais-mot-de-passe',
            'deviceName' => 'Pixel 8',
        ]);

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
    }

    /**
     * Le quota se refuse **avant** de regarder le compte : une fois épuisé, le
     * bon mot de passe ne passe pas davantage, et aucun jeton n'est émis. C'est
     * ce qui fait du limiteur une garde et pas un ralentisseur.
     */
    public function testAThrottledLoginIssuesNoTokenEvenWithTheRightPassword(): void
    {
        $this->createUser('athlete@example.com');

        for ($i = 0; $i < 5; ++$i) {
            $this->post('/api/auth/login', [
                'email' => 'athlete@example.com',
                'password' => 'mauvais-mot-de-passe',
                'deviceName' => 'Pixel 8',
            ]);
        }

        $this->post('/api/auth/login', [
            'email' => 'athlete@example.com',
            'password' => 'password',
            'deviceName' => 'Pixel 8',
        ]);

        self::assertResponseStatusCodeSame(429);
        self::assertSame([], $this->em->getRepository(ApiToken::class)->findAll());
    }

    // --- Déconnexion --------------------------------------------------------

    public function testLogoutRevokesTheTokenPresented(): void
    {
        $user = $this->createUser('athlete@example.com');
        $secret = $this->issueToken($user);

        $this->client->request('POST', '/api/auth/logout', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);

        self::assertResponseStatusCodeSame(204);
        self::assertEmpty($this->client->getResponse()->getContent());

        // Le même jeton ne rouvre plus rien.
        $this->client->request('GET', '/api/ping', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Déconnecter un téléphone ne déconnecte pas les autres : « tout révoquer »
     * est un geste explicite, il vivra dans `/profile/settings` (KL-12).
     */
    public function testLogoutLeavesTheOtherDevicesAlone(): void
    {
        $user = $this->createUser('athlete@example.com');
        $phone = $this->issueToken($user, deviceName: 'Pixel 8');
        $tablet = $this->issueToken($user, deviceName: 'Tablette');

        $this->client->request('POST', '/api/auth/logout', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$phone]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/ping', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tablet]);
        self::assertResponseIsSuccessful();
    }

    public function testLogoutWithoutATokenIsUnauthorized(): void
    {
        $this->client->request('POST', '/api/auth/logout');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    // --- Identité -----------------------------------------------------------

    public function testMeDescribesTheUserAndTheCurrentDevice(): void
    {
        $user = $this->createUser('coach@example.com', ['ROLE_COACH']);
        $secret = $this->issueToken($user, deviceName: 'Pixel 8');

        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);

        self::assertResponseIsSuccessful();
        $payload = $this->json();

        self::assertSame('coach@example.com', $payload['user']['email']);
        self::assertSame($user->getId(), $payload['user']['id']);
        self::assertContains('ROLE_COACH', $payload['user']['roles']);
        self::assertTrue($payload['user']['coach']);

        self::assertSame('Pixel 8', $payload['device']['name']);
        // L'authenticator a noté l'usage de cette requête même.
        self::assertNotNull($payload['device']['lastUsedAt']);
        self::assertNotNull($payload['device']['expiresAt']);
        // Aucun bootstrap n'a eu lieu : « appairé » n'est pas « synchronisé ».
        self::assertNull($payload['device']['lastBootstrapAt']);
    }

    public function testMeRequiresAToken(): void
    {
        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Le pendant du test de KL-10 sur `/api/ping` : une session web n'ouvre pas
     * davantage l'identité de l'API — et c'est ici que ça compte le plus, `/api/me`
     * étant la seule route qui divulgue un email.
     *
     * **Piège de test** : la requête web intermédiaire n'est pas décorative.
     * `loginUser()` pose le jeton dans le `token_storage` du conteneur *en plus*
     * du cookie ; tant que le noyau n'a pas redémarré, ce jeton résiduel traverse
     * n'importe quel pare-feu, stateless compris, et le test passerait pour la
     * mauvaise raison. Une requête intercalée le purge, et ce qui reste — le seul
     * cookie — est bien ce qu'on prétend tester.
     */
    public function testMeIgnoresAWebSession(): void
    {
        $user = $this->createUser('athlete@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/profile/settings');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
    }

    // --- Utilitaires --------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $uri, array $body): void
    {
        $this->client->request(
            'POST',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function issueToken(User $owner, string $deviceName = 'Pixel de test'): string
    {
        $secret = ApiToken::generateSecret();

        $this->em->persist(new ApiToken($owner, $deviceName, $secret));
        $this->em->flush();

        return $secret;
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles = []): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())->setEmail($email)->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'password'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
