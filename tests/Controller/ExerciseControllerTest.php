<?php

namespace App\Tests\Controller;

use App\Entity\Exercise;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ExerciseControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        // Base de test propre : d'abord les séances et exercices (clé étrangère
        // owner), puis les utilisateurs.
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

    public function testIndexRedirectsWhenAnonymous(): void
    {
        $this->client->request('GET', '/exercise');

        self::assertResponseRedirects('/login');
    }

    public function testIndexShowsOnlyOwnExercises(): void
    {
        $owner = $this->createUser('owner@example.com');
        $other = $this->createUser('other@example.com');
        $this->createExercise($owner, 'Squat');
        $this->createExercise($other, 'Développé couché');

        $this->client->loginUser($owner);
        $this->client->request('GET', '/exercise');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Squat');
        self::assertSelectorTextNotContains('body', 'Développé couché');
    }

    public function testShowDeniesAccessToNonOwner(): void
    {
        $owner = $this->createUser('owner@example.com');
        $intruder = $this->createUser('intruder@example.com');
        $exercise = $this->createExercise($owner, 'Squat');

        $this->client->loginUser($intruder);
        $this->client->request('GET', '/exercise/'.$exercise->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateExercise(): void
    {
        $user = $this->createUser('owner@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/exercise/new');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Créer', [
            'exercise[name]' => 'Fentes',
            'exercise[activity]' => ActivityType::GYM->value,
        ]);

        self::assertResponseRedirects('/exercise');
        $created = $this->em->getRepository(Exercise::class)->findOneBy(['name' => 'Fentes']);
        self::assertNotNull($created);
        self::assertSame($user->getId(), $created->getOwner()?->getId());
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

    private function createExercise(User $owner, string $name): Exercise
    {
        $exercise = (new Exercise())
            ->setOwner($owner)
            ->setName($name)
            ->setActivity(ActivityType::GYM);

        $this->em->persist($exercise);
        $this->em->flush();

        return $exercise;
    }
}
