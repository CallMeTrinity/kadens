<?php

namespace App\Controller;

use App\Enum\ScheduledStatus;
use App\Repository\ScheduledWorkoutRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Synthèse « prévu vs réalisé » (Phase 7) : proportion de séances tenues vs
 * manquées, sur un mois donné et par plan instancié. On boucle sur la prévision,
 * pas de tracking détaillé (Strava fait ça). Rendu serveur auto-suffisant.
 */
#[Route('/summary')]
final class SummaryController extends AbstractController
{
    /** Noms de mois en français (index 1..12), l'app étant mono-langue. */
    private const MONTH_NAMES = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
    ];

    #[Route('', name: 'app_summary_index', methods: ['GET'])]
    public function index(): Response
    {
        $now = new \DateTimeImmutable('today');

        return $this->redirectToRoute('app_summary_month', [
            'year' => (int) $now->format('Y'),
            'month' => (int) $now->format('n'),
        ]);
    }

    #[Route('/{year}/{month}', name: 'app_summary_month', methods: ['GET'], requirements: ['year' => '\d{4}', 'month' => '\d{1,2}'])]
    public function month(int $year, int $month, ScheduledWorkoutRepository $repository): Response
    {
        if ($month < 1 || $month > 12) {
            throw $this->createNotFoundException('Mois invalide.');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $last = $first->modify('last day of this month');

        $monthCounts = $repository->countByStatusForOwnerBetween($user, $first, $last);

        $plans = array_map(
            fn (array $bucket): array => [
                'planId' => $bucket['planId'],
                'planTitle' => $bucket['planTitle'],
                'stats' => $this->buildStats($bucket['counts']),
            ],
            $repository->statusCountsByPlanForOwner($user),
        );

        $prev = $first->modify('-1 month');
        $next = $first->modify('+1 month');

        return $this->render('summary/index.html.twig', [
            'year' => $year,
            'month' => $month,
            'monthLabel' => self::MONTH_NAMES[$month].' '.$year,
            'monthStats' => $this->buildStats($monthCounts),
            'plans' => $plans,
            'prev' => ['year' => (int) $prev->format('Y'), 'month' => (int) $prev->format('n')],
            'next' => ['year' => (int) $next->format('Y'), 'month' => (int) $next->format('n')],
        ]);
    }

    /**
     * Enrichit un dénombrement brut par statut avec le total et le taux d'observance
     * (part des séances échues effectivement faites : done / (done + missed)). Les
     * séances encore « prévues » ne sont ni un succès ni un échec, on les exclut du
     * ratio mais on les affiche à part.
     *
     * @param array<string, int> $counts
     *
     * @return array{done: int, missed: int, planned: int, total: int, adherence: float|null}
     */
    private function buildStats(array $counts): array
    {
        $done = $counts[ScheduledStatus::DONE->value] ?? 0;
        $missed = $counts[ScheduledStatus::MISSED->value] ?? 0;
        $planned = $counts[ScheduledStatus::PLANNED->value] ?? 0;
        $settled = $done + $missed;

        return [
            'done' => $done,
            'missed' => $missed,
            'planned' => $planned,
            'total' => $done + $missed + $planned,
            'adherence' => $settled > 0 ? $done / $settled : null,
        ];
    }
}
