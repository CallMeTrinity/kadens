<?php

namespace App\Tests\Command;

use App\Entity\User;
use App\Tests\FindsEntities;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:user:promote-super-admin` : le seul chemin vers le back-office. Le rôle
 * s'ajoute aux autres sans les écraser, et ROLE_USER, implicite, n'est pas
 * stocké.
 */
final class PromoteSuperAdminCommandTest extends KernelTestCase
{
    use FindsEntities;
    use PurgesDatabase;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        $this->purgeDatabase($this->em);
    }

    public function testItAddsTheRoleAlongsideTheExistingOnes(): void
    {
        $user = (new User())->setEmail('antonin@example.com')->setPassword('x')->setRoles(['ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();

        $tester = $this->launch('antonin@example.com');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->em->clear();
        $reloaded = $this->mustFind(User::class, (int) $user->getId());
        self::assertTrue($reloaded->isSuperAdmin());
        self::assertContains('ROLE_ADMIN', $reloaded->getRoles());

        $this->launch('antonin@example.com');
        self::assertCount(2, array_filter($this->mustFind(User::class, (int) $user->getId())->getRoles(), static fn (string $r): bool => 'ROLE_USER' !== $r));
    }

    public function testAnUnknownEmailFails(): void
    {
        self::assertSame(Command::FAILURE, $this->launch('personne@example.com')->getStatusCode());
    }

    private function launch(string $email): CommandTester
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:user:promote-super-admin'));
        $tester->execute(['email' => $email]);

        return $tester;
    }
}
