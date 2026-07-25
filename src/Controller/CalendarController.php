<?php

namespace App\Controller;

use App\Enum\ActivityType;
use App\Form\PlanInstantiationType;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\WorkoutRepository;
use App\Service\WorkoutMetrics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
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
    public function __construct(
        private readonly WorkoutMetrics $workoutMetrics,
    ) {
    }

    /** Noms de mois en français (index 1..12), le calendrier étant mono-langue. */
    private const MONTH_NAMES = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
    ];

    #[Route('', name: 'app_calendar_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $now = new \DateTimeImmutable('today');

        // Respecte la dernière vue choisie (cookie posé au rendu mois/semaine) :
        // l'entrée « Calendrier » et un refresh retombent sur la bonne vue.
        if ('week' === $request->cookies->get('kd_calview')) {
            return $this->redirectToRoute('app_calendar_week', ['date' => $now->format('Y-m-d')]);
        }

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
        \App\Repository\GoalRepository $goalRepository,
        \App\Service\PlanFlattener $planFlattener,
    ): Response {
        if ($month < 1 || $month > 12) {
            throw $this->createNotFoundException('Mois invalide.');
        }

        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $weeks = $this->buildWeeks($first, $month, $scheduledWorkoutRepository);

        // Objectifs tombant dans la fenêtre affichée (grille dense mois débords
        // compris) + prochain objectif pour le bandeau de compte à rebours.
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $gridStart = $first->modify(sprintf('-%d days', (int) $first->format('N') - 1));
        $last = $first->modify('last day of this month');
        $gridEnd = $last->modify(sprintf('+%d days', 7 - (int) $last->format('N')));
        $goalsByDate = $this->indexGoalsByDate($goalRepository->findByOwnerBetween($user, $gridStart, $gridEnd));
        $nextGoal = $goalRepository->findNextForOwner($user);

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

        return $this->rememberView($this->render('calendar/index.html.twig', [
            'year' => $year,
            'month' => $month,
            'monthLabel' => self::MONTH_NAMES[$month].' '.$year,
            'weeks' => $weeks,
            'flattened' => $flattened,
            'goalsByDate' => $goalsByDate,
            'nextGoal' => $nextGoal,
            'weekPivot' => $weekPivot->format('Y-m-d'),
            'prev' => ['year' => (int) $prev->format('Y'), 'month' => (int) $prev->format('n')],
            'next' => ['year' => (int) $next->format('Y'), 'month' => (int) $next->format('n')],
            ...$this->buildAddContext($workoutRepository, $planTemplateRepository, $scheduledWorkoutRepository),
        ]), 'month');
    }

    /**
     * Active ou régénère le jeton d'abonnement calendrier (ICS). Régénérer révoque
     * l'ancien lien (nouveau secret). Sous /calendar donc protégé ; le flux lui-même
     * vit sous /feed (hors auth), cf. PublicCalendarController.
     */
    #[Route('/feed/token', name: 'app_calendar_feed_token', methods: ['POST'])]
    public function generateFeedToken(
        Request $request,
        \Doctrine\ORM\EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('calendar_feed_token', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $regenerate = null !== $user->getCalendarFeedToken();
        $user->setCalendarFeedToken(bin2hex(random_bytes(32)));
        $entityManager->flush();

        $this->addFlash('success', $regenerate
            ? 'Lien d\'abonnement régénéré. L\'ancien lien ne fonctionne plus.'
            : 'Abonnement calendrier activé. Copie le lien pour l\'ajouter à ton agenda.');

        return $this->redirectToRoute('app_calendar_index');
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
        \App\Repository\GoalRepository $goalRepository,
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

        $goalsByDate = $this->indexGoalsByDate($goalRepository->findByOwnerBetween($user, $monday, $sunday));

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
                'goals' => $goalsByDate[$key] ?? [],
            ];
            $cursor = $cursor->modify('+1 day');
        }

        $counts = $scheduledWorkoutRepository->countByStatusForOwnerBetween($user, $monday, $sunday);

        return $this->rememberView($this->render('calendar/week.html.twig', [
            'monday' => $monday,
            'sunday' => $sunday,
            'weekLabel' => $this->formatWeekLabel($monday, $sunday),
            'isoWeek' => (int) $monday->format('W'),
            'days' => $days,
            'flattened' => $flattened,
            'nextGoal' => $goalRepository->findNextForOwner($user),
            'counts' => $counts,
            'sessionCount' => array_sum($counts),
            'totalMinutes' => $totalMinutes,
            'todayKey' => $todayKey,
            'prevDate' => $monday->modify('-7 days')->format('Y-m-d'),
            'nextDate' => $monday->modify('+7 days')->format('Y-m-d'),
            'monthPivot' => ['year' => (int) $monday->modify('+3 days')->format('Y'), 'month' => (int) $monday->modify('+3 days')->format('n')],
            ...$this->buildAddContext($workoutRepository, $planTemplateRepository, $scheduledWorkoutRepository),
        ]), 'week');
    }

    /**
     * Mémorise la vue courante ('month' | 'week') dans un cookie lu par
     * app_calendar_index et par les redirections de mutation
     * (ScheduledWorkoutController) : la vue choisie survit à un refresh et aux
     * actions de planning.
     */
    private function rememberView(Response $response, string $view): Response
    {
        $response->headers->setCookie(
            Cookie::create('kd_calview', $view, new \DateTimeImmutable('+1 year'), '/', null, false, true, false, Cookie::SAMESITE_LAX)
        );

        return $response;
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
     * Contexte commun aux vues mois et semaine : cartes de séances (modale « poser
     * une séance » ouverte par le « + » d'un jour), formulaire + cartes de plans
     * (modale « instancier un plan »), plans instanciés (retrait rapide) et statuts.
     *
     * @return array<string, mixed>
     */
    private function buildAddContext(
        WorkoutRepository $workoutRepository,
        PlanTemplateRepository $planTemplateRepository,
        ScheduledWorkoutRepository $scheduledWorkoutRepository,
    ): array {
        $plans = $planTemplateRepository->findBy(['owner' => $this->getUser()], ['title' => 'ASC']);

        $instantiateForm = $this->createForm(PlanInstantiationType::class, null, [
            'action' => $this->generateUrl('app_scheduled_workout_instantiate'),
            'planTemplates' => $plans,
        ]);

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        return [
            'instantiateForm' => $instantiateForm,
            'instantiatedPlans' => $scheduledWorkoutRepository->findInstantiatedPlansForOwner($user),
            'statuses' => \App\Enum\ScheduledStatus::cases(),
            // Abonnement ICS : jeton (null tant que non activé) consommé par la
            // modale « S'abonner » ; les URLs de flux sont bâties côté Twig via url().
            'feedToken' => $user->getCalendarFeedToken(),
            ...$this->buildPickerCards($workoutRepository, $plans),
        ];
    }

    /**
     * Repères de carte pour les deux modales de sélection : séances de bibliothèque
     * (activités distinctes, nb d'exos, texte de recherche) et plans (durée, nb de
     * séances). Contenu fetch-joint pour les séances (anti N+1), calqué sur la
     * palette de l'éditeur de trame.
     *
     * @param list<\App\Entity\PlanTemplate> $plans
     *
     * @return array<string, mixed>
     */
    private function buildPickerCards(WorkoutRepository $workoutRepository, array $plans): array
    {
        $workouts = $workoutRepository->findLibraryForOwnerWithContent($this->getUser());

        $cards = [];
        $countsByActivity = [];
        foreach ($workouts as $workout) {
            $activities = $this->workoutMetrics->distinctActivities($workout);

            $filterText = (string) $workout->getTitle();
            foreach ($activities as $activity) {
                $countsByActivity[$activity->value] = ($countsByActivity[$activity->value] ?? 0) + 1;
                $filterText .= ' '.$activity->getLabel();
            }

            $cards[] = [
                'workout' => $workout,
                'activities' => $activities,
                'exerciseCount' => $this->workoutMetrics->exerciseCount($workout),
                'filterText' => $filterText,
            ];
        }

        $activityFilters = [];
        foreach (ActivityType::cases() as $activity) {
            if (isset($countsByActivity[$activity->value])) {
                $activityFilters[] = [
                    'value' => $activity->value,
                    'label' => $activity->getLabel(),
                    'count' => $countsByActivity[$activity->value],
                ];
            }
        }

        $planCards = [];
        foreach ($plans as $plan) {
            $planCards[] = [
                'plan' => $plan,
                'weeks' => $plan->getDurationWeeks() ?? 0,
                'sessionCount' => $plan->getPlanItems()->count(),
            ];
        }

        return [
            'workoutCards' => $cards,
            'workoutActivities' => $activityFilters,
            'planCards' => $planCards,
        ];
    }

    /**
     * Indexe des objectifs par date d'échéance (Y-m-d). Plusieurs objectifs peuvent
     * tomber le même jour, d'où une liste par clé.
     *
     * @param list<\App\Entity\Goal> $goals
     *
     * @return array<string, list<\App\Entity\Goal>>
     */
    private function indexGoalsByDate(array $goals): array
    {
        $byDate = [];
        foreach ($goals as $goal) {
            $byDate[$goal->getTargetDate()->format('Y-m-d')][] = $goal;
        }

        return $byDate;
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
