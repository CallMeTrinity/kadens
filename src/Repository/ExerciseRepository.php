<?php

namespace App\Repository;

use App\Entity\Exercise;
use App\Enum\ActivityType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
     * @return Exercise[]
     */
    public function findAllOrderedByName(): array
    {
        return $this->createQueryBuilder('e')
            ->orderBy('e.name', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }
}
