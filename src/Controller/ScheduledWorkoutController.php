<?php

namespace App\Controller;

use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Enum\ScheduledStatus;
use App\Form\PlanInstantiationType;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\WorkoutRepository;
use App\Security\Voter\PlanTemplateVoter;
use App\Security\Voter\ScheduledWorkoutVoter;
use App\Security\Voter\WorkoutVoter;
use App\Service\PlanFlattener;
use App\Service\PlanScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Mutations des séances planifiées (instances datées). Les actions redirigent
 * vers le calendrier concerné (dans la vue mémorisée, cf. preferredCalendarView) ;
 * le rendu du planning reste porté par CalendarController. Exception : le
 * changement de statut répond en Turbo Stream (re-render de la seule pastille,
 * sans rechargement), avec repli redirection sans JS.
 */
#[Route('/schedule')]
final class ScheduledWorkoutController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Pose une séance de bibliothèque sur une date précise, hors de tout plan.
     * Endpoint lean posté par la modale « poser une séance » du calendrier (une
     * carte cliquée = un submit workoutId+date, calqué sur la palette de plan) :
     * pas de formulaire Symfony, la date vient du « + » du jour choisi.
     */
    #[Route('/place', name: 'app_scheduled_workout_place', methods: ['POST'])]
    public function place(Request $request, WorkoutRepository $workoutRepository): Response
    {
        $payload = $request->getPayload();

        if (!$this->isCsrfTokenValid('schedule_place', $payload->getString('_token'))) {
            return $this->redirectToCurrentMonth();
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $payload->getString('date')) ?: null;
        $workout = $workoutRepository->find($payload->getInt('workoutId'));

        // planLocal = copie privée portée par un plan : jamais posable seule.
        if (null === $date || null === $workout || $workout->isPlanLocal()) {
            $this->addFlash('error', 'Impossible de planifier cette séance.');

            return null === $date ? $this->redirectToCurrentMonth() : $this->redirectToMonth($date);
        }

        $this->denyAccessUnlessGranted(WorkoutVoter::VIEW, $workout);

        $scheduled = new ScheduledWorkout();
        $scheduled->setWorkout($workout);
        $scheduled->setScheduledDate($date);
        $scheduled->setOwner($this->getUser());
        $scheduled->setStatus(ScheduledStatus::PLANNED);
        $this->entityManager->persist($scheduled);
        $this->entityManager->flush();

        $this->addFlash('success', 'Séance planifiée.');

        return $this->redirectToMonth($date);
    }

    /**
     * Instancie un plan complet à partir d'une date : PlanScheduler projette
     * la trame sur des dates réelles et crée N ScheduledWorkout.
     */
    #[Route('/plan', name: 'app_scheduled_workout_instantiate', methods: ['POST'])]
    public function instantiate(
        Request $request,
        PlanTemplateRepository $planTemplateRepository,
        PlanScheduler $planScheduler,
    ): Response {
        $form = $this->createForm(PlanInstantiationType::class, null, [
            'planTemplates' => $planTemplateRepository->findBy(['owner' => $this->getUser()]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var PlanTemplate $template */
            $template = $form->get('planTemplate')->getData();
            /** @var \DateTimeImmutable $startDate */
            $startDate = $form->get('startDate')->getData();

            $this->denyAccessUnlessGranted(PlanTemplateVoter::VIEW, $template);

            $alreadyOnCalendar = $planScheduler->isInstantiated($template, $this->getUser());
            $created = $planScheduler->instantiate($template, $this->getUser(), $startDate);

            $this->addFlash('success', $alreadyOnCalendar
                ? sprintf('Plan resynchronisé : %d nouvelle(s) séance(s) ajoutée(s).', count($created))
                : sprintf('Plan instancié : %d séance(s) planifiée(s).', count($created)));

            return $this->redirectToMonth($startDate);
        }

        $this->addFlash('error', 'Impossible d\'instancier ce plan.');

        return $this->redirectToCurrentMonth();
    }

    /**
     * Déplace une séance planifiée sur une autre date (référence vivante : seule
     * la date change, la séance reste la même).
     */
    #[Route('/{id}/move', name: 'app_scheduled_workout_move', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function move(Request $request, ScheduledWorkout $scheduled): Response
    {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::EDIT, $scheduled);

        $raw = $request->getPayload()->getString('scheduledDate');
        $newDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw) ?: null;

        if (null !== $newDate && $this->isCsrfTokenValid('move'.$scheduled->getId(), $request->getPayload()->getString('_token'))) {
            $scheduled->setScheduledDate($newDate);
            $this->entityManager->flush();

            $this->addFlash('success', 'Séance déplacée.');

            return $this->redirectToMonth($newDate);
        }

        return $this->redirectToMonth($scheduled->getScheduledDate());
    }

    /**
     * Boucle « prévu vs réalisé » (Phase 7) : marque une séance planifiée comme
     * faite / manquée / à nouveau prévue, avec une note d'écart léger optionnelle.
     * Pas de log détaillé de séries — Strava fait le suivi, ici on ne fait que
     * boucler sur la prévision.
     */
    #[Route('/{id}/status', name: 'app_scheduled_workout_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateStatus(Request $request, ScheduledWorkout $scheduled, PlanFlattener $planFlattener): Response
    {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::EDIT, $scheduled);

        $payload = $request->getPayload();

        if ($this->isCsrfTokenValid('status'.$scheduled->getId(), $payload->getString('_token'))) {
            $status = ScheduledStatus::tryFrom($payload->getString('status'));

            if (null !== $status) {
                $scheduled->setStatus($status);

                $notes = trim($payload->getString('completionNotes'));
                $scheduled->setCompletionNotes('' === $notes ? null : $notes);

                $this->entityManager->flush();

                // Réponse asynchrone : on re-rend juste la pastille, la page (et
                // donc la vue mois/semaine) n'est pas rechargée. Repli sans JS =
                // redirection classique.
                if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                    return $this->streamCalEvent($request, $scheduled, $planFlattener);
                }

                $this->addFlash('success', 'Statut mis à jour.');
            } else {
                $this->addFlash('error', 'Statut invalide.');
            }
        }

        return $this->redirectToMonth($scheduled->getScheduledDate());
    }

    /**
     * Cycle rapide du statut (clic sur la zone gauche d'une pastille au
     * calendrier) : prévue → faite → manquée → prévue. Ne touche pas la note
     * d'écart (contrairement au formulaire complet de la modale) : c'est un
     * geste express, pas une saisie. Repli sans JS : c'est un vrai bouton de
     * formulaire, il fonctionne sans Stimulus.
     */
    #[Route('/{id}/cycle-status', name: 'app_scheduled_workout_cycle_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cycleStatus(Request $request, ScheduledWorkout $scheduled, PlanFlattener $planFlattener): Response
    {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::EDIT, $scheduled);

        if ($this->isCsrfTokenValid('cycle'.$scheduled->getId(), $request->getPayload()->getString('_token'))) {
            $scheduled->setStatus($scheduled->getStatus()->next());
            $this->entityManager->flush();

            // Geste express : on re-rend la pastille en place (pas de rechargement,
            // la vue mois/semaine est préservée). Repli sans JS = redirection.
            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                return $this->streamCalEvent($request, $scheduled, $planFlattener);
            }
        }

        return $this->redirectToMonth($scheduled->getScheduledDate());
    }

    /**
     * Re-rend la pastille d'une séance datée en Turbo Stream (action="replace"
     * sur `#cal-event-{id}`), à l'identique de son rendu d'origine. `detailed`
     * (vue semaine) est reporté par le formulaire ; `overdue` est recalculé.
     */
    private function streamCalEvent(Request $request, ScheduledWorkout $scheduled, PlanFlattener $planFlattener): Response
    {
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        $today = new \DateTimeImmutable('today');
        $overdue = ScheduledStatus::PLANNED === $scheduled->getStatus()
            && $scheduled->getScheduledDate() < $today;

        return $this->render('calendar/stream/cal_event.stream.html.twig', [
            'scheduled' => $scheduled,
            'fw' => $planFlattener->flattenWorkout($scheduled->getWorkout()),
            'statuses' => ScheduledStatus::cases(),
            'detailed' => (bool) $request->getPayload()->getInt('detailed'),
            'overdue' => $overdue,
        ]);
    }

    /**
     * Efface d'un coup un plan instancié : supprime TOUTES les séances datées
     * qui en proviennent (y compris DONE/MISSED — c'est une action explicite et
     * globale, distincte du retrait d'une case qui préserve le réalisé). Le
     * PlanTemplate n'est pas touché, seule son instanciation calendrier disparaît.
     * Permet notamment de vider un plan pour le ré-instancier sur une autre date.
     */
    #[Route('/plan/clear', name: 'app_scheduled_workout_clear_plan', methods: ['POST'])]
    public function clearPlan(
        Request $request,
        PlanTemplateRepository $planTemplateRepository,
        ScheduledWorkoutRepository $repository,
    ): Response {
        $payload = $request->getPayload();
        $redirect = $this->monthFromPayload($payload);

        if (!$this->isCsrfTokenValid('clear_plan', $payload->getString('_token'))) {
            return $redirect;
        }

        $template = $planTemplateRepository->find($payload->getInt('planId'));
        if (null === $template) {
            $this->addFlash('error', 'Plan introuvable.');

            return $redirect;
        }

        $this->denyAccessUnlessGranted(PlanTemplateVoter::VIEW, $template);

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $scheduled = $repository->findBySourcePlanTemplateForOwner($template, $user);

        foreach ($scheduled as $one) {
            $this->entityManager->remove($one);
        }
        $this->entityManager->flush();

        $this->addFlash('success', sprintf(
            'Plan « %s » retiré du planning : %d séance(s) supprimée(s).',
            $template->getTitle(),
            \count($scheduled),
        ));

        return $redirect;
    }

    #[Route('/{id}/delete', name: 'app_scheduled_workout_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, ScheduledWorkout $scheduled): Response
    {
        $this->denyAccessUnlessGranted(ScheduledWorkoutVoter::DELETE, $scheduled);

        $date = $scheduled->getScheduledDate();

        if ($this->isCsrfTokenValid('delete'.$scheduled->getId(), $request->getPayload()->getString('_token'))) {
            $this->entityManager->remove($scheduled);
            $this->entityManager->flush();

            $this->addFlash('success', 'Séance retirée du planning.');
        }

        return $this->redirectToMonth($date);
    }

    /**
     * Redirige vers le calendrier positionné sur `$date`, dans la vue préférée de
     * l'utilisateur (cookie `kd_calview` posé par CalendarController). Ainsi une
     * mutation faite en vue semaine ré-atterrit en vue semaine (« résistance au
     * refresh »), sans se voir renvoyée en vue mois.
     */
    private function redirectToMonth(\DateTimeImmutable $date): Response
    {
        if ('week' === $this->preferredCalendarView()) {
            return $this->redirectToRoute('app_calendar_week', ['date' => $date->format('Y-m-d')]);
        }

        return $this->redirectToRoute('app_calendar_month', [
            'year' => (int) $date->format('Y'),
            'month' => (int) $date->format('n'),
        ]);
    }

    private function redirectToCurrentMonth(): Response
    {
        // app_calendar_index respecte lui-même le cookie de vue.
        return $this->redirectToRoute('app_calendar_index');
    }

    /**
     * Redirige vers le mois de calendrier porté par le formulaire (champs cachés
     * year/month), avec repli sur le mois courant si absent ou invalide. Respecte
     * la vue préférée (semaine → semaine contenant le 1er du mois visé).
     */
    private function monthFromPayload(\Symfony\Component\HttpFoundation\InputBag $payload): Response
    {
        $year = $payload->getInt('year');
        $month = $payload->getInt('month');

        if ($year >= 1 && $month >= 1 && $month <= 12) {
            return $this->redirectToMonth(new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)));
        }

        return $this->redirectToCurrentMonth();
    }

    /** Vue calendrier mémorisée côté cookie ('week' | 'month', défaut 'month'). */
    private function preferredCalendarView(): string
    {
        return 'week' === $this->requestStack->getCurrentRequest()?->cookies->get('kd_calview')
            ? 'week' : 'month';
    }
}
