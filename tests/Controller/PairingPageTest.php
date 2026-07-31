<?php

namespace App\Tests\Controller;

use App\Entity\ApiToken;
use App\Entity\Coaching;
use App\Entity\Exercise;
use App\Entity\Goal;
use App\Entity\PairingCode;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Service\PairingQr;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\UX\Turbo\TurboBundle;

/**
 * La page QR d'appairage sur le desktop (KL-47) : la section « Connecter un
 * téléphone » de `/profile/settings`, et l'état d'un code émis.
 *
 * Deux tests portent le ticket. `testTheQrEncodesTheServerUrlTheCodeAndTheExpiry` :
 * le QR est le **contrat avec l'app mobile** (KL-48 y lit `{url, code, exp}` et
 * s'en configure) — le dessin peut changer, pas ce qu'il encode.
 * `testTheStatusOfSomeoneElsesCodeIsNotFound` : l'état d'un code est une donnée
 * de son émetteur, et le refus ne confirme pas qu'il existe.
 *
 * L'appairage lui-même (usage unique, propriété du jeton, limiteur) vit dans
 * `ApiPairingTest`.
 */
final class PairingPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        // Le compteur du limiteur de `POST /api/auth/pair` vit dans un pool de
        // cache sur disque : sans ce vidage, l'ordre des tests deviendrait
        // significatif (cf. ApiPairingTest).
        static::getContainer()->get('cache.rate_limiter')->clear();

        // Ordre FK-safe : tout ce qui référence user passe avant les users, que
        // ces tests recréent avec les mêmes emails (uniques en base).
        foreach ([
            PairingCode::class,
            ApiToken::class,
            Coaching::class,
            Goal::class,
            ScheduledWorkout::class,
            PlanTemplate::class,
            Workout::class,
            Exercise::class,
            User::class,
        ] as $entity) {
            foreach ($this->em->getRepository($entity)->findAll() as $row) {
                $this->em->remove($row);
            }
            $this->em->flush();
        }
    }

    /**
     * L'état par défaut de la page est **sans code** : émettre est une écriture,
     * pas un effet de bord de l'affichage. Sinon chaque ouverture des paramètres
     * gâcherait un code et invaliderait celui d'un autre onglet.
     */
    public function testTheSettingsPageOffersPairingWithoutIssuingACode(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));

        $crawler = $this->client->request('GET', '/profile/settings');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#pairing-panel'));
        self::assertCount(1, $crawler->filter('.kd-pairing__form'));
        self::assertCount(0, $crawler->filter('.kd-pairing__code'));
        self::assertSame([], $this->em->getRepository(PairingCode::class)->findAll());
    }

    /**
     * Le QR est dessiné **côté serveur**, donc sans dépendance JavaScript ni
     * requête supplémentaire, et le code de secours l'accompagne en toutes
     * lettres (§0.6 règle 4 : la caméra peut refuser).
     */
    public function testIssuingRendersTheQrAndItsFallbackCode(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));

        $crawler = $this->issue();

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.kd-pairing__qr svg'));
        self::assertMatchesRegularExpression('/^[2-9A-HJ-NP-Z]{8}$/', $this->issuedCode());

        // Repli sans JS : l'échéance est écrite en clair, le décompte n'est
        // qu'un confort posé par-dessus par le contrôleur Stimulus.
        $pairing = $this->em->getRepository(PairingCode::class)->findAll()[0];
        self::assertStringContainsString(
            $pairing->getExpiresAt()->format('H:i'),
            $crawler->filter('.kd-pairing__timer')->text(),
        );
    }

    /**
     * Le test qui porte le ticket : ce que le QR **encode** est le contrat avec
     * l'app mobile (KL-48 s'y configure son URL de serveur). Le dessin est
     * déterministe, on le compare donc à celui de la charge utile attendue —
     * ça vaut décodage, sans décodeur.
     */
    public function testTheQrEncodesTheServerUrlTheCodeAndTheExpiry(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));

        $this->issue();

        $code = $this->issuedCode();
        $pairing = $this->em->getRepository(PairingCode::class)->findAll()[0];

        /** @var PairingQr $qr */
        $qr = static::getContainer()->get(PairingQr::class);
        $expected = $qr->svg([
            // L'URL du serveur voyage avec le code : zéro saisie sur le téléphone,
            // et l'IP LAN réglée d'office en développement.
            'url' => 'http://localhost',
            'code' => $code,
            'exp' => $pairing->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ]);

        self::assertStringContainsString($expected, $this->client->getResponse()->getContent());
    }

    /**
     * Le QR ne porte que ces trois champs (§0.6 règle 1) : s'il portait un
     * jeton, une photo de l'écran vaudrait un accès permanent.
     */
    public function testTheQrPayloadCarriesNothingElse(): void
    {
        $user = $this->createUser('athlete@example.com');
        $pairing = new PairingCode($user, 'ABCD2345');

        $payload = static::getContainer()->get(PairingQr::class)->payload($pairing, 'ABCD2345', 'https://kadens.test');

        self::assertSame(['url', 'code', 'exp'], array_keys($payload));
    }

    /**
     * Avec JS, seul le panneau est remplacé : le formulaire de mot de passe de
     * la même page ne doit pas perdre ce qui y est saisi.
     */
    public function testIssuingAnswersATurboStreamTargetingOnlyThePanel(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));

        $this->issue([TurboBundle::STREAM_MEDIA_TYPE]);

        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="pairing-panel">', $content);
        self::assertStringNotContainsString('<form method="post" action="/login"', $content);
    }

    /**
     * La confirmation visuelle du desktop : le snapshot `consumedByDevice` écrit
     * par l'appairage. C'est ce que KL-46 gardait en réserve pour ce ticket.
     */
    public function testTheStatusReportsTheConsumingDevice(): void
    {
        $this->client->loginUser($this->createUser('athlete@example.com'));
        $this->issue();
        $code = $this->issuedCode();
        $pairing = $this->em->getRepository(PairingCode::class)->findAll()[0];

        $this->client->request('GET', '/pairing/'.$pairing->getId().'/status');
        self::assertSame(
            ['used' => false, 'device' => null, 'expired' => false],
            json_decode($this->client->getResponse()->getContent(), true),
        );

        $this->client->request(
            'POST',
            '/api/auth/pair',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['code' => $code, 'deviceName' => 'Pixel 8']),
        );
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/pairing/'.$pairing->getId().'/status');
        self::assertSame(
            ['used' => true, 'device' => 'Pixel 8', 'expired' => false],
            json_decode($this->client->getResponse()->getContent(), true),
        );
    }

    /**
     * L'autre test qui porte le ticket : l'état d'un code appartient à son
     * émetteur. **404 et non 403** — un refus qui distingue « pas à toi » de
     * « n'existe pas » confirme l'existence à qui essaie des identifiants.
     */
    public function testTheStatusOfSomeoneElsesCodeIsNotFound(): void
    {
        $issuer = $this->createUser('emetteur@example.com');
        $this->client->loginUser($issuer);
        $this->issue();
        $pairing = $this->em->getRepository(PairingCode::class)->findAll()[0];

        $this->client->loginUser($this->createUser('autre@example.com'));
        $this->client->request('GET', '/pairing/'.$pairing->getId().'/status');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheStatusRequiresAWebSession(): void
    {
        $user = $this->createUser('athlete@example.com');
        $this->client->loginUser($user);
        $this->issue();
        $pairing = $this->em->getRepository(PairingCode::class)->findAll()[0];

        // `issue()` a déjà fait deux requêtes : le jeton résiduel que
        // `loginUser()` pose dans le `token_storage` du conteneur a été remis à
        // zéro par le `services_resetter`, et il ne reste plus que le cookie —
        // qu'on jette. Sans ces requêtes intercalées, le test passerait pour la
        // mauvaise raison (piège documenté en KL-11).
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/pairing/'.$pairing->getId().'/status');

        self::assertResponseRedirects();
    }

    /**
     * Émet un code par le vrai endpoint. Le jeton CSRF est lu dans le formulaire
     * de la page : c'est le chemin de l'utilisateur, et il vérifie au passage que
     * le formulaire en porte un.
     *
     * @param list<string> $accept
     */
    private function issue(array $accept = []): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', '/profile/settings');
        $token = $crawler->filter('.kd-pairing__form input[name="_token"]')->attr('value');

        return $this->client->request(
            'POST',
            '/pairing/code',
            ['_token' => $token],
            server: $accept ? ['HTTP_ACCEPT' => implode(',', $accept)] : [],
        );
    }

    private function issuedCode(): string
    {
        return trim($this->client->getCrawler()->filter('.kd-pairing__code')->text());
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
