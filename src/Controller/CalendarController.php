<?php

namespace App\Controller;

use App\Form\PlanInstantiationType;
use App\Form\ScheduleWorkoutType;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\WorkoutRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vue calendrier des séances planifiées (instances datées). Rendu serveur,
 * navigation mois par mois via liens (Turbo Drive assure la fluidité) : la page
 * reste auto-suffisante, sans AJAX post-chargement.
 *
 * Les mutations (poser / instancier / déplacer / supprimer) sont portées par
 * ScheduledWorkoutController ; les formulaires d'ajout sont rendus ici mais
 * postent vers ce contrôleur, puis redirigent vers le mois concerné.
 */
#[Route('/calendar')]
final class CalendarController extends AbstractController
{
    /** Noms de mois en français (index 1..12), le calendrier étant mono-langue. */
    private const MONTH_NAMES = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
    ];

    #[Route('', name: 'app_calendar_index', methods: ['GET'])]
    public function index(): Response
    {
        $now = new \DateTimeImmutable('today');

        return $this->redirectToRoute('app_calendar_month', [
            'year' => (int) $now->format('Y'),
            'month' => (int) $now->format('n'),
        ]);
    }

    #[Route('/{year}/{month}', name: 'app_calendar_month', methods: ['GET'], requirements: ['year' => '\d{4}', 'month' => '\d{1,2}'])]
    public function month(
        int $year,
        int $month,
        ScheduledWorkoutRepository $scheduledWorkoutRepository,
        WorkoutRepository $workoutRepository,
        PlanTemplateRepository $planTemplateRepository,
    ): Response {
        if ($month < 1 || $month > 12) {
            throw $this->createNotFoundException('Mois invalide.');
        }

        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $weeks = $this->buildWeeks($first, $month, $scheduledWorkoutRepository);

        $prev = $first->modify('-1 month');
        $next = $first->modify('+1 month');

        $scheduleForm = $this->createForm(ScheduleWorkoutType::class, null, [
            'action' => $this->generateUrl('app_scheduled_workout_add'),
            'workouts' => $workoutRepository->findBy(['owner' => $this->getUser()], ['title' => 'ASC']),
        ]);

        $instantiateForm = $this->createForm(PlanInstantiationType::class, null, [
            'action' => $this->generateUrl('app_scheduled_workout_instantiate'),
            'planTemplates' => $planTemplateRepository->findBy(['owner' => $this->getUser()], ['title' => 'ASC']),
        ]);

        return $this->render('calendar/index.html.twig', [
            'year' => $year,
            'month' => $month,
            'monthLabel' => self::MONTH_NAMES[$month].' '.$year,
            'weeks' => $weeks,
            'prev' => ['year' => (int) $prev->format('Y'), 'month' => (int) $prev->format('n')],
            'next' => ['year' => (int) $next->format('Y'), 'month' => (int) $next->format('n')],
            'scheduleForm' => $scheduleForm,
            'instantiateForm' => $instantiateForm,
        ]);
    }

    /**
     * Construit la grille dense du mois : semaines ISO (lundi→dimanche) couvrant
     * le mois, débords des mois voisins compris pour remplir les cases. Les
     * séances planifiées sont chargées d'un seul coup sur toute la fenêtre puis
     * indexées par date.
     *
     * @return list<list<array{date: \DateTimeImmutable, inMonth: bool, isToday: bool, scheduled: list<\App\Entity\ScheduledWorkout>}>>
     */
    private function buildWeeks(\DateTimeImmutable $first, int $month, ScheduledWorkoutRepository $repository): array
    {
        $gridStart = $first->modify(sprintf('-%d days', (int) $first->format('N') - 1));
        $last = $first->modify('last day of this month');
        $gridEnd = $last->modify(sprintf('+%d days', 7 - (int) $last->format('N')));

        $byDate = [];
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        foreach ($repository->findByOwnerBetween($user, $gridStart, $gridEnd) as $scheduled) {
            $byDate[$scheduled->getScheduledDate()->format('Y-m-d')][] = $scheduled;
        }

        $todayKey = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $weeks = [];
        $cursor = $gridStart;
        while ($cursor <= $gridEnd) {
            $week = [];
            for ($i = 0; $i < 7; ++$i) {
                $key = $cursor->format('Y-m-d');
                $week[] = [
                    'date' => $cursor,
                    'inMonth' => (int) $cursor->format('n') === $month,
                    'isToday' => $key === $todayKey,
                    'scheduled' => $byDate[$key] ?? [],
                ];
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $week;
        }

        return $weeks;
    }
}
