<?php

namespace App\Tests\Controller;

use App\Entity\ApiToken;
use App\Entity\PairingCode;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * L'appairage par QR (KL-46) : `POST /pairing/code` côté desktop,
 * `POST /api/auth/pair` côté téléphone.
 *
 * Deux tests portent le ticket. `testACodeCannotBeConsumedTwice` : l'usage
 * unique est une garantie de la base, sinon une photo du QR vaudrait plusieurs
 * appairages. `testTheTokenBelongsToTheIssuerNotToTheCaller` : le compte vient
 * du code, jamais de la requête — c'est ce qui interdit de s'appairer chez un
 * autre en devinant huit caractères.
 */
final class ApiPairingTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        // Le compteur du limiteur vit dans un pool de cache **sur disque** : sans
        // ce vidage, un test qui épuise le quota ferait échouer les suivants et
        // l'ordre des tests deviendrait significatif. Le mettre en mémoire ne
        // marcherait pas : un `ArrayAdapter` est remis à zéro entre deux requêtes
        // du même test par le `services_resetter`, et le quota ne compterait plus.
        static::getContainer()->get('cache.rate_limiter')->clear();

        foreach ($this->em->getRepository(PairingCode::class)->findAll() as $code) {
            $this->em->remove($code);
        }
        foreach ($this->em->getRepository(ApiToken::class)->findAll() as $token) {
            $this->em->remove($token);
        }
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            $this->em->remove($user);
        }
        $this->em->flush();
    }

    // --- Émission du code (desktop) -----------------------------------------

    public function testAnAuthenticatedUserIssuesACodeAndTheQrPayload(): void
    {
        $user = $this->createUser('athlete@example.com');
        $this->client->loginUser($user);

        $this->issueCode();

        self::assertResponseIsSuccessful();
        $payload = $this->json();

        self::assertSame(PairingCode::LENGTH, \strlen($payload['code']));
        // L'URL du serveur voyage avec le code : zéro saisie sur le téléphone.
        self::assertSame('http://localhost', $payload['url']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $payload['exp']));

        $codes = $this->em->getRepository(PairingCode::class)->findAll();
        self::assertCount(1, $codes);
        self::assertSame($user->getId(), $codes[0]->getOwner()->getId());
    }

    /**
     * Le QR ne porte que le code (§0.6 règle 1). S'il portait un jeton, une photo
     * de l'écran vaudrait un accès permanent au compte.
     */
    public function testTheQrPayloadCarriesNoToken(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));

        $this->issueCode();

        $payload = $this->json();
        self::assertArrayNotHasKey('token', $payload);
        self::assertSame([], $this->em->getRepository(ApiToken::class)->findAll());
    }

    /**
     * La base ne stocke que l'empreinte, comme pour `ApiToken` : le code clair
     * n'existe que dans la réponse et sur l'écran.
     */
    public function testTheCodeIsStoredHashed(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));

        $this->issueCode();
        $code = $this->json()['code'];

        $stored = $this->em->getConnection()->fetchOne('SELECT code_hash FROM pairing_code');
        self::assertNotSame($code, $stored);
        self::assertSame(hash('sha256', $code), $stored);
    }

    /**
     * Le code fait huit caractères dans un alphabet sans ambiguïté de lecture
     * (§0.6 règle 4) : il doit rester saisissable à la main si la caméra refuse.
     */
    public function testTheCodeAvoidsAmbiguousCharacters(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));

        // Un seul tirage ne prouverait rien sur un alphabet de 32 symboles.
        for ($i = 0; $i < 40; ++$i) {
            $this->issueCode();
            self::assertMatchesRegularExpression('/^[2-9A-HJ-NP-Z]{8}$/', $this->json()['code']);
        }
    }

    /**
     * Un écran, un code : régénérer invalide le précédent, qui resterait sinon
     * échangeable deux minutes sur un poste qu'on vient de quitter.
     */
    public function testIssuingANewCodeInvalidatesThePreviousOne(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));

        $this->issueCode();
        $first = $this->json()['code'];

        $this->issueCode();
        self::assertNotSame($first, $this->json()['code']);
        self::assertCount(1, $this->em->getRepository(PairingCode::class)->findAll());

        $this->pair($first);
        self::assertResponseStatusCodeSame(400);
    }

    public function testIssuingACodeRequiresAWebSession(): void
    {
        $this->client->request('POST', '/pairing/code');

        self::assertResponseRedirects();
        self::assertSame([], $this->em->getRepository(PairingCode::class)->findAll());
    }

    public function testIssuingACodeRequiresAValidCsrfToken(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));

        $this->client->request('POST', '/pairing/code', ['_token' => 'faux-jeton']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->em->getRepository(PairingCode::class)->findAll());
    }

    // --- Échange du code (téléphone) ----------------------------------------

    public function testPairingExchangesTheCodeForAToken(): void
    {
        $user = $this->createUser('athlete@example.com');
        $code = $this->issueCodeFor($user);

        $this->pair($code, 'Pixel 8');

        // 201 comme la connexion : l'appel enregistre un appareil.
        self::assertResponseStatusCodeSame(201);
        $payload = $this->json();

        self::assertSame('athlete@example.com', $payload['user']['email']);

        // Le jeton rendu ouvre bien l'API, et il porte le nom d'appareil donné.
        $this->client->request('GET', '/api/ping', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$payload['token']]);
        self::assertResponseIsSuccessful();

        $tokens = $this->em->getRepository(ApiToken::class)->findForOwner($user);
        self::assertCount(1, $tokens);
        self::assertSame('Pixel 8', $tokens[0]->getDeviceName());
    }

    /**
     * Le test qui porte le ticket : deux scans du même QR, un seul jeton.
     * L'`UPDATE ... WHERE used_at IS NULL` est ce qui le garantit — une lecture
     * suivie d'une écriture laisserait les deux passer.
     */
    public function testACodeCannotBeConsumedTwice(): void
    {
        $user = $this->createUser('athlete@example.com');
        $code = $this->issueCodeFor($user);

        $this->pair($code, 'Pixel 8');
        self::assertResponseStatusCodeSame(201);

        $this->pair($code, 'Téléphone du voisin');
        self::assertResponseStatusCodeSame(400);

        self::assertCount(1, $this->em->getRepository(ApiToken::class)->findForOwner($user));
    }

    /**
     * L'autre test qui porte le ticket : le compte vient du code, pas de la
     * requête. Le téléphone ne choisit jamais à qui il se rattache (§0.6 règle 3).
     */
    public function testTheTokenBelongsToTheIssuerNotToTheCaller(): void
    {
        $issuer = $this->createUser('emetteur@example.com');
        $other = $this->createUser('autre@example.com');
        $code = $this->issueCodeFor($issuer);

        // Ni le corps de la requête ni une session web ouverte sur un autre
        // compte ne désignent le titulaire du jeton : seul le code le fait.
        $this->client->loginUser($other);
        $this->pair($code, 'Pixel 8', ['email' => 'autre@example.com']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('emetteur@example.com', $this->json()['user']['email']);
        self::assertCount(1, $this->em->getRepository(ApiToken::class)->findForOwner($issuer));
        self::assertCount(0, $this->em->getRepository(ApiToken::class)->findForOwner($other));
    }

    /**
     * L'appairage consigne qui a consommé le code : c'est ce que le desktop
     * affiche en confirmation (KL-47), et ça survit à la révocation du jeton.
     */
    public function testPairingRecordsTheConsumingDevice(): void
    {
        $user = $this->createUser('athlete@example.com');
        $code = $this->issueCodeFor($user);

        $this->pair($code, 'Pixel 8');
        self::assertResponseStatusCodeSame(201);

        $stored = $this->em->getRepository(PairingCode::class)->findAll()[0];
        $this->em->refresh($stored);

        self::assertTrue($stored->isUsed());
        self::assertSame('Pixel 8', $stored->getConsumedByDevice());
    }

    /**
     * Un code expiré ne s'échange plus. La borne est dans le `WHERE` de
     * l'`UPDATE`, elle ne peut donc pas être vraie au test et fausse à l'écriture.
     */
    public function testAnExpiredCodeIsRefused(): void
    {
        $user = $this->createUser('athlete@example.com');
        $code = $this->issueCodeFor($user);

        $this->em->getConnection()->executeStatement(
            'UPDATE pairing_code SET expires_at = :past',
            ['past' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s')],
        );

        $this->pair($code);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->em->getRepository(ApiToken::class)->findAll());
    }

    /**
     * Inconnu, expiré, déjà utilisé : la même réponse, au caractère près. Les
     * distinguer dirait à qui devine un code s'il a visé juste.
     */
    public function testUnknownExpiredAndUsedCodesAnswerTheSame(): void
    {
        $user = $this->createUser('athlete@example.com');

        $this->pair('ZZZZZZZZ');
        self::assertResponseStatusCodeSame(400);
        $unknown = $this->client->getResponse()->getContent();

        $used = $this->issueCodeFor($user);
        $this->pair($used);
        self::assertResponseStatusCodeSame(201);
        $this->pair($used);
        self::assertSame($unknown, $this->client->getResponse()->getContent());

        $expired = $this->issueCodeFor($user);
        $this->em->getConnection()->executeStatement(
            'UPDATE pairing_code SET expires_at = :past WHERE code_hash = :hash',
            [
                'past' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
                'hash' => PairingCode::hash($expired),
            ],
        );
        $this->pair($expired);
        self::assertSame($unknown, $this->client->getResponse()->getContent());
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    /**
     * Le repli clavier : le code s'affiche en majuscules, il se tape comme il
     * vient. Sans normalisation, l'erreur uniforme rendrait la panne indéchiffrable.
     */
    public function testTheCodeIsAcceptedInLowercaseAndPadded(): void
    {
        $user = $this->createUser('athlete@example.com');
        $code = $this->issueCodeFor($user);

        $this->pair('  '.strtolower($code).' ');

        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPairBodies')]
    public function testInvalidPairBodiesAreRejected(array $body): void
    {
        $this->client->request(
            'POST',
            '/api/auth/pair',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body),
        );

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPairBodies(): iterable
    {
        yield 'corps vide' => [[]];
        yield 'sans code' => [['deviceName' => 'Pixel 8']];
        yield 'sans nom d\'appareil' => [['code' => 'ABCD2345']];
        yield 'nom d\'appareil blanc' => [['code' => 'ABCD2345', 'deviceName' => '   ']];
        yield 'code non textuel' => [['code' => 42, 'deviceName' => 'Pixel 8']];
        yield 'nom d\'appareil trop long' => [['code' => 'ABCD2345', 'deviceName' => str_repeat('a', 101)]];
    }

    /**
     * Huit caractères sur 32 symboles, c'est 40 bits : assez pour ne pas se
     * deviner, pas assez pour encaisser une force brute non bridée. Le limiteur
     * est une pièce du modèle de sécurité, pas un confort.
     */
    public function testPairingIsRateLimitedByIp(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->pair('ZZZZZZZZ');
            self::assertResponseStatusCodeSame(400);
        }

        $this->pair('ZZZZZZZZ');

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
    }

    /**
     * Le quota se refuse avant la base : un appairage valide bloqué par un quota
     * épuisé ne doit pas non plus consommer le code.
     */
    public function testAThrottledPairingDoesNotConsumeTheCode(): void
    {
        $user = $this->createUser('athlete@example.com');
        $code = $this->issueCodeFor($user);

        for ($i = 0; $i < 10; ++$i) {
            $this->pair('ZZZZZZZZ');
        }

        $this->pair($code);
        self::assertResponseStatusCodeSame(429);

        $stored = $this->em->getRepository(PairingCode::class)->findAll()[0];
        $this->em->refresh($stored);
        self::assertFalse($stored->isUsed());
    }

    // --- Utilitaires --------------------------------------------------------

    /** Émet un code depuis la session desktop de `$owner` et le rend. */
    private function issueCodeFor(User $owner): string
    {
        $this->client->loginUser($owner);
        $this->issueCode();

        return $this->json()['code'];
    }

    /**
     * Émet un code par le vrai endpoint, CSRF compris. Le jeton est posé
     * directement dans la session : le formulaire qui le rendra vit en KL-47, et
     * un test qui contournerait la garde CSRF ne prouverait pas qu'elle existe
     * (c'est ce que `testIssuingACodeRequiresAValidCsrfToken` vérifie de l'autre
     * côté).
     */
    private function issueCode(): void
    {
        // Une requête d'abord : sans elle, il n'y a pas encore de session où
        // écrire, `loginUser()` ne posant qu'un cookie.
        $this->client->request('GET', '/profile/settings');

        $session = $this->client->getRequest()->getSession();
        $session->set('_csrf/pairing_code', 'jeton-csrf-de-test');
        $session->save();

        $this->client->request('POST', '/pairing/code', ['_token' => 'jeton-csrf-de-test']);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function pair(string $code, string $deviceName = 'Pixel de test', array $extra = []): void
    {
        $this->client->request(
            'POST',
            '/api/auth/pair',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($extra + ['code' => $code, 'deviceName' => $deviceName]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
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
}
