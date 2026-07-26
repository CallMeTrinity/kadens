<?php

namespace App\Controller;

use App\Entity\Goal;
use App\Entity\PlanTemplate;
use App\Entity\User;
use App\Form\GoalType;
use App\Repository\GoalRepository;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Security\Voter\GoalVoter;
use App\Security\Voter\PlanTemplateVoter;
use App\Service\PlanScheduler;
use App\Service\SlugGenerator;
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

        // Le propriétaire, pas l'utilisateur courant : un coach peut consulter
        // l'objectif de son athlète, et c'est le contenu de l'ATHLÈTE qu'il doit
        // voir (ses plans, ses séances datées).
        $owner = $goal->getOwner();

        // Séances datées menant à l'échéance (fenêtre : ~16 semaines avant → jour J).
        $windowStart = $goal->getTargetDate()->modify('-16 weeks');
        $leadUp = $scheduledWorkoutRepository->findByOwnerBetween($owner, $windowStart, $goal->getTargetDate());

        $plans = $planTemplateRepository->findBy(['owner' => $owner], ['title' => 'ASC']);

        return $this->render('goal/show.html.twig', [
            'goal' => $goal,
            'plans' => $plans,
            // Plans rattachables : ceux du propriétaire qui ne le sont pas déjà.
            'attachablePlans' => array_values(array_filter(
                $plans,
                static fn ($plan) => !$goal->getPlanTemplates()->contains($plan),
            )),
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
     * Rattache/détache un plan existant à cet objectif (relation N:N, réversible).
     * Le pendant de `app_plan_template_goals` : le lien se pose des deux côtés.
     */
    #[Route('/{id}/plans', name: 'app_goal_plans', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updatePlans(Request $request, Goal $goal, PlanTemplateRepository $planTemplateRepository, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(GoalVoter::EDIT, $goal);

        if (!$this->isCsrfTokenValid('goal_plans'.$goal->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $plan = $planTemplateRepository->find($request->request->getInt('planTemplate'));

        // Même propriétaire que l'objectif : le coach travaille sur le contenu de
        // son athlète, jamais sur le sien depuis cette page.
        if (null !== $plan && $plan->getOwner() === $goal->getOwner()) {
            if ('detach' === (string) $request->request->get('action')) {
                $plan->removeGoal($goal);
            } else {
                $plan->addGoal($goal);
            }
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_goal_show', ['id' => $goal->getId()]);
    }

    /**
     * Crée un plan vide DÉJÀ rattaché à cet objectif et bascule sur l'éditeur de
     * trame. Même geste qu'ailleurs (brouillon titré par défaut, 4 semaines,
     * `rename=1`), avec le rattachement en plus : on part de l'échéance pour
     * construire la préparation.
     */
    #[Route('/{id}/plans/new', name: 'app_goal_plan_new', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function newPlanForGoal(Request $request, Goal $goal, SlugGenerator $slugGenerator, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(GoalVoter::EDIT, $goal);

        if (!$this->isCsrfTokenValid('goal_plan_new'.$goal->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $title = PlanTemplateController::DRAFT_PLAN_TITLE;
        $plan = (new PlanTemplate())
            ->setOwner($goal->getOwner())
            ->setTitle($title)
            ->setDurationWeeks(PlanTemplateController::DRAFT_PLAN_WEEKS)
            ->setSlug($slugGenerator->generate($title, PlanTemplate::class));
        $plan->addGoal($goal);

        $entityManager->persist($plan);
        $entityManager->flush();

        return $this->redirectToRoute('app_plan_template_edit', ['id' => $plan->getId(), 'rename' => 1]);
    }

    /**
     * Ancre un plan sur l'échéance : on raisonne à l'envers (jour J → date de
     * départ). La date de départ est calculée pour que la DERNIÈRE semaine du plan
     * tombe sur la semaine ISO de l'objectif, puis PlanScheduler instancie
     * (idempotent : re-poser un plan déjà instancié resynchronise sans redater).
     * Le plan posé est rattaché à l'objectif au passage : le lien ne se perd plus.
     */
    #[Route('/{id}/prepare', name: 'app_goal_prepare', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function prepare(
        Request $request,
        Goal $goal,
        PlanTemplateRepository $planTemplateRepository,
        PlanScheduler $planScheduler,
        EntityManagerInterface $entityManager,
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

        // Le lien ne se perd plus : poser un plan pour une échéance, c'est dire que
        // ce plan la prépare. Idempotent (addGoal ignore un doublon).
        $plan->addGoal($goal);
        $entityManager->flush();

        $this->addFlash('success', sprintf('Plan « %s » posé : %d séance%s, dernière semaine calée sur ton échéance.', $plan->getTitle(), \count($created), \count($created) > 1 ? 's' : ''));

        return $this->redirectToRoute('app_goal_show', ['id' => $goal->getId()]);
    }
}
