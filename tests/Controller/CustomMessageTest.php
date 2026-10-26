<?php

namespace App\Tests\Controller;

use App\Entity\CustomMessage;
use App\Entity\User;
use App\Enum\MessageAudience;
use App\Service\CustomMessageInbox;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * Les messages programmés : le back-office qui les écrit (ROLE_SUPER_ADMIN
 * seul), la modale qui les montre une fois, et l'image servie par URL signée.
 */
final class CustomMessageTest extends WebTestCase
{
    use FindsEntities;
    use PurgesDatabase;

    private const MODAL = 'dialog[aria-label="Message"]';

    /** Un GIF 1×1 valide : `getimagesize()` le lit, un navigateur l'affiche. */
    private const GIF = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);
    }

    // --- Le back-office --------------------------------------------------------

    public function testTheBackOfficeIsForSuperAdminsOnly(): void
    {
        $this->client->request('GET', '/admin/messages');
        self::assertResponseRedirects('/login');

        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN']));
        $this->client->request('GET', '/admin/messages');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->createUser('root@example.com', ['ROLE_SUPER_ADMIN']));
        $crawler = $this->client->request('GET', '/admin/messages');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/admin/messages"].kd-usermenu__link'));
    }

    public function testAnAdminDoesNotSeeTheMenuEntry(): void
    {
        $this->client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN'], 'Admin'));

        $crawler = $this->client->request('GET', '/workout');

        self::assertCount(0, $crawler->filter('a[href="/admin/messages"]'));
    }

    public function testASuperAdminSchedulesAMessageWithAGif(): void
    {
        $friend = $this->createUser('lea@example.com', [], 'Léa');
        $this->client->loginUser($this->createUser('root@example.com', ['ROLE_SUPER_ADMIN'], 'Root'));

        $crawler = $this->client->request('GET', '/admin/messages/new');
        $form = $crawler->selectButton('Programmer')->form();
        $form['custom_message[title]'] = 'Anniversaire de Léa';
        $form['custom_message[body]'] = "Joyeux anniversaire !\nBonne séance.";
        $form['custom_message[startsOn]'] = '2026-11-02';
        $form['custom_message[endsOn]'] = '2026-11-02';
        $this->choice($form, 'custom_message[audience]')->select('users');
        $this->tickRecipient($form, $friend);
        $this->file($form, 'custom_message[media]')->upload($this->gifFile());
        $this->client->submit($form);

        self::assertResponseRedirects();
        $message = $this->em->getRepository(CustomMessage::class)->findOneBy(['title' => 'Anniversaire de Léa']);
        self::assertNotNull($message);
        self::assertSame('2026-11-02', $message->getStartsOn()?->format('Y-m-d'));
        self::assertTrue($message->isAddressedTo($friend));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.gif$/', (string) $message->getMediaFile());
        self::assertSame(self::GIF, file_get_contents($this->mediaPath((string) $message->getMediaFile())), 'Le GIF a été ré-encodé.');
    }

    public function testAMessageForSomeUsersNeedsARecipient(): void
    {
        $this->client->loginUser($this->createUser('root@example.com', ['ROLE_SUPER_ADMIN'], 'Root'));

        $crawler = $this->client->request('GET', '/admin/messages/new');
        $form = $crawler->selectButton('Programmer')->form();
        $form['custom_message[title]'] = 'Sans destinataire';
        $form['custom_message[body]'] = 'Coucou';
        $this->choice($form, 'custom_message[audience]')->select('users');
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Choisis au moins un destinataire.');
        self::assertCount(0, $this->em->getRepository(CustomMessage::class)->findAll());
    }

    public function testAnImageThatIsNotOneIsRefused(): void
    {
        $this->client->loginUser($this->createUser('root@example.com', ['ROLE_SUPER_ADMIN'], 'Root'));

        $path = tempnam(sys_get_temp_dir(), 'kd').'.gif';
        file_put_contents($path, 'pas une image');

        $crawler = $this->client->request('GET', '/admin/messages/new');
        $form = $crawler->selectButton('Programmer')->form();
        $form['custom_message[title]'] = 'Noël';
        $form['custom_message[body]'] = 'Joyeux Noël';
        $this->choice($form, 'custom_message[audience]')->select('all');
        $this->file($form, 'custom_message[media]')->upload($path);
        $this->client->submit($form);

        self::assertSelectorTextContains('body', 'Format non reconnu');
        self::assertCount(0, $this->em->getRepository(CustomMessage::class)->findAll());
    }

    public function testResettingTheViewsShowsTheMessageAgain(): void
    {
        $root = $this->createUser('root@example.com', ['ROLE_SUPER_ADMIN'], 'Root');
        $message = $this->createMessage(MessageAudience::ALL);
        $message->markSeenBy($root);
        $this->em->flush();

        $this->client->loginUser($root);
        $crawler = $this->client->request('GET', '/admin/messages/'.$message->getId().'/edit');
        $this->client->submit($crawler->selectButton('Remettre à zéro les vues')->form());

        self::assertResponseRedirects('/admin/messages/'.$message->getId().'/edit');
        self::assertCount(1, $this->client->request('GET', '/workout')->filter(self::MODAL));
    }

    public function testDeletingAMessageRemovesItsImage(): void
    {
        $this->client->loginUser($this->createUser('root@example.com', ['ROLE_SUPER_ADMIN'], 'Root'));
        $message = $this->createMessage(MessageAudience::ALL, withGif: true);
        $file = (string) $message->getMediaFile();

        $crawler = $this->client->request('GET', '/admin/messages/'.$message->getId().'/edit');
        $this->client->submit($crawler->selectButton('Supprimer')->form());

        self::assertResponseRedirects('/admin/messages');
        self::assertFileDoesNotExist($this->mediaPath($file));
    }

    // --- La modale -------------------------------------------------------------

    public function testTheRecipientSeesTheMessageOnceAndClosingItIsDefinitive(): void
    {
        $friend = $this->createUser('lea@example.com', [], 'Léa');
        $message = $this->createMessage(MessageAudience::USERS, [$friend], withGif: true);

        $this->client->loginUser($friend);
        $crawler = $this->client->request('GET', '/workout?q=x');

        $modal = $crawler->filter(self::MODAL);
        self::assertCount(1, $modal);
        self::assertStringContainsString('Joyeux anniversaire', $modal->text());
        self::assertStringNotContainsString('Nom interne secret', (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $modal->filter('img.kd-message__media'));

        $this->client->submit($crawler->filter('#custom-message-seen-'.$message->getId())->form());
        self::assertResponseRedirects('/workout?q=x');

        self::assertCount(0, $this->client->request('GET', '/workout')->filter(self::MODAL));
    }

    public function testSomeoneElseDoesNotSeeIt(): void
    {
        $friend = $this->createUser('lea@example.com', [], 'Léa');
        $this->createMessage(MessageAudience::USERS, [$friend]);

        $this->client->loginUser($this->createUser('autre@example.com', [], 'Autre'));

        self::assertCount(0, $this->client->request('GET', '/workout')->filter(self::MODAL));
    }

    /** 404 et pas 403 : l'identifiant est séquentiel, un refus dirait qu'il existe. */
    public function testSomeoneElseCannotMarkItSeen(): void
    {
        $friend = $this->createUser('lea@example.com', [], 'Léa');
        $stranger = $this->createUser('autre@example.com', [], 'Autre');
        $message = $this->createMessage(MessageAudience::USERS, [$friend]);

        // Un jeton CSRF valide, pris sur la page du destinataire, dans la même session.
        $token = $this->csrfFromAPageWith($friend, $message);

        $this->client->loginUser($stranger);
        $this->client->request('POST', '/message/'.$message->getId().'/seen', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        $this->em->clear();
        self::assertCount(0, $this->mustFind(CustomMessage::class, (int) $message->getId())->getSeenBy());
    }

    /** Une modale à la fois : l'invitation à choisir un pseudo attend la page suivante. */
    public function testTheMessageTakesPrecedenceOverTheUsernamePrompt(): void
    {
        $this->createMessage(MessageAudience::ALL);
        $this->client->loginUser($this->createUser('sans-pseudo@example.com'));

        $crawler = $this->client->request('GET', '/workout');

        self::assertCount(1, $crawler->filter(self::MODAL));
        self::assertCount(0, $crawler->filter('dialog[aria-labelledby="username-prompt-title"]'));
    }

    // --- La fenêtre ------------------------------------------------------------

    public function testTheWindowIsInclusiveAndInactiveOrSeenMessagesStayHidden(): void
    {
        $user = $this->createUser('lea@example.com', [], 'Léa');
        $inbox = static::getContainer()->get(CustomMessageInbox::class);

        $message = $this->createMessage(MessageAudience::ALL, startsOn: '2026-12-24', endsOn: '2026-12-26');

        self::assertNull($inbox->pendingFor($user, new \DateTimeImmutable('2026-12-23')));
        self::assertSame($message, $inbox->pendingFor($user, new \DateTimeImmutable('2026-12-24')));
        self::assertSame($message, $inbox->pendingFor($user, new \DateTimeImmutable('2026-12-26')));
        self::assertNull($inbox->pendingFor($user, new \DateTimeImmutable('2026-12-27')));

        $message->setActive(false);
        $this->em->flush();
        self::assertNull($inbox->pendingFor($user, new \DateTimeImmutable('2026-12-25')));

        $message->setActive(true)->markSeenBy($user);
        $this->em->flush();
        self::assertNull($inbox->pendingFor($user, new \DateTimeImmutable('2026-12-25')));
    }

    // --- L'image ---------------------------------------------------------------

    public function testTheImageIsServedOnlyThroughItsSignedUrl(): void
    {
        $message = $this->createMessage(MessageAudience::ALL, withGif: true);
        $url = (string) static::getContainer()->get(CustomMessageInbox::class)->mediaUrl($message);

        // Sans session : c'est la signature qui ouvre la porte, comme pour le téléphone.
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/gif');

        $this->client->request('GET', explode('?', $url)[0]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', str_replace('_hash=', '_hash=x', $url));
        self::assertResponseStatusCodeSame(404);
    }

    // --- Outils ----------------------------------------------------------------

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles = [], ?string $username = null): User
    {
        $user = (new User())->setEmail($email)->setPassword('x')->setRoles($roles)->setUsername($username);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @param list<User> $recipients
     */
    private function createMessage(
        MessageAudience $audience,
        array $recipients = [],
        bool $withGif = false,
        string $startsOn = 'today',
        string $endsOn = 'today',
    ): CustomMessage {
        $message = (new CustomMessage())
            ->setTitle('Nom interne secret')
            ->setBody('Joyeux anniversaire !')
            ->setStartsOn(new \DateTimeImmutable($startsOn))
            ->setEndsOn(new \DateTimeImmutable($endsOn))
            ->setAudience($audience);

        foreach ($recipients as $recipient) {
            $message->addRecipient($recipient);
        }

        if ($withGif) {
            $file = bin2hex(random_bytes(16)).'.gif';
            @mkdir(\dirname($this->mediaPath($file)), 0775, true);
            file_put_contents($this->mediaPath($file), self::GIF);
            $message->setMediaFile($file);
        }

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function mediaPath(string $file): string
    {
        return static::getContainer()->getParameter('app.message_media_dir').'/'.$file;
    }

    private function gifFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kd').'.gif';
        file_put_contents($path, self::GIF);

        return $path;
    }

    private function choice(Form $form, string $name): ChoiceFormField
    {
        $field = $form->get($name);
        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }

    private function file(Form $form, string $name): FileFormField
    {
        $field = $form->get($name);
        self::assertInstanceOf(FileFormField::class, $field);

        return $field;
    }

    private function tickRecipient(Form $form, User $user): void
    {
        $fields = $form->get('custom_message[recipients]');
        self::assertIsArray($fields);

        foreach ($fields as $field) {
            if ($field instanceof ChoiceFormField && \in_array((string) $user->getId(), $field->availableOptionValues(), true)) {
                $field->tick();
            }
        }
    }

    /** Un jeton CSRF valide pour la fermeture, pris sur la page du destinataire. */
    private function csrfFromAPageWith(User $recipient, CustomMessage $message): string
    {
        $this->client->loginUser($recipient);
        $crawler = $this->client->request('GET', '/workout');

        return (string) $crawler->filter('#custom-message-seen-'.$message->getId().' input[name="_token"]')->attr('value');
    }
}
