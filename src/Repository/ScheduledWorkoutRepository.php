<?php

namespace App\Repository;

use App\Entity\ScheduledWorkout;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledWorkout>
 */
class ScheduledWorkoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledWorkout::class);
    }

    /**
     * Séances planifiées d'un utilisateur dans une fenêtre de dates (bornes
     * incluses). Sert au rendu d'une grille de calendrier : on charge d'un coup
     * tout ce que couvre le mois affiché (débords des semaines compris) et on
     * jointe la séance pour éviter N requêtes au rendu.
     *
     * @return list<ScheduledWorkout>
     */
    public function findByOwnerBetween(User $owner, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('w')
            ->join('s.workout', 'w')
            ->andWhere('s.owner = :owner')
            ->andWhere('s.scheduledDate BETWEEN :start AND :end')
            ->setParameter('owner', $owner)
            ->setParameter('start', $start, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE)
            ->setParameter('end', $end, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE)
            ->orderBy('s.scheduledDate', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
