<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeletedEntity;
use App\Entity\User;
use App\Enum\DeletedEntityType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Lectures des pierres tombales (KL-14). Elles ne rendent jamais d'entités mais
 * des **clés** en projection scalaire : le client n'a que faire de savoir quand
 * une chose a disparu, seulement qu'elle a disparu.
 *
 * @extends ServiceEntityRepository<DeletedEntity>
 */
class DeletedEntityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeletedEntity::class);
    }

    /**
     * Identifiants d'exercices supprimés depuis `$since` et visibles par les
     * propriétaires donnés — la bibliothèque globale (`owner IS NULL`) comprise,
     * puisqu'elle l'était pour tout le monde.
     *
     * Même portée que `ExerciseRepository::createLibraryQueryBuilder()` : ce qui
     * a pu descendre sur le téléphone doit pouvoir en être retiré, et rien
     * d'autre.
     *
     * @param list<User> $owners
     *
     * @return list<int>
     */
    public function exerciseIdsDeletedSince(array $owners, \DateTimeImmutable $since): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d.entityKey')
            ->andWhere('d.entityType = :type')
            ->andWhere('d.deletedAt >= :since')
            ->setParameter('type', DeletedEntityType::EXERCISE)
            ->setParameter('since', $since)
        ;

        if ([] === $owners) {
            $qb->andWhere('d.owner IS NULL');
        } else {
            $qb
                ->andWhere('d.owner IN (:owners) OR d.owner IS NULL')
                ->setParameter('owners', $owners)
            ;
        }

        return array_map(
            static fn (array $row): int => (int) $row['entityKey'],
            $qb->getQuery()->getScalarResult(),
        );
    }

    /**
     * Uuid des séances datées supprimées depuis `$since` pour ce propriétaire.
     *
     * Pas de `owner IS NULL` ici, contrairement aux exercices : une séance datée
     * a toujours un propriétaire, il n'existe pas de calendrier partagé.
     *
     * @return list<string>
     */
    public function scheduleUuidsDeletedSince(User $owner, \DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.entityKey')
            ->andWhere('d.entityType = :type')
            ->andWhere('d.owner = :owner')
            ->andWhere('d.deletedAt >= :since')
            ->setParameter('type', DeletedEntityType::SCHEDULED_WORKOUT)
            ->setParameter('owner', $owner)
            ->setParameter('since', $since)
            ->getQuery()
            ->getScalarResult()
        ;

        return array_map(static fn (array $row): string => (string) $row['entityKey'], $rows);
    }

    /**
     * Retire les pierres tombales antérieures à `$before`. Rend le nombre de
     * lignes supprimées.
     */
    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('d')
            ->delete()
            ->andWhere('d.deletedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute()
        ;
    }
}
