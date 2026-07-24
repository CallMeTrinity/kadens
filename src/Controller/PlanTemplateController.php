<?php

namespace App\Controller;

use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\ScheduledStatus;
use App\Form\PlanTemplateType;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\WorkoutRepository;
use App\Security\Voter\PlanTemplateVoter;
use App\Service\PlanFlattener;
use App\Service\PlanScheduler;
use App\Service\PlanVolumeAggregator;
use App\Service\SlugGenerator;
use App\Service\WorkoutCloner;
use App\Service\WorkoutMetrics;
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
        private readonly WorkoutMetrics $workoutMetrics,
        private readonly PlanVolumeAggregator $volumeAggregator,
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
    public function show(PlanTemplate $template, SlugGenerator $slugGenerator): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::VIEW, $template);
        $this->ensureSlug($template, $slugGenerator);

        return $this->render('plan_template/show.html.twig', [
            'flat' => $this->planFlattener->flattenPlanTemplate($template),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_plan_template_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, PlanTemplate $template, SlugGenerator $slugGenerator): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $this->ensureSlug($template, $slugGenerator);

        $form = $this->createForm(PlanTemplateType::class, $template);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Plan mis à jour.');

            return $this->redirectToRoute('app_plan_template_edit', ['id' => $template->getId()]);
        }

        return $this->render('plan_template/edit.html.twig', [
            'form' => $form,
        ] + $this->gridContext($template) + $this->paletteContext());
    }

    /**
     * Édition en ligne d'un champ du plan (titre/description) depuis l'en-tête
     * cliquable de l'éditeur (contrôleur `inline-edit`). Renvoie la valeur
     * persistée (texte brut) que le JS réaffiche ; repli sans JS = le formulaire
     * complet replié dans l'éditeur.
     */
    #[Route('/{id}/meta', name: 'app_plan_template_meta', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateMeta(Request $request, PlanTemplate $template): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $payload = $request->getPayload();

        if (!$this->isCsrfTokenValid('plan_meta'.$template->getId(), $payload->getString('_token'))) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $value = trim($payload->getString('value'));
        switch ($payload->getString('field')) {
            case 'title':
                if ('' === $value) {
                    return new Response('Le titre ne peut pas être vide.', Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $template->setTitle($value);
                break;
            case 'description':
                $template->setDescription('' === $value ? null : $value);
                break;
            default:
                return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();

        return new Response($value);
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

    /**
     * Pose une séance dans une case (palette : mode tampon / glisser-déposer).
     * Corps : workoutId + week + day. Clone la séance choisie (fork à la pose) et
     * la pose dans la case, puis resync si le plan est déjà au calendrier.
     */
    #[Route('/{id}/place', name: 'app_plan_template_item_place', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function placeItem(Request $request, PlanTemplate $template, WorkoutCloner $cloner, PlanScheduler $scheduler): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $payload = $request->getPayload();

        if ($this->isCsrfTokenValid('plan_place'.$template->getId(), $payload->getString('_token'))) {
            $week = $payload->getInt('week');
            $day = $payload->getInt('day');
            $source = $this->workoutRepository->find($payload->getInt('workoutId'));

            // Case valide + séance possédée par l'utilisateur et de bibliothèque
            // (jamais une copie locale d'un autre plan).
            if ($week >= 1 && $week <= (int) $template->getDurationWeeks() && $day >= 1 && $day <= 7
                && null !== $source && $source->getOwner()?->getId() === $this->getUser()->getId() && !$source->isPlanLocal()) {
                $this->placeWorkoutInCell($template, $source, $week, $day, null, $cloner);
                $this->entityManager->flush();
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
            $orphan = $this->detachItem($template, $item, $scheduledRepository);
            $this->entityManager->flush();

            // Copie orpheline nettoyée APRÈS le flush (pour que le retrait des séances
            // datées prenne effet avant la cascade workout -> scheduled).
            if (null !== $orphan) {
                $this->entityManager->remove($orphan);
                $this->entityManager->flush();
            }
        }

        return $this->gridResponse($request, $template);
    }

    /**
     * Édition en ligne de la note d'une case (contrôleur `inline-edit`). Renvoie
     * la note persistée (texte brut) ; pas de re-render de grille nécessaire (la
     * note s'affiche là où on la modifie).
     */
    #[Route('/{id}/items/{itemId}/note', name: 'app_plan_template_item_note', methods: ['POST'], requirements: ['id' => '\d+', 'itemId' => '\d+'])]
    public function updateItemNote(Request $request, PlanTemplate $template, int $itemId): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $item = $this->findItem($template, $itemId);
        $payload = $request->getPayload();

        if (!$this->isCsrfTokenValid('item_note'.$itemId, $payload->getString('_token'))) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $value = trim($payload->getString('value'));
        $item->setNotes('' === $value ? null : $value);
        $this->entityManager->flush();

        return new Response($value);
    }

    /**
     * Ajoute une semaine vide en fin de trame (durationWeeks++). Pas de case créée,
     * donc rien à resynchroniser au calendrier.
     */
    #[Route('/{id}/weeks/add', name: 'app_plan_template_week_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addWeek(Request $request, PlanTemplate $template): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);

        if ($this->isCsrfTokenValid('week_add'.$template->getId(), $request->getPayload()->getString('_token'))
            && (int) $template->getDurationWeeks() < 52) {
            $template->setDurationWeeks((int) $template->getDurationWeeks() + 1);
            $this->entityManager->flush();
        }

        return $this->gridResponse($request, $template);
    }

    /**
     * Retire une semaine : détache ses cases (préserve le réalisé, cf. detachItem),
     * décale les semaines suivantes d'un cran et réaligne au calendrier les séances
     * encore prévues des cases décalées. Refuse de descendre sous 1 semaine.
     */
    #[Route('/{id}/weeks/{week}/remove', name: 'app_plan_template_week_remove', methods: ['POST'], requirements: ['id' => '\d+', 'week' => '\d+'])]
    public function removeWeek(Request $request, PlanTemplate $template, int $week, ScheduledWorkoutRepository $scheduledRepository, PlanScheduler $scheduler): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);

        if ($this->isCsrfTokenValid('week_remove'.$week, $request->getPayload()->getString('_token'))
            && $week >= 1 && $week <= (int) $template->getDurationWeeks() && (int) $template->getDurationWeeks() > 1) {
            // 1) Détacher les cases de la semaine retirée (snapshot d'abord : on
            //    modifie la collection).
            $toDetach = [];
            foreach ($template->getPlanItems() as $item) {
                if ($item->getWeekNumber() === $week) {
                    $toDetach[] = $item;
                }
            }
            $orphans = [];
            foreach ($toDetach as $item) {
                $orphan = $this->detachItem($template, $item, $scheduledRepository);
                if (null !== $orphan) {
                    $orphans[] = $orphan;
                }
            }

            // 2) Décaler les semaines suivantes d'un cran.
            $shifted = [];
            foreach ($template->getPlanItems() as $item) {
                if ($item->getWeekNumber() > $week) {
                    $item->setWeekNumber($item->getWeekNumber() - 1);
                    $shifted[] = $item;
                }
            }

            $template->setDurationWeeks((int) $template->getDurationWeeks() - 1);
            $this->entityManager->flush();

            if ([] !== $orphans) {
                foreach ($orphans as $copy) {
                    $this->entityManager->remove($copy);
                }
                $this->entityManager->flush();
            }

            // 3) Le calendrier suit : les séances prévues des cases décalées migrent.
            foreach ($shifted as $item) {
                $scheduler->rescheduleItem($item, $this->getUser());
            }
        }

        return $this->gridResponse($request, $template);
    }

    /**
     * Copie le contenu d'une semaine vers une autre (cible libre). Chaque séance est
     * clonée en copie locale indépendante ; le contenu de la semaine cible est
     * d'abord REMPLACÉ (ses cases détachées, réalisé préservé). Support de la
     * construction incrémentale (« ma S1 est bonne, je la reporte en S3 »).
     */
    #[Route('/{id}/weeks/{week}/copy', name: 'app_plan_template_week_copy', methods: ['POST'], requirements: ['id' => '\d+', 'week' => '\d+'])]
    public function copyWeek(Request $request, PlanTemplate $template, int $week, WorkoutCloner $cloner, ScheduledWorkoutRepository $scheduledRepository, PlanScheduler $scheduler): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $payload = $request->getPayload();
        $target = $payload->getInt('target');

        if ($this->isCsrfTokenValid('week_copy'.$week, $payload->getString('_token'))
            && $week >= 1 && $week <= (int) $template->getDurationWeeks()
            && $target >= 1 && $target <= (int) $template->getDurationWeeks() && $target !== $week) {
            // Snapshots AVANT toute modification de la collection.
            $sources = [];
            $targetItems = [];
            foreach ($template->getPlanItems() as $item) {
                if ($item->getWeekNumber() === $week) {
                    $sources[] = $item;
                } elseif ($item->getWeekNumber() === $target) {
                    $targetItems[] = $item;
                }
            }

            // 1) Vider la semaine cible (remplacement, réalisé préservé).
            $orphans = [];
            foreach ($targetItems as $item) {
                $orphan = $this->detachItem($template, $item, $scheduledRepository);
                if (null !== $orphan) {
                    $orphans[] = $orphan;
                }
            }

            // 2) Cloner les cases de la source vers la cible.
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

            if ([] !== $orphans) {
                foreach ($orphans as $copy) {
                    $this->entityManager->remove($copy);
                }
                $this->entityManager->flush();
            }

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

    /**
     * Garantit un slug (partage public). Les plans créés/dupliqués en ont déjà un ;
     * ce repli couvre d'éventuelles données anciennes au slug null.
     */
    private function ensureSlug(PlanTemplate $template, SlugGenerator $slugGenerator): void
    {
        if (null === $template->getSlug() || '' === $template->getSlug()) {
            $template->setSlug($slugGenerator->generate((string) $template->getTitle(), PlanTemplate::class));
            $this->entityManager->flush();
        }
    }

    /**
     * Fork à la pose : la case reçoit sa PROPRE copie (planLocal) de la séance
     * source, éditable et progressable sans toucher la biblio ni les autres cases.
     * Persiste l'item ; le flush et le resync calendrier restent à l'appelant
     * (pour grouper les poses multiples si besoin).
     */
    private function placeWorkoutInCell(PlanTemplate $template, Workout $source, int $week, int $day, ?string $notes, WorkoutCloner $cloner): PlanItem
    {
        $copy = $cloner->cloneWorkout($source, $this->getUser(), $source->getTitle(), true);
        $item = (new PlanItem())
            ->setWeekNumber($week)
            ->setDayOfWeek($day)
            ->setNotes($notes)
            ->setWorkout($copy);
        $template->addPlanItem($item);
        $this->entityManager->persist($item);

        return $item;
    }

    /**
     * Détache une case de la trame en PRÉSERVANT le réalisé : retire ses séances
     * datées `PLANNED`, conserve `DONE`/`MISSED` (leur date matérialise le réalisé,
     * leur lien vers la case passera à NULL — SET NULL), retire la case du template.
     * Renvoie la copie locale à nettoyer si elle devient orpheline (aucune séance
     * conservée ne la référence), sinon null. Le flush et la suppression de la copie
     * restent à l'appelant (pour batcher : la suppression de la copie doit suivre le
     * flush du retrait des séances datées, cf. cascade workout -> scheduled).
     */
    private function detachItem(PlanTemplate $template, PlanItem $item, ScheduledWorkoutRepository $scheduledRepository): ?Workout
    {
        $copy = $item->getWorkout();

        $kept = 0;
        foreach ($scheduledRepository->findBySourcePlanItem($item) as $scheduled) {
            if (ScheduledStatus::PLANNED === $scheduled->getStatus()) {
                $this->entityManager->remove($scheduled);
            } else {
                ++$kept;
            }
        }

        $template->removePlanItem($item);

        return (null !== $copy && $copy->isPlanLocal() && 0 === $kept) ? $copy : null;
    }

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

    /**
     * Contexte de rendu de l'éditeur de trame : le plan aplati (grille dense
     * semaines × jours) et le volume par semaine.
     *
     * @return array<string, mixed>
     */
    private function gridContext(PlanTemplate $template): array
    {
        return [
            'template' => $template,
            'flat' => $this->planFlattener->flattenPlanTemplate($template),
            'weekVolumes' => $this->volumeAggregator->byWeek($template),
        ];
    }

    /**
     * Contexte de la palette de séances (volet gauche de l'éditeur) : les séances
     * de bibliothèque avec leurs repères de carte (activités distinctes, nombre
     * d'exos, texte de recherche) et les filtres d'activité présents. Chargée une
     * fois au rendu de la page (hors des flux de grille), avec le contenu
     * fetch-joint pour éviter tout N+1.
     *
     * @return array<string, mixed>
     */
    private function paletteContext(): array
    {
        $workouts = $this->workoutRepository->findLibraryForOwnerWithContent($this->getUser());

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

        // Filtres d'activité présents, dans l'ordre canonique de l'enum. Un même
        // workout compte pour chaque activité qu'il contient.
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

        return [
            'paletteCards' => $cards,
            'paletteCount' => \count($workouts),
            'paletteActivities' => $activityFilters,
        ];
    }

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
