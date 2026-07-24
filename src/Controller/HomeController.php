<?php

namespace App\Controller;

use App\Enum\ScheduledStatus;
use App\Repository\ExerciseRepository;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\WorkoutRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page d'accueil — tableau de bord de l'utilisateur connecté. Point d'entrée
 * de l'app : prochaines séances datées, observance du mois en cours, compteurs
 * de la bibliothèque et raccourcis vers les sections. Rendu serveur
 * auto-suffisant (aucun AJAX post-chargement), donc cachable offline (Phase 9).
 */
final class HomeController extends AbstractController
{
    /** Fenêtre « à venir » affichée sur l'accueil, en jours à partir d'aujourd'hui. */
    private const UPCOMING_DAYS = 14;

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(
        ScheduledWorkoutRepository $scheduled,
        WorkoutRepository $workouts,
        PlanTemplateRepository $plans,
        ExerciseRepository $exercises,
    ): Response {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $today = new \DateTimeImmutable('today');
        $horizon = $today->modify('+'.self::UPCOMING_DAYS.' days');
        $monthStart = $today->modify('first day of this month');
        $monthEnd = $today->modify('last day of this month');

        $counts = $scheduled->countByStatusForOwnerBetween($user, $monthStart, $monthEnd);
        $done = $counts[ScheduledStatus::DONE->value] ?? 0;
        $missed = $counts[ScheduledStatus::MISSED->value] ?? 0;
        $planned = $counts[ScheduledStatus::PLANNED->value] ?? 0;
        $settled = $done + $missed;

        return $this->render('home/index.html.twig', [
            'upcoming' => $scheduled->findByOwnerBetween($user, $today, $horizon),
            'monthStats' => [
                'done' => $done,
                'missed' => $missed,
                'planned' => $planned,
                'total' => $done + $missed + $planned,
                'adherence' => $settled > 0 ? (int) round(100 * $done / $settled) : null,
            ],
            'counts' => [
                // planLocal = false : on ne compte pas les copies privées aux plans.
                'workouts' => $workouts->count(['owner' => $user, 'planLocal' => false]),
                'plans' => $plans->count(['owner' => $user]),
                'exercises' => \count($exercises->findLibraryForUser($user)),
            ],
        ]);
    }
}
