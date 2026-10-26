<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiToken;
use App\Entity\CustomMessage;
use App\Entity\User;
use App\Enum\MessageAudience;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les messages programmés vus du téléphone : la clé `messages` du bootstrap
 * (non vus, en cours ou à venir sous quinze jours) et `PUT /api/messages/{id}/seen`,
 * qui partage la mémoire du « vu » avec le web.
 */
final class ApiMessageTest extends WebTestCase
{
    use FindsEntities;
    use PurgesDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $friend;
    private string $secret;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);

        $this->friend = (new User())->setEmail('lea@example.com')->setPassword('x');
        $this->em->persist($this->friend);

        $this->secret = ApiToken::generateSecret();
        $this->em->persist(new ApiToken($this->friend, 'Pixel de test', $this->secret));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->purgeDatabase(static::getContainer()->get('doctrine.orm.entity_manager'));

        parent::tearDown();
    }

    public function testTheBootstrapCarriesUnseenMessagesUpToTheHorizon(): void
    {
        $today = $this->createMessage('today', 'today', 'Aujourd\'hui');
        $soon = $this->createMessage('+10 days', '+10 days', 'Bientôt');
        $this->createMessage('+20 days', '+20 days', 'Trop loin');
        $this->createMessage('-3 days', '-1 day', 'Passé');
        $seen = $this->createMessage('today', 'today', 'Déjà vu');
        $seen->markSeenBy($this->friend);
        $this->createMessage('today', 'today', 'Pour quelqu\'un d\'autre', MessageAudience::USERS);
        $this->em->flush();

        $messages = $this->bootstrap()['messages'];

        self::assertSame([$today->getId(), $soon->getId()], array_column($messages, 'id'));
        self::assertSame(['id', 'body', 'mediaUrl', 'startsOn', 'endsOn'], array_keys($messages[0]));
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $messages[0]['startsOn']);
        self::assertNull($messages[0]['mediaUrl']);
    }

    public function testTheMediaUrlIsAbsoluteAndSigned(): void
    {
        $message = $this->createMessage('today', 'today', 'Avec GIF');
        $message->setMediaFile(str_repeat('a', 32).'.gif');
        $this->em->flush();

        $url = (string) $this->bootstrap()['messages'][0]['mediaUrl'];

        self::assertStringStartsWith('http://localhost/media/message/'.$message->getId().'/'.str_repeat('a', 32).'.gif?', $url);
        self::assertStringContainsString('_hash=', $url);
        self::assertStringContainsString('_expiration=', $url);
    }

    public function testMarkingSeenIsIdempotentAndSharedWithTheWeb(): void
    {
        $message = $this->createMessage('today', 'today', 'Joyeux anniversaire');

        $this->put('/api/messages/'.$message->getId().'/seen');
        self::assertResponseStatusCodeSame(204);
        $this->put('/api/messages/'.$message->getId().'/seen');
        self::assertResponseStatusCodeSame(204);

        self::assertSame([], $this->bootstrap()['messages']);

        $this->client->loginUser($this->friend);
        self::assertCount(0, $this->client->request('GET', '/workout')->filter('dialog[aria-label="Message"]'));
    }

    public function testAMessageForSomeoneElseIsA404(): void
    {
        $message = $this->createMessage('today', 'today', 'Pas pour toi', MessageAudience::USERS);

        $this->put('/api/messages/'.$message->getId().'/seen');
        self::assertResponseStatusCodeSame(404);

        $this->put('/api/messages/999999/seen');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function bootstrap(): array
    {
        $this->client->request('GET', '/api/bootstrap', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->secret]);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function put(string $uri): void
    {
        $this->client->request('PUT', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->secret]);
    }

    /** `USERS` sans destinataire : un message qui ne s'adresse à personne de la fixture. */
    private function createMessage(string $startsOn, string $endsOn, string $body, MessageAudience $audience = MessageAudience::ALL): CustomMessage
    {
        $message = (new CustomMessage())
            ->setTitle('Interne')
            ->setBody($body)
            ->setStartsOn(new \DateTimeImmutable($startsOn))
            ->setEndsOn(new \DateTimeImmutable($endsOn))
            ->setAudience($audience);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }
}
