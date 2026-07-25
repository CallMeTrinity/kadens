<?php

namespace App\Controller;

use App\Entity\Goal;
use App\Entity\User;
use App\Form\GoalType;
use App\Repository\GoalRepository;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Security\Voter\GoalVoter;
use App\Security\Voter\PlanTemplateVoter;
use App\Service\PlanScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Objectifs datés : les échéances vers lesquelles l'athlète s'entraîne. CRUD
 * owner-only (GoalVoter), plus l'ancrage d'un plan sur une date d'arrivée
 * (« préparer ce plan pour cette échéance ») qui délègue à PlanScheduler.
 */
#[Route('/goal')]
final class GoalController extends AbstractController
{
    #[Route('', name: 'app_goal_index', methods: ['GET'])]
    public function index(GoalRepository $goalRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('goal/index.html.twig', [
            'upcoming' => $goalRepository->findUpcomingForOwner($user),
            'past' => $goalRepository->findPastForOwner($user),
        ]);
    }

    #[Route('/new', name: 'app_goal_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $goal = new Goal();
        $form = $this->createForm(GoalType::class, $goal);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $this->getUser();
            $goal->setOwner($user);
            $entityManager->persist($goal);
            $entityManager->flush();
            $this->addFlash('success', 'Objectif créé.');

            return $this->redirectToRoute('app_goal_show', ['id' => $goal->getId()]);
        }

        return $this->render('goal/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_goal_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        Goal $goal,
        PlanTemplateRepository $planTemplateRepository,
        ScheduledWorkoutRepository $scheduledWorkoutRepository,
    ): Response {
        $this->denyAccessUnlessGranted(GoalVoter::VIEW, $goal);

        /** @var User $user */
        $user = $this->getUser();

        // Séances datées menant à l'échéance (fenêtre : ~16 semaines avant → jour J).
        $windowStart = $goal->getTargetDate()->modify('-16 weeks');
        $leadUp = $scheduledWorkoutRepository->findByOwnerBetween($user, $windowStart, $goal->getTargetDate());

        return $this->render('goal/show.html.twig', [
            'goal' => $goal,
            'plans' => $planTemplateRepository->findBy(['owner' => $user], ['title' => 'ASC']),
            'leadUp' => $leadUp,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_goal_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Goal $goal, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(GoalVoter::EDIT, $goal);

        $form = $this->createForm(GoalType::class, $goal);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Objectif mis à jour.');

            return $this->redirectToRoute('app_goal_show', ['id' => $goal->getId()]);
        }

        return $this->render('goal/edit.html.twig', [
            'goal' => $goal,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_goal_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Goal $goal, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(GoalVoter::DELETE, $goal);

        if (!$this->isCsrfTokenValid('delete_goal'.$goal->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $entityManager->remove($goal);
        $entityManager->flush();
        $this->addFlash('success', 'Objectif supprimé.');

        return $this->redirectToRoute('app_goal_index');
    }

    /**
     * Ancre un plan sur l'échéance : on raisonne à l'envers (jour J → date de
     * départ). La date de départ est calculée pour que la DERNIÈRE semaine du plan
     * tombe sur la semaine ISO de l'objectif, puis PlanScheduler instancie
     * (idempotent : re-poser un plan déjà instancié resynchronise sans redater).
     */
    #[Route('/{id}/prepare', name: 'app_goal_prepare', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function prepare(
        Request $request,
        Goal $goal,
        PlanTemplateRepository $planTemplateRepository,
        PlanScheduler $planScheduler,
    ): Response {
        $this->denyAccessUnlessGranted(GoalVoter::EDIT, $goal);

        if (!$this->isCsrfTokenValid('prepare_goal'.$goal->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $plan = $planTemplateRepository->find($request->request->getInt('planTemplate'));
        if (null === $plan) {
            throw $this->createNotFoundException('Plan introuvable.');
        }
        $this->denyAccessUnlessGranted(PlanTemplateVoter::VIEW, $plan);

        /** @var User $user */
        $user = $this->getUser();

        $weeks = max(1, $plan->getDurationWeeks() ?? 1);
        $target = $goal->getTargetDate();
        // Lundi ISO de la semaine de l'échéance, puis on remonte de (semaines - 1)
        // pour que la dernière semaine du plan couvre la semaine du jour J.
        $goalMonday = $target->setTime(0, 0)->modify(sprintf('-%d days', (int) $target->format('N') - 1));
        $start = $goalMonday->modify(sprintf('-%d days', ($weeks - 1) * 7));

        if ($planScheduler->isInstantiated($plan, $user)) {
            $this->addFlash('error', sprintf('Le plan « %s » est déjà posé sur ton calendrier. Vide-le d\'abord pour le ré-ancrer sur cette échéance.', $plan->getTitle()));

            return $this->redirectToRoute('app_goal_show', ['id' => $goal->getId()]);
        }

        $created = $planScheduler->instantiate($plan, $user, $start);
        $this->addFlash('success', sprintf('Plan « %s » posé : %d séance%s, dernière semaine calée sur ton échéance.', $plan->getTitle(), \count($created), \count($created) > 1 ? 's' : ''));

        return $this->redirectToRoute('app_goal_show', ['id' => $goal->getId()]);
    }
}
