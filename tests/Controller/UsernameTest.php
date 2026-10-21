<?php

namespace App\Tests\Controller;

use App\Entity\Coaching;
use App\Entity\User;
use App\Enum\CoachingStatus;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Le pseudo : un libellé d'affichage, unique, facultatif, modifiable à volonté.
 * L'email reste l'identifiant (connexion, coaching). Tant qu'il n'y en a pas, une
 * invitation s'ouvre sur la page d'arrivée, qu'on peut décliner pour de bon.
 */
final class UsernameTest extends WebTestCase
{
    use FindsEntities;
    use PurgesDatabase;

    private const PROMPT = 'dialog[aria-labelledby="username-prompt-title"]';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->purgeDatabase($this->em);
    }

    // --- L'invitation ----------------------------------------------------------

    public function testTheInvitationWaitsOnAnyPageUntilAnswered(): void
    {
        $this->client->loginUser($this->createUser('sans-pseudo@example.com'));

        $crawler = $this->client->request('GET', '/workout');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(self::PROMPT));
    }

    public function testThereIsNoInvitationOnceAUsernameIsSet(): void
    {
        $this->client->loginUser($this->createUser('avec-pseudo@example.com', 'Trinity'));

        $crawler = $this->client->request('GET', '/');

        self::assertCount(0, $crawler->filter(self::PROMPT));
    }

    /**
     * Les deux pages qui portent déjà le champ : la modale y ferait doublon, et
     * deux formulaires `username` sur une même page se répondraient l'un l'autre.
     */
    public function testThereIsNoInvitationWhereTheFieldIsAlreadyOnThePage(): void
    {
        $this->client->loginUser($this->createUser('sans-pseudo@example.com'));

        self::assertCount(0, $this->client->request('GET', '/profile/settings')->filter(self::PROMPT));
        self::assertCount(0, $this->client->request('GET', '/profile/username')->filter(self::PROMPT));
    }

    public function testChoosingAUsernameReturnsToThePageAndReplacesTheEmailOnScreen(): void
    {
        $this->client->loginUser($this->createUser('trinity@example.com'));

        $crawler = $this->client->request('GET', '/exercise?q=squat');
        $this->submitPrompt($crawler, '  Trinity  ');

        self::assertResponseRedirects('/exercise?q=squat');

        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter(self::PROMPT));
        self::assertSelectorTextSame('.kd-usermenu__who', 'Trinity');

        // Espaces de bord retirés à l'enregistrement.
        self::assertSame('Trinity', $this->reload('trinity@example.com')->getUsername());
    }

    public function testNoThanksIsRememberedAndTheUsernameStaysOptional(): void
    {
        $this->client->loginUser($this->createUser('discret@example.com'));

        $crawler = $this->client->request('GET', '/workout');
        $this->client->submit($crawler->filter('#username-prompt-dismiss')->form());

        self::assertResponseRedirects('/workout');

        $user = $this->reload('discret@example.com');
        self::assertNull($user->getUsername());
        self::assertNotNull($user->getUsernamePromptDismissedAt());

        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter(self::PROMPT));
        // Sans pseudo, l'email reste le nom affiché.
        self::assertSelectorTextSame('.kd-usermenu__who', 'discret@example.com');
    }

    /**
     * « Non merci » ne referme que l'invitation, pas le pseudo : il reste
     * ajoutable depuis les paramètres, et le refus ne le bloque jamais.
     */
    public function testNoThanksStillLetsTheUsernameBeAddedFromTheSettings(): void
    {
        $this->client->loginUser($this->createUser('discret@example.com'));

        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->filter('#username-prompt-dismiss')->form());
        self::assertNotNull($this->reload('discret@example.com')->getUsernamePromptDismissedAt());

        $this->submitSettings('Discret');

        self::assertResponseRedirects('/profile/settings');
        self::assertSame('Discret', $this->reload('discret@example.com')->getUsername());

        $this->client->followRedirect();
        self::assertSelectorTextSame('.kd-usermenu__who', 'Discret');
    }

    public function testATakenUsernameIsRefusedWhateverItsCase(): void
    {
        $this->createUser('premier@example.com', 'Trinity');
        $this->client->loginUser($this->createUser('second@example.com'));

        $crawler = $this->client->request('GET', '/');
        $crawler = $this->submitPrompt($crawler, 'trinity');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.kd-field', 'Ce pseudo est déjà pris.');
        // Le refus ne s'affiche pas comme accepté le temps du rendu.
        self::assertSelectorTextSame('.kd-usermenu__who', 'second@example.com');
        // La saisie et le retour sont conservés pour réessayer.
        self::assertSame('trinity', $crawler->filter('#username_username')->attr('value'));
        self::assertSame('/', $crawler->filter('input[name="_back"]')->first()->attr('value'));

        self::assertNull($this->reload('second@example.com')->getUsername());
    }

    public function testTheInvitationRefusesAnEmptyAnswer(): void
    {
        $this->client->loginUser($this->createUser('vide@example.com'));

        $this->submitPrompt($this->client->request('GET', '/'), '   ');

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->reload('vide@example.com')->getUsername());
        self::assertNull($this->reload('vide@example.com')->getUsernamePromptDismissedAt());
    }

    /**
     * Pas de longueur minimale, voulu. Un seul caractère est un pseudo.
     */
    public function testASingleCharacterIsAUsername(): void
    {
        $this->client->loginUser($this->createUser('court@example.com'));

        $this->submitPrompt($this->client->request('GET', '/'), 'K');

        self::assertResponseRedirects('/');
        self::assertSame('K', $this->reload('court@example.com')->getUsername());
    }

    /**
     * Un pseudo qui ressemble à un email se ferait passer pour l'identifiant de
     * quelqu'un d'autre, celui qu'on tape pour l'inviter en coaching.
     */
    public function testAUsernameCannotLookLikeAnEmail(): void
    {
        $this->client->loginUser($this->createUser('malin@example.com'));

        $this->submitPrompt($this->client->request('GET', '/'), 'coach@example.com');

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->reload('malin@example.com')->getUsername());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignBacks(): iterable
    {
        yield 'URL absolue' => ['https://evil.example/'];
        yield 'protocole relatif' => ['//evil.example/'];
        yield 'antislash' => ['/\\evil.example/'];
        yield 'tabulation retirée par le navigateur' => ["/\t/evil.example/"];
        yield 'chemin relatif' => ['evil'];
    }

    /**
     * `_back` vient du client : tout ce qui n'est pas un chemin local retombe sur
     * l'accueil, sans quoi la route serait une redirection ouverte.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('foreignBacks')]
    public function testTheReturnPathNeverLeavesTheSite(string $back): void
    {
        $this->client->loginUser($this->createUser('retour@example.com'));

        $crawler = $this->client->request('GET', '/');
        $form = $crawler->filter('#username-prompt-dismiss')->form();
        $form['_back'] = $back;
        $this->client->submit($form);

        self::assertResponseRedirects('/');
    }

    public function testDismissingRequiresAValidCsrfToken(): void
    {
        $this->client->loginUser($this->createUser('csrf@example.com'));

        $this->client->request('POST', '/profile/username/dismiss', ['_token' => 'faux', '_back' => '/']);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reload('csrf@example.com')->getUsernamePromptDismissedAt());
    }

    // --- Les paramètres --------------------------------------------------------

    public function testTheUsernameCanBeChangedAtWill(): void
    {
        $this->client->loginUser($this->createUser('change@example.com', 'Ancien'));

        $this->submitSettings('Nouveau');

        self::assertResponseRedirects('/profile/settings');
        self::assertSame('Nouveau', $this->reload('change@example.com')->getUsername());
    }

    /**
     * Garder son propre pseudo n'est pas un doublon : l'unicité ignore le compte
     * lui-même, y compris pour un simple changement de casse.
     */
    public function testKeepingOrRecasingOnesOwnUsernameIsNotADuplicate(): void
    {
        $this->client->loginUser($this->createUser('casse@example.com', 'trinity'));

        $this->submitSettings('Trinity');

        self::assertResponseRedirects('/profile/settings');
        self::assertSame('Trinity', $this->reload('casse@example.com')->getUsername());
    }

    /**
     * Vider le champ retire le pseudo, et c'est un choix : l'invitation ne
     * revient pas le relancer.
     */
    public function testClearingTheFieldRemovesTheUsernameWithoutReopeningTheInvitation(): void
    {
        $this->client->loginUser($this->createUser('retire@example.com', 'Trinity'));

        $this->submitSettings('');

        self::assertResponseRedirects('/profile/settings');
        $user = $this->reload('retire@example.com');
        self::assertNull($user->getUsername());
        self::assertNotNull($user->getUsernamePromptDismissedAt());

        self::assertCount(0, $this->client->request('GET', '/')->filter(self::PROMPT));
    }

    public function testATakenUsernameIsRefusedInTheSettingsToo(): void
    {
        $this->createUser('premier@example.com', 'Trinity');
        $this->client->loginUser($this->createUser('second@example.com', 'Neo'));

        $this->submitSettings('TRINITY');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="username"] .kd-field', 'Ce pseudo est déjà pris.');
        self::assertSelectorTextSame('.kd-usermenu__who', 'Neo');
        self::assertSame('Neo', $this->reload('second@example.com')->getUsername());
    }

    // --- Ce que le pseudo ne change pas ----------------------------------------

    /**
     * Le coaching se noue toujours par email : le pseudo n'est pas une adresse.
     */
    public function testCoachingIsStillRequestedByEmail(): void
    {
        $coach = $this->createUser('coach@example.com', 'Morpheus');
        $coach->setRoles(['ROLE_COACH']);
        $this->em->flush();
        $this->client->loginUser($this->createUser('athlete@example.com', 'Neo'));

        $crawler = $this->client->request('GET', '/coaching');
        $form = $crawler->filter('#coaching_request_email')->closest('form')?->form();
        self::assertNotNull($form);

        $form['email'] = 'Morpheus';
        $this->client->submit($form);
        self::assertCount(0, $this->em->getRepository(Coaching::class)->findAll());

        $form['email'] = 'coach@example.com';
        $this->client->submit($form);
        $coaching = $this->em->getRepository(Coaching::class)->findOneBy([]);
        self::assertNotNull($coaching);
        self::assertSame(CoachingStatus::PENDING, $coaching->getStatus());

        // Sous un pseudo, la carte rappelle l'email par lequel la relation s'est nouée.
        $crawler = $this->client->request('GET', '/coaching');
        self::assertStringContainsString('Morpheus', $crawler->filter('.kd-coachcard__name')->text());
        self::assertStringContainsString('coach@example.com', $crawler->filter('.kd-coachcard__role')->text());
    }

    // --- Outils ----------------------------------------------------------------

    private function submitPrompt(Crawler $page, string $username): Crawler
    {
        $form = $page->filter(self::PROMPT.' form[name="username"]')->form();
        $form['username[username]'] = $username;

        return $this->client->submit($form);
    }

    private function submitSettings(string $username): Crawler
    {
        $crawler = $this->client->request('GET', '/profile/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="username"]')->form();
        $form['username[username]'] = $username;

        return $this->client->submit($form);
    }

    private function reload(string $email): User
    {
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        return $user;
    }

    private function createUser(string $email, ?string $username = null): User
    {
        $user = (new User())->setEmail($email)->setUsername($username)->setPassword('irrelevant');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
