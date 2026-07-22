<?php

namespace App\Repository;

use App\Entity\Exercise;
use App\Entity\User;
use App\Enum\ActivityType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Exercise>
 */
class ExerciseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Exercise::class);
    }

    /**
     * @return Exercise[]
     */
    public function findByActivity(ActivityType $activity): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.activity = :activity')
            ->setParameter('activity', $activity)
            ->orderBy('e.name', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Bibliothèque visible par un utilisateur : ses exercices perso + la
     * bibliothèque globale de l'app (owner null).
     *
     * @return Exercise[]
     */
    public function findLibraryForUser(User $user): array
    {
        return $this->createLibraryQueryBuilder($user)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * QueryBuilder de la bibliothèque visible par un utilisateur (perso +
     * global), réutilisé par le form de prescription pour limiter les choix.
     */
    public function createLibraryQueryBuilder(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.owner = :user OR e.owner IS NULL')
            ->setParameter('user', $user)
            ->orderBy('e.name', 'ASC')
        ;
    }
}
