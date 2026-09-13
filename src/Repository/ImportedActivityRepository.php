<?php

namespace App\Repository;

use App\Entity\ImportedActivity;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Enum\ActivitySource;
use App\Enum\ActivityType;
use App\Enum\ScheduledStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportedActivity>
 */
class ImportedActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportedActivity::class);
    }

    /**
     * Parmi des identifiants externes, ceux qui sont déjà en base. Une requête
     * par lot, pas une par activité : c'est ce qui rend la synchro rejouable à
     * coût constant.
     *
     * @param list<string> $externalIds
     *
     * @return list<string>
     */
    public function knownExternalIds(ActivitySource $source, array $externalIds): array
    {
        if ([] === $externalIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('a')
            ->select('a.externalId')
            ->andWhere('a.source = :source')
            ->andWhere('a.externalId IN (:ids)')
            ->setParameter('source', $source)
            ->setParameter('ids', $externalIds)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('strval', $rows);
    }

    /**
     * @return list<ImportedActivity>
     */
    public function findForScheduledWorkout(ScheduledWorkout $scheduled): array
    {
        return $this->findBy(['scheduledWorkout' => $scheduled], ['startedAt' => 'ASC']);
    }

    /**
     * Les activités non rattachées d'un utilisateur un jour donné : ce qu'on
     * propose de rattacher depuis la page d'une séance datée.
     *
     * @return list<ImportedActivity>
     */
    public function findUnattachedForOwnerOn(User $owner, \DateTimeImmutable $day): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.owner = :owner')
            ->andWhere('a.scheduledWorkout IS NULL')
            ->andWhere('a.localDate = :day')
            ->setParameter('owner', $owner)
            ->setParameter('day', $day, Types::DATE_IMMUTABLE)
            ->orderBy('a.startedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countUnattachedForOwner(User $owner): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.owner = :owner')
            ->andWhere('a.scheduledWorkout IS NULL')
            ->andWhere('a.activity IS NOT NULL')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Parmi des séances datées, celles qui portent déjà au moins une activité.
     *
     * @param list<int> $scheduledIds
     *
     * @return list<int>
     */
    public function scheduledIdsWithActivity(array $scheduledIds): array
    {
        if ([] === $scheduledIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('a')
            ->select('DISTINCT IDENTITY(a.scheduledWorkout)')
            ->andWhere('a.scheduledWorkout IN (:ids)')
            ->setParameter('ids', $scheduledIds)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $rows);
    }

    /**
     * Le volume d'endurance **réel** des séances faites d'une fenêtre, par séance
     * et par activité, en un seul agrégat SQL. C'est ce que `TrainingStats` substitue
     * au prescrit quand il existe, sans hydrater une seule activité.
     *
     * @return array<int, array<string, array{meters: int, seconds: int}>> id de séance => activité => volume
     */
    public function enduranceTotalsByScheduledWorkout(User $owner, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.scheduledWorkout) AS sid', 'a.activity AS activity', 'COALESCE(SUM(a.distanceMeters), 0) AS meters', 'COALESCE(SUM(a.movingSeconds), 0) AS seconds')
            ->join('a.scheduledWorkout', 's')
            ->andWhere('s.owner = :owner')
            ->andWhere('s.status = :done')
            ->andWhere('a.activity IN (:endurance)')
            ->setParameter('owner', $owner)
            ->setParameter('done', ScheduledStatus::DONE)
            ->setParameter('endurance', [ActivityType::RUNNING->value, ActivityType::CYCLING->value, ActivityType::SWIMMING->value])
            ->groupBy('sid', 'a.activity');

        if (null !== $start) {
            $qb->andWhere('s.scheduledDate >= :windowStart')->setParameter('windowStart', $start, Types::DATE_IMMUTABLE);
        }

        if (null !== $end) {
            $qb->andWhere('s.scheduledDate <= :windowEnd')->setParameter('windowEnd', $end, Types::DATE_IMMUTABLE);
        }

        $totals = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $activity = $row['activity'] instanceof ActivityType ? $row['activity']->value : (string) $row['activity'];
            $totals[(int) $row['sid']][$activity] = [
                'meters' => (int) $row['meters'],
                'seconds' => (int) $row['seconds'],
            ];
        }

        return $totals;
    }

    public function deleteForOwner(User $owner, ActivitySource $source): int
    {
        return $this->createQueryBuilder('a')
            ->delete()
            ->andWhere('a.owner = :owner')
            ->andWhere('a.source = :source')
            ->setParameter('owner', $owner)
            ->setParameter('source', $source)
            ->getQuery()
            ->execute();
    }
}
