<?php

namespace App\Tests\Command;

use App\Entity\Exercise;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:exercise:globalize` : la bascule des exercices perso d'un admin dans la
 * bibliothèque globale.
 *
 * Ce que les tests tiennent : l'**identifiant survit** à la bascule (c'est la
 * raison d'être d'un `UPDATE` plutôt que d'une copie), la **frontière de
 * l'assiette** (un exercice de simple utilisateur n'est jamais touché) et le
 * refus de fusionner un **doublon de nom** avec la globale.
 */
final class GlobalizeAdminExercisesCommandTest extends KernelTestCase
{
    use PurgesDatabase;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        $this->purgeDatabase($this->em);
    }

    public function testItGlobalizesAnAdminExerciseWithoutTouchingItsId(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $exercise = $this->createExercise('Rowing barre', $admin);
        $id = $exercise->getId();

        $this->launch(['--force' => true]);

        $this->em->clear();
        $reloaded = $this->em->find(Exercise::class, $id);
        self::assertNotNull($reloaded, 'L\'exercice a changé d\'identifiant.');
        self::assertNull($reloaded->getOwner());
        self::assertSame('Rowing barre', $reloaded->getName());
    }

    public function testSimulationWritesNothing(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $exercise = $this->createExercise('Rowing barre', $admin);

        $tester = $this->launch([]);

        self::assertStringContainsString('Simulation', $tester->getDisplay());

        $this->em->clear();
        self::assertNotNull($this->em->find(Exercise::class, $exercise->getId())->getOwner());
    }

    public function testAnOrdinaryUserExerciseIsNeverTouched(): void
    {
        $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $athlete = $this->createUser('athlete@example.com');
        $mine = $this->createExercise('Ma variante maison', $athlete);

        $tester = $this->launch(['--force' => true]);

        self::assertStringContainsString('Aucun exercice perso', $tester->getDisplay());

        $this->em->clear();
        self::assertNotNull($this->em->find(Exercise::class, $mine->getId())->getOwner());
    }

    public function testANameAlreadyHeldByTheGlobalLibraryIsSkipped(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $global = $this->createExercise('Développé couché', null);
        // Casse différente : ce n'est pas un doublon pour SQL, c'en est un dans
        // une liste que tout le monde lit.
        $duplicate = $this->createExercise('développé couché', $admin);
        $fresh = $this->createExercise('Face pull', $admin);

        $tester = $this->launch(['--force' => true]);

        self::assertStringContainsString(\sprintf('doublon de #%d', $global->getId()), $tester->getDisplay());

        $this->em->clear();
        // Le doublon reste perso — et surtout, il existe toujours.
        self::assertNotNull($this->em->find(Exercise::class, $duplicate->getId())->getOwner());
        self::assertNull($this->em->find(Exercise::class, $fresh->getId())->getOwner());
    }

    /**
     * Le doublon peut naître de la commande elle-même. C'est alors l'exercice le
     * plus ancien qui est publié : la règle doit être stable d'une exécution à
     * l'autre, pas dépendre de l'ordre que SQL rend.
     */
    public function testTwoAdminsHoldingTheSameNameOnlyPublishOne(): void
    {
        $first = $this->createUser('admin1@example.com', ['ROLE_ADMIN']);
        $second = $this->createUser('admin2@example.com', ['ROLE_ADMIN']);
        $published = $this->createExercise('Tirage vertical', $first);
        $rejected = $this->createExercise('Tirage vertical', $second);

        $this->launch(['--force' => true]);

        $this->em->clear();
        self::assertNull($this->em->find(Exercise::class, $published->getId())->getOwner());
        self::assertNotNull($this->em->find(Exercise::class, $rejected->getId())->getOwner());
    }

    public function testTheUserFilterScopesTheRun(): void
    {
        $first = $this->createUser('admin1@example.com', ['ROLE_ADMIN']);
        $second = $this->createUser('admin2@example.com', ['ROLE_ADMIN']);
        $mine = $this->createExercise('Face pull', $first);
        $theirs = $this->createExercise('Gainage latéral', $second);

        $this->launch(['--force' => true, '--user' => 'admin1@example.com']);

        $this->em->clear();
        self::assertNull($this->em->find(Exercise::class, $mine->getId())->getOwner());
        self::assertNotNull($this->em->find(Exercise::class, $theirs->getId())->getOwner());
    }

    public function testANonAdminTargetIsRefused(): void
    {
        $this->createUser('athlete@example.com');

        $tester = $this->launch(['--user' => 'athlete@example.com', '--force' => true]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('n\'est pas administrateur', $tester->getDisplay());
    }

    public function testAnUnknownAccountIsRefused(): void
    {
        $tester = $this->launch(['--user' => 'personne@example.com']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('Aucun compte', $tester->getDisplay());
    }

    // --------------------------------------------------------- Fixtures

    /**
     * @param array<string, bool|string> $arguments
     */
    private function launch(array $arguments): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:exercise:globalize'));
        $tester->execute($arguments);

        return $tester;
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles = []): User
    {
        $user = (new User())->setEmail($email);
        $user->setRoles($roles);
        $user->setPassword('peu-importe');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createExercise(string $name, ?User $owner): Exercise
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
