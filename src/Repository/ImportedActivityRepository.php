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
 * @phpstan-type EnduranceRow array{id: int, scheduledId: int, date: \DateTimeImmutable, activity: ActivityType, meters: ?int, seconds: ?int, elevation: ?int, splits: ?list<mixed>, zones: ?list<mixed>}
 *
 * @extends ServiceEntityRepository<ImportedActivity>
 */
class ImportedActivityRepository extends ServiceEntityRepository
{
    /** Les activités dont le réel entre dans les statistiques d'endurance. */
    private const ENDURANCE = [ActivityType::RUNNING->value, ActivityType::CYCLING->value, ActivityType::SWIMMING->value];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportedActivity::class);
    }

    /**
     * Les activités déjà importées d'un propriétaire parmi des identifiants
     * externes, indexées par identifiant. Sert à relire leur ressenti, qu'on
     * note souvent sur la montre ou dans Intervals après la synchro.
     *
     * @param list<string> $externalIds
     *
     * @return array<string, ImportedActivity>
     */
    public function findKnownForOwner(User $owner, ActivitySource $source, array $externalIds): array
    {
        if ([] === $externalIds) {
            return [];
        }

        /** @var list<ImportedActivity> $activities */
        $activities = $this->createQueryBuilder('a')
            ->andWhere('a.owner = :owner')
            ->andWhere('a.source = :source')
            ->andWhere('a.externalId IN (:ids)')
            ->setParameter('owner', $owner)
            ->setParameter('source', $source)
            ->setParameter('ids', $externalIds)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($activities as $activity) {
            $byId[$activity->getExternalId()] = $activity;
        }

        return $byId;
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

    /**
     * Les activités d'un utilisateur entre deux jours inclus, la plus récente en
     * tête : ce qu'on propose d'insérer dans le récit d'un objectif.
     *
     * @return list<ImportedActivity>
     */
    public function findForOwnerBetween(User $owner, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.owner = :owner')
            ->andWhere('a.localDate BETWEEN :start AND :end')
            ->setParameter('owner', $owner)
            ->setParameter('start', $start, Types::DATE_IMMUTABLE)
            ->setParameter('end', $end, Types::DATE_IMMUTABLE)
            ->orderBy('a.startedAt', 'DESC')
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

        return array_values(array_map('intval', $rows));
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

        return array_values(array_map(static fn (array $row): array => [
            'scheduledId' => (int) $row['sid'],
            'activity' => $row['activity'] instanceof ActivityType
                ? $row['activity']
                : ActivityType::from((string) $row['activity']),
            'activities' => (int) $row['activities'],
        ], $rows));
    }

    /**
     * Les sorties d'endurance **réelles** des séances faites d'une fenêtre, une
     * ligne par activité, en une seule lecture scalaire : aucune entité n'est
     * hydratée. C'est tout ce que `TrainingStats` sait du réel importé — le
     * volume par séance s'en replie, et `EnduranceStats` en tire allure, D+,
     * zones, records et comparaison.
     *
     * Pas un agrégat SQL, parce que les zones et les splits sont du JSON, que
     * DQL ne sait pas sommer. La lecture reste bornée par le nombre de sorties
     * de la fenêtre, jamais par l'historique de séries.
     *
     * Les JSON sont rendus tels quels (`list<mixed>`) : leur forme se vérifie
     * chez qui les lit, une ligne mal formée ne doit pas casser la page.
     *
     * @return list<EnduranceRow>
     */
    public function enduranceRowsForOwner(User $owner, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select(
                'a.id AS id',
                's.id AS sid',
                's.scheduledDate AS date',
                'a.activity AS activity',
                'a.distanceMeters AS meters',
                'a.movingSeconds AS seconds',
                'a.elevationGainMeters AS elevation',
                'a.splits AS splits',
                'a.hrZoneSeconds AS zones',
            )
            ->join('a.scheduledWorkout', 's')
            ->andWhere('s.owner = :owner')
            ->andWhere('s.status = :done')
            ->andWhere('a.activity IN (:endurance)')
            ->setParameter('owner', $owner)
            ->setParameter('done', ScheduledStatus::DONE)
            ->setParameter('endurance', self::ENDURANCE)
            ->orderBy('s.scheduledDate', 'ASC')
            ->addOrderBy('a.id', 'ASC');

        $this->window($qb, 's.scheduledDate', $start, $end);

        return array_values(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'scheduledId' => (int) $row['sid'],
            'date' => $row['date'] instanceof \DateTimeImmutable ? $row['date'] : new \DateTimeImmutable((string) $row['date']),
            'activity' => $row['activity'] instanceof ActivityType
                ? $row['activity']
                : ActivityType::from((string) $row['activity']),
            'meters' => null !== $row['meters'] ? (int) $row['meters'] : null,
            'seconds' => null !== $row['seconds'] ? (int) $row['seconds'] : null,
            'elevation' => null !== $row['elevation'] ? (int) $row['elevation'] : null,
            'splits' => self::json($row['splits']),
            'zones' => self::json($row['zones']),
        ], $qb->getQuery()->getArrayResult()));
    }

    /**
     * Les sorties d'endurance importées d'une fenêtre qui attendent encore une
     * séance. Elles ne comptent nulle part dans les statistiques ; ce compteur
     * sert seulement à le dire, pour qu'un total plus bas que prévu s'explique.
     * La fenêtre se lit sur le jour local de la sortie : sans séance, c'est la
     * seule date qu'elle ait.
     */
    public function countUnattachedEnduranceForOwnerIn(User $owner, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.owner = :owner')
            ->andWhere('a.scheduledWorkout IS NULL')
            ->andWhere('a.activity IN (:endurance)')
            ->setParameter('owner', $owner)
            ->setParameter('endurance', self::ENDURANCE);

        $this->window($qb, 'a.localDate', $start, $end);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Le volume d'endurance **réel**, par séance et par activité, borné à des
     * séances désignées : celles d'une instanciation
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
            ->setParameter('endurance', self::ENDURANCE)
            ->groupBy('sid', 'a.activity');
    }

    /**
     * @param array<array<string, mixed>> $rows
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

    /**
     * Une colonne JSON lue en scalaire arrive déjà décodée par son type ; le
     * repli sur la chaîne brute ne coûte rien et ne dépend pas de l'hydrateur.
     *
     * @return ?list<mixed>
     */
    private static function json(mixed $value): ?array
    {
        if (\is_string($value)) {
            $value = json_decode($value, true);
        }

        return \is_array($value) ? array_values($value) : null;
    }

    private function window(QueryBuilder $qb, string $field, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end): void
    {
        if (null !== $start) {
            $qb->andWhere($field.' >= :windowStart')->setParameter('windowStart', $start, Types::DATE_IMMUTABLE);
        }

        if (null !== $end) {
            $qb->andWhere($field.' <= :windowEnd')->setParameter('windowEnd', $end, Types::DATE_IMMUTABLE);
        }
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
