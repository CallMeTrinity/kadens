<?php

namespace App\Controller;

use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Enum\ScheduledStatus;
use App\Form\PlanInstantiationType;
use App\Form\ScheduleWorkoutType;
use App\Repository\PlanTemplateRepository;
use App\Repository\WorkoutRepository;
use App\Security\Voter\PlanTemplateVoter;
use App\Security\Voter\ScheduledWorkoutVoter;
use App\Service\PlanInstantiator;
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
            'workouts' => $workoutRepository->findBy(['owner' => $this->getUser()]),
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
     * Instancie un plan complet à partir d'une date : PlanInstantiator projette
     * la trame sur des dates réelles et crée N ScheduledWorkout.
     */
    #[Route('/plan', name: 'app_scheduled_workout_instantiate', methods: ['POST'])]
    public function instantiate(
        Request $request,
        PlanTemplateRepository $planTemplateRepository,
        PlanInstantiator $planInstantiator,
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

            $created = $planInstantiator->instantiate($template, $this->getUser(), $startDate);

            $this->addFlash('success', sprintf('Plan instancié : %d séance(s) planifiée(s).', count($created)));

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
}
