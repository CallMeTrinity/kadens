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
use Doctrine\ORM\QueryBuilder;
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
     * Les activités importées rattachées à chaque séance FAITE, avec leur nombre :
     * le pendant, côté montre, de `ScheduledWorkoutRepository::doneActivityCountsForOwner()`.
     *
     * Sert à dire la nature d'une séance qui n'a ni prescrit ni réalisé logué —
     * la séance libre que crée la reprise d'historique Intervals
     * (`ActivityMatcher`, `workout = null`). Son activité importée est la seule
     * chose qui dise ce qu'elle était.
     *
     * @return list<array{scheduledId: int, activity: ActivityType, activities: int}>
     */
    public function doneActivityCountsByScheduledWorkout(User $owner): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.scheduledWorkout) AS sid', 'a.activity AS activity', 'COUNT(a.id) AS activities')
            ->join('a.scheduledWorkout', 's')
            ->andWhere('s.owner = :owner')
            ->andWhere('s.status = :done')
            ->andWhere('a.activity IS NOT NULL')
            ->setParameter('owner', $owner)
            ->setParameter('done', ScheduledStatus::DONE)
            ->groupBy('sid', 'a.activity')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'scheduledId' => (int) $row['sid'],
            'activity' => $row['activity'] instanceof ActivityType
                ? $row['activity']
                : ActivityType::from((string) $row['activity']),
            'activities' => (int) $row['activities'],
        ], $rows);
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
        $qb = $this->enduranceTotalsQuery()
            ->join('a.scheduledWorkout', 's')
            ->andWhere('s.owner = :owner')
            ->andWhere('s.status = :done')
            ->setParameter('owner', $owner)
            ->setParameter('done', ScheduledStatus::DONE);

        if (null !== $start) {
            $qb->andWhere('s.scheduledDate >= :windowStart')->setParameter('windowStart', $start, Types::DATE_IMMUTABLE);
        }

        if (null !== $end) {
            $qb->andWhere('s.scheduledDate <= :windowEnd')->setParameter('windowEnd', $end, Types::DATE_IMMUTABLE);
        }

        return $this->foldEnduranceTotals($qb->getQuery()->getArrayResult());
    }

    /**
     * Le même agrégat, borné à des séances désignées : celles d'une instanciation
     * de plan, que `ProgressionAggregator::realizedRun()` replie sur ses semaines.
     * Le rattachement suffit (pas de filtre de statut) : une activité posée sur
     * une séance dit qu'elle a eu lieu, et le rattachement la passe déjà en DONE.
     *
     * @param list<int> $scheduledIds
     *
     * @return array<int, array<string, array{meters: int, seconds: int}>> id de séance => activité => volume
     */
    public function enduranceTotalsForScheduledIds(array $scheduledIds): array
    {
        if ([] === $scheduledIds) {
            return [];
        }

        $rows = $this->enduranceTotalsQuery()
            ->andWhere('a.scheduledWorkout IN (:ids)')
            ->setParameter('ids', $scheduledIds)
            ->getQuery()
            ->getArrayResult();

        return $this->foldEnduranceTotals($rows);
    }

    private function enduranceTotalsQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->select('IDENTITY(a.scheduledWorkout) AS sid', 'a.activity AS activity', 'COALESCE(SUM(a.distanceMeters), 0) AS meters', 'COALESCE(SUM(a.movingSeconds), 0) AS seconds')
            ->andWhere('a.activity IN (:endurance)')
            ->setParameter('endurance', [ActivityType::RUNNING->value, ActivityType::CYCLING->value, ActivityType::SWIMMING->value])
            ->groupBy('sid', 'a.activity');
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, array<string, array{meters: int, seconds: int}>>
     */
    private function foldEnduranceTotals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
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
