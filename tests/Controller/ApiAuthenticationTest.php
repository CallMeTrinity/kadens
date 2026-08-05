<?php

namespace App\Tests\Controller;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Le pare-feu `api` (KL-10) : jeton porteur, stateless, expiration glissante.
 *
 * Le test qui porte le ticket est `testSessionCookieDoesNotAuthenticateTheApi` :
 * il garde l'ordre des pare-feux dans security.yaml. Si `^/api` retombait dans
 * `main`, une session (ou le cookie remember_me à dix ans) suffirait à passer, le
 * jeton ne servirait plus à rien et révoquer un appareil n'aurait aucun effet.
 */
final class ApiAuthenticationTest extends WebTestCase
{
    use PurgesDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        $this->purgeDatabase($this->em);
    }

    public function testValidTokenAuthenticates(): void
    {
        $user = $this->createUser('athlete@example.com');
        $secret = $this->issueToken($user);

        $this->request($secret);

        self::assertResponseIsSuccessful();
        self::assertJson($this->client->getResponse()->getContent());
        self::assertSame('athlete@example.com', json_decode($this->client->getResponse()->getContent(), true)['user']);
    }

    /**
     * La garde du `stateless: true` : une requête d'API ne doit jamais ouvrir de
     * session, sinon chaque appel du mobile en créerait une côté serveur.
     */
    public function testApiNeverSetsASessionCookie(): void
    {
        $user = $this->createUser('athlete@example.com');
        $secret = $this->issueToken($user);

        $this->request($secret);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));
    }

    /**
     * Le piège du ticket. Un utilisateur connecté sur le web reste anonyme pour
     * l'API : son cookie n'y est pas lu.
     */
    public function testSessionCookieDoesNotAuthenticateTheApi(): void
    {
        $user = $this->createUser('athlete@example.com');
        $this->client->loginUser($user);

        // La session est bien active sur le pare-feu `main` (sans elle, /calendar
        // renverrait vers /login au lieu du mois courant).
        $this->client->request('GET', '/calendar');
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/ping');
        self::assertResponseStatusCodeSame(401);
    }

    public function testMissingTokenIsRejected(): void
    {
        $this->client->request('GET', '/api/ping');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
    }

    public function testUnknownTokenIsRejected(): void
    {
        $this->createUser('athlete@example.com');

        $this->request('ce-jeton-n-a-jamais-existe');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testExpiredTokenIsRejected(): void
    {
        $user = $this->createUser('athlete@example.com');
        $secret = $this->issueToken($user, new \DateTimeImmutable('-91 days'));

        $this->request($secret);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Expiration glissante : chaque usage note la date et repousse l'échéance de
     * 90 jours. Un téléphone dont on se sert ne se déconnecte jamais.
     */
    public function testUsageSlidesTheExpiry(): void
    {
        $user = $this->createUser('athlete@example.com');
        $secret = $this->issueToken($user, new \DateTimeImmutable('-30 days'));

        $before = $this->em->getRepository(ApiToken::class)->findOneByPlainToken($secret);
        self::assertNull($before->getLastUsedAt());
        $initialExpiry = $before->getExpiresAt();

        $this->request($secret);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $after = $this->em->getRepository(ApiToken::class)->findOneByPlainToken($secret);

        self::assertNotNull($after->getLastUsedAt());
        self::assertGreaterThan($initialExpiry, $after->getExpiresAt());
        self::assertGreaterThan(new \DateTimeImmutable('+89 days'), $after->getExpiresAt());
    }

    /** Le secret ne doit exister nulle part en base, seulement son empreinte. */
    public function testOnlyTheHashIsStored(): void
    {
        $user = $this->createUser('athlete@example.com');
        $secret = $this->issueToken($user);

        $stored = $this->em->getConnection()
            ->fetchOne('SELECT token_hash FROM api_token WHERE owner_id = ?', [$user->getId()]);

        self::assertNotSame($secret, $stored);
        self::assertSame(hash('sha256', $secret), $stored);
    }

    private function request(string $secret): void
    {
        $this->client->request('GET', '/api/ping', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
    }

    private function issueToken(User $owner, ?\DateTimeImmutable $createdAt = null): string
    {
        $secret = ApiToken::generateSecret();
        $token = new ApiToken($owner, 'Pixel de test', $secret, $createdAt);

        $this->em->persist($token);
        $this->em->flush();

        return $secret;
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
