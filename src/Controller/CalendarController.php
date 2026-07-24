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
        \App\Service\PlanFlattener $planFlattener,
    ): Response {
        if ($month < 1 || $month > 12) {
            throw $this->createNotFoundException('Mois invalide.');
        }

        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $weeks = $this->buildWeeks($first, $month, $scheduledWorkoutRepository);

        // Aperçu au survol : une mise à plat par séance distincte du mois,
        // indexée par id de Workout (source unique PlanFlattener, cf. plans).
        $flattened = [];
        foreach ($weeks as $week) {
            foreach ($week as $cell) {
                foreach ($cell['scheduled'] as $scheduled) {
                    $workout = $scheduled->getWorkout();
                    $flattened[$workout->getId()] ??= $planFlattener->flattenWorkout($workout);
                }
            }
        }

        $prev = $first->modify('-1 month');
        $next = $first->modify('+1 month');

        // Pivot de la bascule « Semaine » : la semaine ouverte est celle contenant
        // aujourd'hui si le mois affiché est le mois courant, sinon son 1er jour.
        $today = new \DateTimeImmutable('today');
        $weekPivot = ((int) $today->format('Y') === $year && (int) $today->format('n') === $month)
            ? $today : $first;

        return $this->render('calendar/index.html.twig', [
            'year' => $year,
            'month' => $month,
            'monthLabel' => self::MONTH_NAMES[$month].' '.$year,
            'weeks' => $weeks,
            'flattened' => $flattened,
            'weekPivot' => $weekPivot->format('Y-m-d'),
            'prev' => ['year' => (int) $prev->format('Y'), 'month' => (int) $prev->format('n')],
            'next' => ['year' => (int) $next->format('Y'), 'month' => (int) $next->format('n')],
            ...$this->buildAddContext($workoutRepository, $planTemplateRepository, $scheduledWorkoutRepository),
        ]);
    }

    #[Route('/week', name: 'app_calendar_week_index', methods: ['GET'])]
    public function weekIndex(): Response
    {
        return $this->redirectToRoute('app_calendar_week', [
            'date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
        ]);
    }

    /**
     * Vue semaine : 7 jours lundi→dimanche autour de la date passée, cartes de
     * séance détaillées et bandeau de synthèse (observance + volume de la semaine).
     */
    #[Route('/week/{date}', name: 'app_calendar_week', methods: ['GET'], requirements: ['date' => '\d{4}-\d{2}-\d{2}'])]
    public function week(
        string $date,
        ScheduledWorkoutRepository $scheduledWorkoutRepository,
        WorkoutRepository $workoutRepository,
        PlanTemplateRepository $planTemplateRepository,
        \App\Service\PlanFlattener $planFlattener,
    ): Response {
        try {
            $ref = new \DateTimeImmutable($date);
        } catch (\Exception) {
            throw $this->createNotFoundException('Date invalide.');
        }

        // Ancrage au lundi ISO de la semaine contenant la date.
        $monday = $ref->modify(sprintf('-%d days', (int) $ref->format('N') - 1))->setTime(0, 0);
        $sunday = $monday->modify('+6 days');
        $todayKey = (new \DateTimeImmutable('today'))->format('Y-m-d');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $byDate = [];
        $flattened = [];
        foreach ($scheduledWorkoutRepository->findByOwnerBetween($user, $monday, $sunday) as $scheduled) {
            $byDate[$scheduled->getScheduledDate()->format('Y-m-d')][] = $scheduled;
            $workout = $scheduled->getWorkout();
            $flattened[$workout->getId()] ??= $planFlattener->flattenWorkout($workout);
        }

        $dayNames = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
        $days = [];
        $totalMinutes = 0;
        $cursor = $monday;
        for ($i = 0; $i < 7; ++$i) {
            $key = $cursor->format('Y-m-d');
            $scheduled = $byDate[$key] ?? [];
            foreach ($scheduled as $s) {
                $totalMinutes += (int) $s->getWorkout()->getEstimatedDurationMinutes();
            }
            $days[] = [
                'date' => $cursor,
                'dayName' => $dayNames[(int) $cursor->format('N')],
                'isToday' => $key === $todayKey,
                'isPast' => $key < $todayKey,
                'weekend' => (int) $cursor->format('N') >= 6,
                'scheduled' => $scheduled,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        $counts = $scheduledWorkoutRepository->countByStatusForOwnerBetween($user, $monday, $sunday);

        return $this->render('calendar/week.html.twig', [
            'monday' => $monday,
            'sunday' => $sunday,
            'weekLabel' => $this->formatWeekLabel($monday, $sunday),
            'isoWeek' => (int) $monday->format('W'),
            'days' => $days,
            'flattened' => $flattened,
            'counts' => $counts,
            'sessionCount' => array_sum($counts),
            'totalMinutes' => $totalMinutes,
            'todayKey' => $todayKey,
            'prevDate' => $monday->modify('-7 days')->format('Y-m-d'),
            'nextDate' => $monday->modify('+7 days')->format('Y-m-d'),
            'monthPivot' => ['year' => (int) $monday->modify('+3 days')->format('Y'), 'month' => (int) $monday->modify('+3 days')->format('n')],
            ...$this->buildAddContext($workoutRepository, $planTemplateRepository, $scheduledWorkoutRepository),
        ]);
    }

    /**
     * Libellé lisible d'une semaine : « 21 – 27 juillet 2026 », en compactant le
     * mois/l'année communs aux deux bornes.
     */
    private function formatWeekLabel(\DateTimeImmutable $monday, \DateTimeImmutable $sunday): string
    {
        $mFrom = self::MONTH_NAMES[(int) $monday->format('n')];
        $mTo = self::MONTH_NAMES[(int) $sunday->format('n')];
        $yFrom = $monday->format('Y');
        $yTo = $sunday->format('Y');

        if ($yFrom !== $yTo) {
            return sprintf('%d %s %s – %d %s %s', (int) $monday->format('j'), $mFrom, $yFrom, (int) $sunday->format('j'), $mTo, $yTo);
        }
        if ($mFrom !== $mTo) {
            return sprintf('%d %s – %d %s %s', (int) $monday->format('j'), $mFrom, (int) $sunday->format('j'), $mTo, $yTo);
        }

        return sprintf('%d – %d %s %s', (int) $monday->format('j'), (int) $sunday->format('j'), $mTo, $yTo);
    }

    /**
     * Contexte commun aux vues mois et semaine : formulaires d'ajout (poser une
     * séance, instancier un plan), plans instanciés (retrait rapide) et statuts.
     *
     * @return array<string, mixed>
     */
    private function buildAddContext(
        WorkoutRepository $workoutRepository,
        PlanTemplateRepository $planTemplateRepository,
        ScheduledWorkoutRepository $scheduledWorkoutRepository,
    ): array {
        $scheduleForm = $this->createForm(ScheduleWorkoutType::class, null, [
            'action' => $this->generateUrl('app_scheduled_workout_add'),
            'workouts' => $workoutRepository->findLibraryForOwner($this->getUser()),
        ]);

        $instantiateForm = $this->createForm(PlanInstantiationType::class, null, [
            'action' => $this->generateUrl('app_scheduled_workout_instantiate'),
            'planTemplates' => $planTemplateRepository->findBy(['owner' => $this->getUser()], ['title' => 'ASC']),
        ]);

        return [
            'scheduleForm' => $scheduleForm,
            'instantiateForm' => $instantiateForm,
            'instantiatedPlans' => $scheduledWorkoutRepository->findInstantiatedPlansForOwner($this->getUser()),
            'statuses' => \App\Enum\ScheduledStatus::cases(),
        ];
    }

    /**
     * Construit la grille dense du mois : semaines ISO (lundi→dimanche) couvrant
     * le mois, débords des mois voisins compris pour remplir les cases. Les
     * séances planifiées sont chargées d'un seul coup sur toute la fenêtre puis
     * indexées par date.
     *
     * @return list<list<array{date: \DateTimeImmutable, inMonth: bool, isToday: bool, overdue: bool, scheduled: list<\App\Entity\ScheduledWorkout>}>>
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
                    'overdue' => $key < $todayKey,
                    'scheduled' => $byDate[$key] ?? [],
                ];
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $week;
        }

        return $weeks;
    }
}
