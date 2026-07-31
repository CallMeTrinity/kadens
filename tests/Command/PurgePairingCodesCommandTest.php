<?php

namespace App\Tests\Command;

use App\Entity\PairingCode;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:pairing:purge` (KL-46) : la seule chose qui nettoie `pairing_code`.
 *
 * Ce que le test tient, c'est la **borne** : l'échéance, et rien d'autre. Un
 * code consommé mais encore valide reste — c'est la fenêtre pendant laquelle le
 * desktop confirme quel téléphone vient de se connecter (KL-47) — et un code
 * échu part, consommé ou non.
 */
final class PurgePairingCodesCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        foreach ($this->em->getRepository(PairingCode::class)->findAll() as $code) {
            $this->em->remove($code);
        }
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            $this->em->remove($user);
        }
        $this->em->flush();
    }

    public function testItDeletesExpiredCodesAndKeepsTheLiveOnes(): void
    {
        $user = (new User())->setEmail('athlete@example.com');
        $user->setPassword('peu-importe');
        $this->em->persist($user);

        // Deux codes échus (l'un consommé, l'autre non) et un code vivant.
        $expired = new PairingCode($user, 'AAAA2345', new \DateTimeImmutable('-1 hour'));
        $expiredAndUsed = new PairingCode($user, 'BBBB2345', new \DateTimeImmutable('-1 hour'));
        $live = new PairingCode($user, 'CCCC2345');

        foreach ([$expired, $expiredAndUsed, $live] as $code) {
            $this->em->persist($code);
        }
        $this->em->flush();

        $this->em->getConnection()->executeStatement(
            'UPDATE pairing_code SET used_at = NOW(), consumed_by_device = ? WHERE id = ?',
            ['Pixel 8', $expiredAndUsed->getId()],
        );

        $tester = new CommandTester((new Application(self::$kernel))->find('app:pairing:purge'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('2 code(s)', $tester->getDisplay());

        $this->em->clear();
        $remaining = $this->em->getRepository(PairingCode::class)->findAll();
        self::assertCount(1, $remaining);
        self::assertSame($live->getCodeHash(), $remaining[0]->getCodeHash());
    }
}
