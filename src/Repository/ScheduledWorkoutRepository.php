<?php

namespace App\Repository;

use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
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

    /**
     * Nombre de séances planifiées par statut pour un utilisateur sur une fenêtre
     * de dates (bornes incluses). Alimente la vue de synthèse « prévu vs réalisé »
     * (Phase 7) : une seule requête agrégée, pas d'hydratation d'entités.
     *
     * @return array<string, int> clés = valeurs de ScheduledStatus, valeurs = compte.
     *                            Les statuts absents sur la période valent 0.
     */
    public function countByStatusForOwnerBetween(User $owner, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.status AS status', 'COUNT(s.id) AS cnt')
            ->andWhere('s.owner = :owner')
            ->andWhere('s.scheduledDate BETWEEN :start AND :end')
            ->setParameter('owner', $owner)
            ->setParameter('start', $start, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE)
            ->setParameter('end', $end, \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE)
            ->groupBy('s.status')
            ->getQuery()
            ->getResult();

        return $this->mapStatusCounts($rows);
    }

    /**
     * Répartition par statut, regroupée par plan source, pour un utilisateur.
     * Le plan source est nullable (séance isolée, ou plan supprimé qui a mis la
     * FK à NULL) : ces séances retombent dans un bucket « hors plan ».
     *
     * @return list<array{planId: int|null, planTitle: string|null, counts: array<string, int>}>
     */
    public function statusCountsByPlanForOwner(User $owner): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select(
                'IDENTITY(s.sourcePlanTemplate) AS planId',
                'p.title AS planTitle',
                's.status AS status',
                'COUNT(s.id) AS cnt',
            )
            ->leftJoin('s.sourcePlanTemplate', 'p')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $owner)
            ->groupBy('planId')
            ->addGroupBy('p.title')
            ->addGroupBy('s.status')
            ->getQuery()
            ->getResult();

        // Regroupe les lignes (planId, status, cnt) en un bucket par plan.
        $byPlan = [];
        foreach ($rows as $row) {
            $planId = null !== $row['planId'] ? (int) $row['planId'] : null;
            $key = $planId ?? 0; // 0 = bucket « hors plan »
            if (!isset($byPlan[$key])) {
                $byPlan[$key] = [
                    'planId' => $planId,
                    'planTitle' => $row['planTitle'],
                    'counts' => $this->emptyStatusCounts(),
                ];
            }
            $byPlan[$key]['counts'][$this->statusValue($row['status'])] += (int) $row['cnt'];
        }

        return array_values($byPlan);
    }

    /**
     * Séances datées issues d'un plan pour un utilisateur (l'« instance vivante »
     * de ce plan sur son calendrier). Sert au resync : ajouter les cases nouvelles,
     * retrouver l'ancre. La séance (copie locale) est jointe pour le rendu.
     *
     * @return list<ScheduledWorkout>
     */
    public function findBySourcePlanTemplateForOwner(PlanTemplate $template, User $owner): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('w')
            ->join('s.workout', 'w')
            ->andWhere('s.sourcePlanTemplate = :template')
            ->andWhere('s.owner = :owner')
            ->setParameter('template', $template)
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getResult();
    }

    /**
     * Plans actuellement posés sur le calendrier de l'utilisateur (au moins une
     * séance datée en provient), dédupliqués et triés par titre. Alimente le
     * retrait rapide d'un plan instancié depuis le calendrier.
     *
     * @return list<PlanTemplate>
     */
    public function findInstantiatedPlansForOwner(User $owner): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('p')
            ->distinct()
            ->from(PlanTemplate::class, 'p')
            ->innerJoin(ScheduledWorkout::class, 's', 'WITH', 's.sourcePlanTemplate = p')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('p.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Séances datées issues d'une case précise du plan. Sert au retrait d'une case :
     * on retire les séances encore PLANNED, on préserve celles DONE/MISSED.
     *
     * @return list<ScheduledWorkout>
     */
    public function findBySourcePlanItem(PlanItem $item): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.sourcePlanItem = :item')
            ->setParameter('item', $item)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<array{status: mixed, cnt: mixed}> $rows
     *
     * @return array<string, int>
     */
    private function mapStatusCounts(array $rows): array
    {
        $counts = $this->emptyStatusCounts();
        foreach ($rows as $row) {
            $counts[$this->statusValue($row['status'])] += (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Base à zéro pour tous les statuts, dans l'ordre de l'enum.
     *
     * @return array<string, int>
     */
    private function emptyStatusCounts(): array
    {
        $counts = [];
        foreach (\App\Enum\ScheduledStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        return $counts;
    }

    /**
     * Normalise la valeur de statut renvoyée par DQL (enum ou chaîne backing).
     */
    private function statusValue(mixed $status): string
    {
        return $status instanceof \App\Enum\ScheduledStatus ? $status->value : (string) $status;
    }
}
