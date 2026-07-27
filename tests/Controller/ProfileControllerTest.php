<?php

namespace App\Tests\Controller;

use App\Entity\Coaching;
use App\Entity\Exercise;
use App\Entity\Goal;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Paramètres du compte : changement de mot de passe (le seul écrit sensible de
 * la page). La fiche athlète est couverte indirectement ailleurs.
 */
final class ProfileControllerTest extends WebTestCase
{
    private const CURRENT_PASSWORD = 'password';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        // Ordre FK-safe : tout ce qui référence user passe avant les users.
        foreach ($this->em->getRepository(Coaching::class)->findAll() as $coaching) {
            $this->em->remove($coaching);
        }
        foreach ($this->em->getRepository(Goal::class)->findAll() as $goal) {
            $this->em->remove($goal);
        }
        foreach ($this->em->getRepository(ScheduledWorkout::class)->findAll() as $scheduled) {
            $this->em->remove($scheduled);
        }
        foreach ($this->em->getRepository(PlanTemplate::class)->findAll() as $template) {
            $this->em->remove($template);
        }
        foreach ($this->em->getRepository(Workout::class)->findAll() as $workout) {
            $this->em->remove($workout);
        }
        foreach ($this->em->getRepository(Exercise::class)->findAll() as $exercise) {
            $this->em->remove($exercise);
        }
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            $this->em->remove($user);
        }
        $this->em->flush();
    }

    public function testSettingsRedirectsWhenAnonymous(): void
    {
        $this->client->request('GET', '/profile/settings');

        self::assertResponseRedirects('/login');
    }

    public function testChangePasswordUpdatesHashAndKeepsSession(): void
    {
        $user = $this->createUser('owner@example.com');
        $oldHash = $user->getPassword();

        $this->client->loginUser($user);
        $this->submitPasswordForm(self::CURRENT_PASSWORD, 'nouveau-mot-de-passe', 'nouveau-mot-de-passe');

        self::assertResponseRedirects('/profile/settings');

        $this->em->clear();
        $updated = $this->em->getRepository(User::class)->find($user->getId());
        self::assertNotSame($oldHash, $updated->getPassword());

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($updated, 'nouveau-mot-de-passe'));

        // La ré-authentification du contrôleur doit préserver la session : la page
        // suivante reste accessible sans repasser par /login.
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testChangePasswordRejectsWrongCurrentPassword(): void
    {
        $user = $this->createUser('owner@example.com');
        $oldHash = $user->getPassword();

        $this->client->loginUser($user);
        $crawler = $this->submitPasswordForm('mauvais-mot-de-passe', 'nouveau-mot-de-passe', 'nouveau-mot-de-passe');

        // Symfony rend un formulaire invalide en 422 (contrat Turbo).
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('ne correspond pas à ton mot de passe actuel', $crawler->html());

        $this->em->clear();
        self::assertSame($oldHash, $this->em->getRepository(User::class)->find($user->getId())->getPassword());
    }

    public function testChangePasswordRejectsMismatchedRepeat(): void
    {
        $user = $this->createUser('owner@example.com');
        $oldHash = $user->getPassword();

        $this->client->loginUser($user);
        $crawler = $this->submitPasswordForm(self::CURRENT_PASSWORD, 'nouveau-mot-de-passe', 'autre-mot-de-passe');

        // Symfony rend un formulaire invalide en 422 (contrat Turbo).
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('ne correspondent pas', $crawler->html());

        $this->em->clear();
        self::assertSame($oldHash, $this->em->getRepository(User::class)->find($user->getId())->getPassword());
    }

    public function testChangePasswordRejectsTooShortPassword(): void
    {
        $user = $this->createUser('owner@example.com');
        $oldHash = $user->getPassword();

        $this->client->loginUser($user);
        // « au moins 8 caractères » figure aussi dans l'aide du champ : on vérifie
        // la présence d'une erreur rendue, pas le simple texte.
        $crawler = $this->submitPasswordForm(self::CURRENT_PASSWORD, 'court', 'court');

        // Symfony rend un formulaire invalide en 422 (contrat Turbo).
        self::assertResponseStatusCodeSame(422);
        self::assertGreaterThan(0, $crawler->filter('.kd-errors')->count());

        $this->em->clear();
        self::assertSame($oldHash, $this->em->getRepository(User::class)->find($user->getId())->getPassword());
    }

    public function testChangePasswordRejectsSamePassword(): void
    {
        $user = $this->createUser('owner@example.com');

        $this->client->loginUser($user);
        $crawler = $this->submitPasswordForm(self::CURRENT_PASSWORD, self::CURRENT_PASSWORD, self::CURRENT_PASSWORD);

        // Symfony rend un formulaire invalide en 422 (contrat Turbo).
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('différent de l\'actuel', $crawler->html());
    }

    private function submitPasswordForm(string $current, string $first, string $second): Crawler
    {
        $crawler = $this->client->request('GET', '/profile/settings');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Changer le mot de passe')->form();
        $form['change_password[currentPassword]'] = $current;
        $form['change_password[plainPassword][first]'] = $first;
        $form['change_password[plainPassword][second]'] = $second;

        return $this->client->submit($form);
    }

    private function createUser(string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())->setEmail($email);
        $user->setPassword($hasher->hashPassword($user, self::CURRENT_PASSWORD));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
