<?php

namespace App\Repository;

use App\Entity\PairingCode;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PairingCode>
 */
class PairingCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PairingCode::class);
    }

    /**
     * Consomme un code et le rend, ou rend `null` s'il est inconnu, expiré ou
     * déjà utilisé. **L'appelant ne distingue pas les trois cas** : les
     * distinguer dirait à qui devine un code s'il a visé juste.
     *
     * L'écriture est **atomique** et c'est tout l'enjeu de la méthode. Lire puis
     * écrire laisserait deux scans simultanés du même QR passer tous les deux :
     * les deux lectures verraient `used_at IS NULL` avant que l'une des deux
     * n'écrive. Ici c'est la base qui tranche, par le `WHERE`, et le nombre de
     * lignes affectées dit qui a gagné. L'expiration est dans la même condition,
     * pour la même raison : elle ne peut pas être vraie au moment du test et
     * fausse au moment de l'écriture.
     */
    public function consume(string $plainCode, string $deviceName, ?\DateTimeImmutable $now = null): ?PairingCode
    {
        $now ??= new \DateTimeImmutable();

        $code = $this->findOneBy(['codeHash' => PairingCode::hash($plainCode)]);

        if (null === $code) {
            return null;
        }

        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE pairing_code SET used_at = :now, consumed_by_device = :device
             WHERE id = :id AND used_at IS NULL AND expires_at > :now',
            [
                'now' => $now->format('Y-m-d H:i:s'),
                'device' => $deviceName,
                'id' => $code->getId(),
            ],
        );

        if (0 === $affected) {
            return null;
        }

        // L'UPDATE est passé par la connexion, l'entité en mémoire ignore encore
        // qu'elle est consommée. On la relit plutôt que de la corriger à la main :
        // ce que le contrôleur rend doit être ce que la base a réellement écrit.
        $this->getEntityManager()->refresh($code);

        return $code;
    }

    /**
     * Retire les codes non consommés d'un utilisateur. Appelé avant d'en émettre
     * un nouveau : **un écran, un code**. Sans ça, un code affiché sur un poste
     * qu'on a quitté resterait échangeable pendant deux minutes après qu'on en a
     * demandé un autre ailleurs.
     *
     * Ne touche pas aux codes déjà consommés : ce sont des traces d'appairage,
     * elles servent la confirmation visuelle du desktop (KL-47) jusqu'à leur
     * purge.
     */
    public function deleteUnusedFor(User $owner): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->andWhere('c.owner = :owner')
            ->andWhere('c.usedAt IS NULL')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->execute();
    }

    /**
     * Purge les codes échus, consommés ou non. Une seule borne, l'échéance : un
     * code utilisé reste affichable tant qu'il n'est pas périmé (c'est la fenêtre
     * pendant laquelle le desktop confirme l'appairage), et passé ce délai plus
     * rien ne le distingue d'un code mort.
     *
     * Appelée par `app:pairing:purge` (cron). Rien d'autre ne nettoie cette
     * table : un code laissé là n'est pas dangereux, il est juste inutile.
     */
    public function deleteExpired(?\DateTimeImmutable $now = null): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->andWhere('c.expiresAt <= :now')
            ->setParameter('now', $now ?? new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
