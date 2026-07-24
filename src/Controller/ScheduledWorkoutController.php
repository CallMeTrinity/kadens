<?php

namespace App\Controller;

use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Enum\ScheduledStatus;
use App\Form\PlanInstantiationType;
use App\Form\ScheduleWorkoutType;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\WorkoutRepository;
use App\Security\Voter\PlanTemplateVoter;
use App\Security\Voter\ScheduledWorkoutVoter;
use App\Service\PlanScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Mutations des séances planifiées (instances datées). Chaque action redirige
 * vers le mois de calendrier concerné : le rendu du planning reste porté par
 * CalendarController, ici on ne fait qu'écrire.
 */
#[Route('/schedule')]
final class ScheduledWorkoutController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Pose une séance existante sur une date précise, hors de tout plan.
     */
    #[Route('/workout', name: 'app_scheduled_workout_add', methods: ['POST'])]
    public function add(Request $request, WorkoutRepository $workoutRepository): Response
    {
        $scheduled = new ScheduledWorkout();
        $form = $this->createForm(ScheduleWorkoutType::class, $scheduled, [
            'workouts' => $workoutRepository->findLibraryForOwner($this->getUser()),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $scheduled->setOwner($this->getUser());
            $scheduled->setStatus(ScheduledStatus::PLANNED);
            $this->entityManager->persist($scheduled);
            $this->entityManager->flush();

            $this->addFlash('success', 'Séance planifiée.');

            return $this->redirectToMonth($scheduled->getScheduledDate());
        }

        $this->addFlash('error', 'Impossible de planifier cette séance.');

        return $this->redirectToCurrentMonth();
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
    public function updateStatus(Request $request, ScheduledWorkout $scheduled): Response
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

                $this->addFlash('success', 'Statut mis à jour.');
            } else {
                $this->addFlash('error', 'Statut invalide.');
            }
        }

        return $this->redirectToMonth($scheduled->getScheduledDate());
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

    private function redirectToMonth(\DateTimeImmutable $date): Response
    {
        return $this->redirectToRoute('app_calendar_month', [
            'year' => (int) $date->format('Y'),
            'month' => (int) $date->format('n'),
        ]);
    }

    private function redirectToCurrentMonth(): Response
    {
        return $this->redirectToRoute('app_calendar_index');
    }

    /**
     * Redirige vers le mois de calendrier porté par le formulaire (champs cachés
     * year/month), avec repli sur le mois courant si absent ou invalide.
     */
    private function monthFromPayload(\Symfony\Component\HttpFoundation\InputBag $payload): Response
    {
        $year = $payload->getInt('year');
        $month = $payload->getInt('month');

        if ($year >= 1 && $month >= 1 && $month <= 12) {
            return $this->redirectToRoute('app_calendar_month', ['year' => $year, 'month' => $month]);
        }

        return $this->redirectToCurrentMonth();
    }
}
