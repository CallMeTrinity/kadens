<?php

namespace App\Repository;

use App\Entity\Goal;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Goal>
 */
class GoalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Goal::class);
    }

    /**
     * Objectifs à venir (échéance >= aujourd'hui), du plus proche au plus lointain.
     *
     * @return list<Goal>
     */
    public function findUpcomingForOwner(User $owner, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('g')
            ->andWhere('g.owner = :owner')
            ->andWhere('g.targetDate >= :today')
            ->setParameter('owner', $owner)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('g.targetDate', 'ASC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Objectifs passés (échéance < aujourd'hui), du plus récent au plus ancien.
     *
     * @return list<Goal>
     */
    public function findPastForOwner(User $owner): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.owner = :owner')
            ->andWhere('g.targetDate < :today')
            ->setParameter('owner', $owner)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('g.targetDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Le prochain objectif à venir, ou null. Sert au compte à rebours (calendrier/profil). */
    public function findNextForOwner(User $owner): ?Goal
    {
        return $this->findUpcomingForOwner($owner, 1)[0] ?? null;
    }

    /**
     * Objectifs dont l'échéance tombe dans la fenêtre (bornes incluses). Sert au
     * marquage des cases du calendrier.
     *
     * @return list<Goal>
     */
    public function findByOwnerBetween(User $owner, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.owner = :owner')
            ->andWhere('g.targetDate BETWEEN :from AND :to')
            ->setParameter('owner', $owner)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('g.targetDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
