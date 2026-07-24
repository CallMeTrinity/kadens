<?php

namespace App\Controller;

use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Enum\ScheduledStatus;
use App\Form\PlanItemType;
use App\Form\PlanTemplateType;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\WorkoutRepository;
use App\Security\Voter\PlanTemplateVoter;
use App\Service\PlanFlattener;
use App\Service\PlanScheduler;
use App\Service\SlugGenerator;
use App\Service\WorkoutCloner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

#[Route('/plan-template')]
final class PlanTemplateController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FormFactoryInterface $formFactory,
        private readonly PlanFlattener $planFlattener,
        private readonly WorkoutRepository $workoutRepository,
    ) {
    }

    #[Route('', name: 'app_plan_template_index', methods: ['GET'])]
    public function index(PlanTemplateRepository $planTemplateRepository): Response
    {
        return $this->render('plan_template/index.html.twig', [
            'planTemplates' => $planTemplateRepository->findBy(['owner' => $this->getUser()], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/new', name: 'app_plan_template_new', methods: ['GET', 'POST'])]
    public function new(Request $request, SlugGenerator $slugGenerator): Response
    {
        $template = new PlanTemplate();
        $form = $this->createForm(PlanTemplateType::class, $template);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $template->setOwner($this->getUser());
            $template->setSlug($slugGenerator->generate($template->getTitle(), PlanTemplate::class));
            $this->entityManager->persist($template);
            $this->entityManager->flush();

            $this->addFlash('success', 'Plan créé. Compose sa trame maintenant.');

            return $this->redirectToRoute('app_plan_template_edit', ['id' => $template->getId()]);
        }

        return $this->render('plan_template/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_plan_template_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(PlanTemplate $template): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::VIEW, $template);

        return $this->render('plan_template/show.html.twig', [
            'flat' => $this->planFlattener->flattenPlanTemplate($template),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_plan_template_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, PlanTemplate $template): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);

        $form = $this->createForm(PlanTemplateType::class, $template);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Plan mis à jour.');

            return $this->redirectToRoute('app_plan_template_edit', ['id' => $template->getId()]);
        }

        return $this->render('plan_template/edit.html.twig', [
            'form' => $form,
        ] + $this->gridContext($template));
    }

    #[Route('/{id}/delete', name: 'app_plan_template_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, PlanTemplate $template): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::DELETE, $template);

        if ($this->isCsrfTokenValid('delete'.$template->getId(), $request->getPayload()->getString('_token'))) {
            $this->entityManager->remove($template);
            $this->entityManager->flush();

            $this->addFlash('success', 'Plan supprimé.');
        }

        return $this->redirectToRoute('app_plan_template_index');
    }

    /**
     * Duplique un plan visible en une copie appartenant à l'utilisateur courant
     * (utile pour itérer sans repartir de zéro). Le template source reste intact.
     */
    #[Route('/{id}/duplicate', name: 'app_plan_template_duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(Request $request, PlanTemplate $template, SlugGenerator $slugGenerator, WorkoutCloner $cloner): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::VIEW, $template);

        if (!$this->isCsrfTokenValid('duplicate'.$template->getId(), $request->getPayload()->getString('_token'))) {
            return $this->redirectToRoute('app_plan_template_edit', ['id' => $template->getId()]);
        }

        $copy = (new PlanTemplate())
            ->setOwner($this->getUser())
            ->setTitle($template->getTitle().' (copie)')
            ->setDescription($template->getDescription())
            ->setDurationWeeks($template->getDurationWeeks())
            ->setSlug($slugGenerator->generate($template->getTitle().' copie', PlanTemplate::class));

        // Chaque case porte sa PROPRE copie de séance (progression indépendante) :
        // on clone donc la copie locale, pas seulement le placement. Sans ça, les
        // deux plans partageraient les mêmes séances et s'éditeraient mutuellement.
        foreach ($template->getPlanItems() as $item) {
            $workoutCopy = $cloner->cloneWorkout($item->getWorkout(), $this->getUser(), $item->getWorkout()->getTitle(), true);
            $copy->addPlanItem(
                (new PlanItem())
                    ->setWorkout($workoutCopy)
                    ->setWeekNumber($item->getWeekNumber())
                    ->setDayOfWeek($item->getDayOfWeek())
                    ->setNotes($item->getNotes())
            );
        }

        // cascade persist depuis PlanTemplate propage aux PlanItem.
        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        $this->addFlash('success', 'Plan dupliqué.');

        return $this->redirectToRoute('app_plan_template_edit', ['id' => $copy->getId()]);
    }

    // ---- Édition de la trame (placement des séances) -----------------------

    #[Route('/{id}/weeks/{week}/days/{day}/items', name: 'app_plan_template_item_add', methods: ['POST'], requirements: ['id' => '\d+', 'week' => '\d+', 'day' => '[1-7]'])]
    public function addItem(Request $request, PlanTemplate $template, int $week, int $day, WorkoutCloner $cloner, PlanScheduler $scheduler): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $this->assertCell($template, $week, $day);

        $item = (new PlanItem())->setWeekNumber($week)->setDayOfWeek($day);
        $form = $this->createAddItemForm($template, $week, $day, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Le formulaire a lié la séance de bibliothèque choisie sur $item.
            // Fork à la pose : la case reçoit sa PROPRE copie (planLocal), éditable
            // et progressable sans toucher la séance source ni les autres cases.
            $chosen = $item->getWorkout();
            if (null !== $chosen) {
                $copy = $cloner->cloneWorkout($chosen, $this->getUser(), $chosen->getTitle(), true);
                $item->setWorkout($copy);
                $template->addPlanItem($item);
                $this->entityManager->persist($item);
                $this->entityManager->flush();

                // Si le plan est déjà posé sur le calendrier, la nouvelle case y
                // apparaît (resync add-only, no-op sinon).
                $scheduler->resync($template);
            }
        }

        return $this->gridResponse($request, $template);
    }

    #[Route('/{id}/items/{itemId}/delete', name: 'app_plan_template_item_delete', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function deleteItem(Request $request, PlanTemplate $template, int $itemId, ScheduledWorkoutRepository $scheduledRepository): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $item = $this->findItem($template, $itemId);

        if ($this->isCsrfTokenValid('item_delete'.$itemId, $request->getPayload()->getString('_token'))) {
            $copy = $item->getWorkout();

            // Séances datées issues de cette case : on retire celles encore prévues,
            // on PRÉSERVE celles faites/manquées (leur FUT vaut historique). Leur
            // lien vers la case passera à NULL au retrait de la case (SET NULL).
            $kept = 0;
            foreach ($scheduledRepository->findBySourcePlanItem($item) as $scheduled) {
                if (ScheduledStatus::PLANNED === $scheduled->getStatus()) {
                    $this->entityManager->remove($scheduled);
                } else {
                    ++$kept;
                }
            }

            $template->removePlanItem($item);
            $this->entityManager->flush();

            // Copie orpheline (plus aucune séance datée préservée ne la référence) :
            // on la nettoie. Si une séance faite la référence encore, on la garde
            // (sinon la cascade workout->scheduled effacerait le réalisé).
            if (null !== $copy && $copy->isPlanLocal() && 0 === $kept) {
                $this->entityManager->remove($copy);
                $this->entityManager->flush();
            }
        }

        return $this->gridResponse($request, $template);
    }

    /**
     * Duplique le contenu d'une semaine sur la semaine suivante. Chaque séance est
     * clonée en une nouvelle copie locale (progression indépendante). Support de la
     * construction incrémentale : « ma semaine 1 est bonne, je la reporte en 2 puis
     * je fais progresser ».
     */
    #[Route('/{id}/weeks/{week}/duplicate', name: 'app_plan_template_week_duplicate', methods: ['POST'], requirements: ['id' => '\d+', 'week' => '\d+'])]
    public function duplicateWeek(Request $request, PlanTemplate $template, int $week, WorkoutCloner $cloner, PlanScheduler $scheduler): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);

        $target = $week + 1;
        if ($this->isCsrfTokenValid('week_duplicate'.$week, $request->getPayload()->getString('_token'))
            && $week >= 1 && $target <= (int) $template->getDurationWeeks()) {
            // Snapshot des cases source AVANT d'ajouter (on modifie la collection).
            $sources = [];
            foreach ($template->getPlanItems() as $item) {
                if ($item->getWeekNumber() === $week) {
                    $sources[] = $item;
                }
            }

            foreach ($sources as $item) {
                $copy = $cloner->cloneWorkout($item->getWorkout(), $this->getUser(), $item->getWorkout()->getTitle(), true);
                $newItem = (new PlanItem())
                    ->setWeekNumber($target)
                    ->setDayOfWeek($item->getDayOfWeek())
                    ->setNotes($item->getNotes())
                    ->setWorkout($copy);
                $template->addPlanItem($newItem);
                $this->entityManager->persist($newItem);
            }

            $this->entityManager->flush();
            $scheduler->resync($template);
        }

        return $this->gridResponse($request, $template);
    }

    /**
     * Déplace une case dans une autre semaine/jour (glisser-déposer SortableJS).
     * Réaligne les séances datées ENCORE PRÉVUES sur la nouvelle position (le
     * calendrier suit le plan), en préservant les DONE/MISSED (leur date =
     * réalisé). Voir PlanScheduler::rescheduleItem.
     */
    #[Route('/{id}/items/{itemId}/move', name: 'app_plan_template_item_move', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function moveItem(Request $request, PlanTemplate $template, int $itemId, PlanScheduler $scheduler): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $item = $this->findItem($template, $itemId);
        $payload = $request->getPayload();

        if ($this->isCsrfTokenValid('item_move'.$itemId, $payload->getString('_token'))) {
            $week = $payload->getInt('week');
            $day = $payload->getInt('day');
            if ($week >= 1 && $week <= (int) $template->getDurationWeeks() && $day >= 1 && $day <= 7) {
                $item->setWeekNumber($week)->setDayOfWeek($day);
                $this->entityManager->flush();

                // Le calendrier suit : les séances prévues issues de cette case
                // migrent à la nouvelle date (DONE/MISSED conservées).
                $scheduler->rescheduleItem($item, $this->getUser());
            }
        }

        return $this->gridResponse($request, $template);
    }

    // ---- Helpers -----------------------------------------------------------

    private function findItem(PlanTemplate $template, int $itemId): PlanItem
    {
        foreach ($template->getPlanItems() as $item) {
            if ($item->getId() === $itemId) {
                return $item;
            }
        }

        throw $this->createNotFoundException('Case introuvable dans ce plan.');
    }

    /**
     * Vérifie qu'une case (semaine/jour) appartient bien à la trame déclarée.
     */
    private function assertCell(PlanTemplate $template, int $week, int $day): void
    {
        if ($week < 1 || $week > (int) $template->getDurationWeeks() || $day < 1 || $day > 7) {
            throw $this->createNotFoundException('Cette case est hors de la trame du plan.');
        }
    }

    private function createAddItemForm(PlanTemplate $template, int $week, int $day, PlanItem $item): FormInterface
    {
        return $this->formFactory->createNamed('add_item_w'.$week.'_d'.$day, PlanItemType::class, $item, [
            'workouts' => $this->ownedWorkouts(),
            'action' => $this->generateUrl('app_plan_template_item_add', [
                'id' => $template->getId(),
                'week' => $week,
                'day' => $day,
            ]),
        ]);
    }

    /**
     * Séances de l'utilisateur, chargées une seule fois par requête et
     * réutilisées par toutes les cases de la grille (une requête au lieu d'une
     * par case).
     *
     * @return list<\App\Entity\Workout>
     */
    private function ownedWorkouts(): array
    {
        return $this->ownedWorkoutsCache ??= $this->workoutRepository->findLibraryForOwner($this->getUser());
    }

    /**
     * Contexte de rendu de l'éditeur de trame : le plan aplati (grille dense
     * semaines × jours) et un formulaire d'ajout par case.
     *
     * @return array<string, mixed>
     */
    private function gridContext(PlanTemplate $template): array
    {
        $addItemForms = [];
        for ($week = 1; $week <= (int) $template->getDurationWeeks(); ++$week) {
            for ($day = 1; $day <= 7; ++$day) {
                $addItemForms['w'.$week.'d'.$day] = $this
                    ->createAddItemForm($template, $week, $day, (new PlanItem())->setWeekNumber($week)->setDayOfWeek($day))
                    ->createView();
            }
        }

        return [
            'template' => $template,
            'flat' => $this->planFlattener->flattenPlanTemplate($template),
            'addItemForms' => $addItemForms,
        ];
    }

    /** @var list<\App\Entity\Workout>|null cache pour éviter de recharger les séances par case */
    private ?array $ownedWorkoutsCache = null;

    /**
     * Répond à une mutation de la trame : Turbo Stream si accepté, sinon repli
     * par redirection vers l'édition (dégradation sans JS).
     */
    private function gridResponse(Request $request, PlanTemplate $template): Response
    {
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('plan_template/stream/grid.stream.html.twig', $this->gridContext($template));
        }

        return $this->redirectToRoute('app_plan_template_edit', ['id' => $template->getId()]);
    }
}
