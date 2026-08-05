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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\UX\Turbo\TurboBundle;

/**
 * La gestion des appareils dans `/profile/settings` (KL-12) : la liste, et les
 * deux révocations.
 *
 * Le test qui porte le ticket est `testRevokingADeviceEndsItsApiAccess` : sans
 * lui, la page ne prouverait qu'une ligne retirée d'un tableau. Ce qui compte,
 * c'est que le téléphone perde vraiment la main — l'échéance d'un `ApiToken`
 * glisse à chaque usage (KL-10), un appareil actif ne s'éteint donc jamais seul.
 *
 * `testRevokingSomeoneElsesDeviceIsNotFound` porte l'autre moitié : le refus ne
 * confirme pas qu'un identifiant existe.
 */
final class DeviceRevocationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

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
     * La liste est scopée au propriétaire : les appareils d'un autre compte n'y
     * apparaissent pas, et le nom saisi par le téléphone s'affiche tel quel.
     */
    public function testTheSettingsPageListsOnlyItsOwnDevices(): void
    {
        $owner = $this->createUser('athlete@example.com');
        $this->createToken($owner, 'Pixel 8');
        $this->createToken($this->createUser('autre@example.com'), 'Galaxy S24');

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/profile/settings');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.kd-device'));
        self::assertStringContainsString('Pixel 8', $crawler->filter('.kd-device__name')->text());
        self::assertStringNotContainsString('Galaxy S24', $this->client->getResponse()->getContent());
    }

    /**
     * Le test qui porte le ticket. Révoquer depuis le web coupe l'API, tout de
     * suite : le jeton est **supprimé**, comme au `POST /api/auth/logout`, pas
     * marqué d'un état dont chaque lecture devrait se souvenir.
     */
    public function testRevokingADeviceEndsItsApiAccess(): void
    {
        $user = $this->createUser('athlete@example.com');
        $secret = ApiToken::generateSecret();
        $token = $this->createToken($user, 'Pixel 8', $secret);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/profile/settings');

        // Avant : le jeton ouvre l'API. Le cookie de session ne compte pas ici,
        // le pare-feu `api` est stateless (KL-10) — et la requête web ci-dessus
        // a déjà purgé le `token_storage` résiduel de `loginUser()`.
        $this->requestPing($secret);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/profile/devices/'.$token->getId().'/revoke', [
            '_token' => $crawler->filter('.kd-device__act input[name="_token"]')->attr('value'),
        ]);
        self::assertResponseRedirects('/profile/settings');

        // Après : le même secret ne vaut plus rien, et la ligne a disparu.
        $this->requestPing($secret);
        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->em->getRepository(ApiToken::class)->findAll());
    }

    /**
     * L'autre moitié du ticket : un jeton qui n'est pas le sien rend **404 et
     * non 403**. Distinguer « pas à toi » de « n'existe pas » confirmerait
     * l'existence à qui essaie des identifiants — même règle que l'état d'un
     * code d'appairage (KL-47).
     */
    public function testRevokingSomeoneElsesDeviceIsNotFound(): void
    {
        $token = $this->createToken($this->createUser('victime@example.com'), 'Pixel 8');

        $this->client->loginUser($this->createUser('autre@example.com'));
        $this->client->request('POST', '/profile/devices/'.$token->getId().'/revoke');

        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $this->em->getRepository(ApiToken::class)->findAll());
    }

    /**
     * Le CSRF est vérifié à la main, la requête ne passant pas par un `FormType` :
     * un oubli côté template rendrait un 403, pas une révocation silencieuse.
     */
    public function testRevokingWithoutACsrfTokenIsRefused(): void
    {
        $user = $this->createUser('athlete@example.com');
        $token = $this->createToken($user, 'Pixel 8');

        $this->client->loginUser($user);
        $this->client->request('POST', '/profile/devices/'.$token->getId().'/revoke');

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->em->getRepository(ApiToken::class)->findAll());
    }

    /**
     * Avec JS, seul le panneau des appareils est remplacé : révoquer un vieux
     * téléphone pendant qu'on en appaire un nouveau ne doit effacer ni le QR
     * affiché, ni la saisie du formulaire de mot de passe.
     */
    public function testRevokingAnswersATurboStreamTargetingOnlyThePanel(): void
    {
        $user = $this->createUser('athlete@example.com');
        $token = $this->createToken($user, 'Pixel 8');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/profile/settings');

        $this->client->request(
            'POST',
            '/profile/devices/'.$token->getId().'/revoke',
            ['_token' => $crawler->filter('.kd-device__act input[name="_token"]')->attr('value')],
            server: ['HTTP_ACCEPT' => TurboBundle::STREAM_MEDIA_TYPE],
        );

        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="devices-panel">', $content);
        self::assertStringNotContainsString('pairing-panel', $content);
    }

    /**
     * « Tout révoquer » ne sort pas du compte : c'est le geste qu'on fait quand
     * on ne sait plus ce qui est connecté, il ne doit pas non plus dépendre de
     * ce que la page affichait.
     */
    public function testRevokingAllOnlyTouchesItsOwnDevices(): void
    {
        $owner = $this->createUser('athlete@example.com');
        $this->createToken($owner, 'Pixel 8');
        $this->createToken($owner, 'Tablette');
        $this->createToken($this->createUser('autre@example.com'), 'Galaxy S24');

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/profile/settings');

        // Le bouton global n'apparaît qu'à partir de deux appareils : avec un
        // seul, il doublerait la révocation d'à côté.
        self::assertCount(1, $crawler->filter('.kd-devices__all'));

        $this->client->request('POST', '/profile/devices/revoke-all', [
            '_token' => $crawler->filter('.kd-devices__all input[name="_token"]')->attr('value'),
        ]);
        self::assertResponseRedirects('/profile/settings');

        $remaining = $this->em->getRepository(ApiToken::class)->findAll();
        self::assertCount(1, $remaining);
        self::assertSame('Galaxy S24', $remaining[0]->getDeviceName());
    }

    public function testTheSettingsPageOffersNoGlobalRevocationForASingleDevice(): void
    {
        $user = $this->createUser('athlete@example.com');
        $this->createToken($user, 'Pixel 8');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/profile/settings');

        self::assertCount(0, $crawler->filter('.kd-devices__all'));
    }

    private function requestPing(string $secret): void
    {
        $this->client->request('GET', '/api/ping', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
    }

    private function createToken(User $owner, string $deviceName, ?string $secret = null): ApiToken
    {
        $token = new ApiToken($owner, $deviceName, $secret ?? ApiToken::generateSecret());

        $this->em->persist($token);
        $this->em->flush();

        return $token;
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
