<?php

namespace App\Controller;

use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\ScheduledStatus;
use App\Repository\GoalRepository;
use App\Repository\PlanTemplateRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\WorkoutRepository;
use App\Security\Voter\PlanTemplateVoter;
use App\Service\CoachedLibrary;
use App\Service\PlanFlattener;
use App\Service\PlanScheduler;
use App\Service\PlanVolumeAggregator;
use App\Service\ProgressionAggregator;
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
    /**
     * Titre d'un brouillon créé en un clic. Sert aussi de repère pour savoir si le
     * slug mérite d'être régénéré au premier vrai renommage (cf. updateMeta).
     */
    public const DRAFT_PLAN_TITLE = 'Nouveau plan';

    /** Bloc de départ d'un nouveau plan : on ajoute ensuite semaine par semaine. */
    public const DRAFT_PLAN_WEEKS = 4;

    /** Plafond de la trame (borne haute de `durationWeeks`). */
    public const MAX_WEEKS = 52;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FormFactoryInterface $formFactory,
        private readonly PlanFlattener $planFlattener,
        private readonly WorkoutRepository $workoutRepository,
        private readonly WorkoutMetrics $workoutMetrics,
        private readonly PlanVolumeAggregator $volumeAggregator,
        private readonly GoalRepository $goalRepository,
    ) {
    }

    #[Route('', name: 'app_plan_template_index', methods: ['GET'])]
    public function index(PlanTemplateRepository $planTemplateRepository, CoachedLibrary $coachedLibrary): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Même portée que l'index des séances : soi + ses athlètes suivis, pour
        // qu'un coach retrouve les plans qu'il a bâtis pour eux (ils appartiennent
        // à l'athlète). Facette « Moi » active par défaut.
        $athletes = $coachedLibrary->athletesOf($user);
        $templates = $planTemplateRepository->findForOwnersWithContent([$user, ...$athletes]);

        $items = [];
        $counts = [];
        $ownerCounts = [];
        foreach ($templates as $template) {
            // Activités distinctes de toutes les séances du plan (union), pour les facettes.
            $seen = [];
            foreach ($template->getPlanItems() as $planItem) {
                $workout = $planItem->getWorkout();
                if (null === $workout) {
                    continue;
                }
                foreach ($this->workoutMetrics->distinctActivities($workout) as $activity) {
                    $seen[$activity->value] = $activity;
                }
            }
            $activities = array_values($seen);
            $items[] = ['template' => $template, 'activities' => $activities];
            foreach ($activities as $activity) {
                $counts[$activity->value] = ($counts[$activity->value] ?? 0) + 1;
            }
            $ownerId = $template->getOwner()?->getId();
            if (null !== $ownerId) {
                $ownerCounts[$ownerId] = ($ownerCounts[$ownerId] ?? 0) + 1;
            }
        }

        $facets = [];
        foreach (ActivityType::cases() as $case) {
            if (isset($counts[$case->value])) {
                $facets[] = ['value' => $case->value, 'label' => $case->getLabel(), 'count' => $counts[$case->value]];
            }
        }

        return $this->render('plan_template/index.html.twig', [
            'items' => $items,
            'total' => \count($items),
            'activityFacets' => $facets,
            'ownerFacets' => $coachedLibrary->ownerFacets($user, $athletes, $ownerCounts),
        ]);
    }

    /**
     * Crée un brouillon titré par défaut, sur un bloc de 4 semaines, et bascule
     * directement sur l'éditeur de trame (pas d'écran de formulaire intermédiaire :
     * le titre s'édite en ligne, les semaines s'ajoutent au pied de la trame). Même
     * geste que `CoachController::newPlan`, qui procédait déjà ainsi.
     */
    #[Route('/new', name: 'app_plan_template_new', methods: ['POST'])]
    public function new(Request $request, SlugGenerator $slugGenerator): Response
    {
        if (!$this->isCsrfTokenValid('plan_new', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $title = trim($request->getPayload()->getString('title')) ?: self::DRAFT_PLAN_TITLE;

        $template = (new PlanTemplate())
            ->setOwner($this->getUser())
            ->setTitle($title)
            ->setDurationWeeks(self::DRAFT_PLAN_WEEKS)
            ->setSlug($slugGenerator->generate($title, PlanTemplate::class));

        $this->entityManager->persist($template);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_plan_template_edit', ['id' => $template->getId(), 'rename' => 1]);
    }

    /**
     * La fiche d'un plan : sa trame mise à plat, puis le bloc de progression —
     * la rampe prévue, et par-dessus elle le réalisé d'une instanciation (KL-49).
     *
     * **Une trame n'a pas de dates**, il faut donc choisir *quelle fois* on
     * regarde : la plus récente par défaut, une autre par `?run=Y-m-d` quand le
     * plan a été repassé. Un plan jamais posé au calendrier n'a pas de réalisé du
     * tout, et le bloc reste celui du prévu seul.
     *
     * Portée : le propriétaire du plan, jamais l'utilisateur courant — un coach
     * ouvre cette page pour lire le plan de son athlète, et c'est le calendrier de
     * l'athlète qui porte le réalisé.
     */
    #[Route('/{id}', name: 'app_plan_template_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        Request $request,
        PlanTemplate $template,
        SlugGenerator $slugGenerator,
        PlanTemplateRepository $planTemplateRepository,
        ScheduledWorkoutRepository $scheduledWorkoutRepository,
        ProgressionAggregator $progression,
    ): Response {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::VIEW, $template);
        $this->ensureSlug($template, $slugGenerator);

        // Précharge tout le contenu en une requête : la mise à plat ET les agrégats
        // de progression parcourent chaque case (anti-N+1). Même instance managée.
        $loaded = $planTemplateRepository->findWithContent($template->getId()) ?? $template;

        $owner = $template->getOwner();
        $anchors = null !== $owner ? $scheduledWorkoutRepository->findPlanAnchorsForOwner($template, $owner) : [];
        $anchor = $this->pickAnchor($anchors, $request->query->getString('run'));

        $realized = null !== $owner && [] !== $anchors
            ? $progression->realizedRun($loaded, $scheduledWorkoutRepository->findPlanRunWithLog($template, $owner, $anchor))
            : null;

        return $this->render('plan_template/show.html.twig', [
            'flat' => $this->planFlattener->flattenPlanTemplate($loaded),
            'progression' => [
                'volume' => $progression->weeklyVolume($loaded, $realized['weeks'] ?? []),
                'trajectories' => $progression->exerciseTrajectories($loaded, $realized['exercises'] ?? []),
                'adherence' => $realized['adherence'] ?? null,
                'anchors' => $anchors,
                'anchor' => $anchor,
            ],
        ]);
    }

    /**
     * L'instanciation regardée : celle demandée si elle existe, la plus récente
     * sinon. `$anchors` est déjà trié du plus récent au plus ancien, l'ancre nulle
     * (instanciation antérieure au champ) en queue.
     *
     * Une valeur inconnue dans l'URL retombe silencieusement sur le défaut plutôt
     * que de lever : c'est un paramètre d'affichage, pas une ressource.
     *
     * @param list<\DateTimeImmutable|null> $anchors
     */
    private function pickAnchor(array $anchors, string $requested): ?\DateTimeImmutable
    {
        if ([] === $anchors) {
            return null;
        }

        foreach ($anchors as $anchor) {
            if (null !== $anchor && $anchor->format('Y-m-d') === $requested) {
                return $anchor;
            }
        }

        return $anchors[0];
    }

    /**
     * L'éditeur de trame. Titre et description s'éditent en ligne (endpoint
     * `meta`), le nombre de semaines par les boutons du pied de trame : plus aucun
     * formulaire de métadonnées ici, donc plus de POST sur cette route.
     */
    #[Route('/{id}/edit', name: 'app_plan_template_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(PlanTemplate $template, SlugGenerator $slugGenerator): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $this->ensureSlug($template, $slugGenerator);

        return $this->render('plan_template/edit.html.twig',
            $this->gridContext($template) + $this->paletteContext($template) + $this->goalsContext($template)
        );
    }

    /**
     * Édition en ligne d'un champ du plan (titre/description/notes) depuis l'en-tête
     * cliquable de l'éditeur (contrôleur `inline-edit`). Renvoie la valeur
     * persistée (texte brut) que le JS réaffiche. C'est le SEUL chemin d'édition
     * des métadonnées : il n'y a plus de formulaire de repli.
     *
     * `notes` est le seul champ à porter une garde plus stricte que l'attribut EDIT
     * de la route : c'est un bloc-notes privé, réservé au propriétaire.
     */
    #[Route('/{id}/meta', name: 'app_plan_template_meta', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateMeta(Request $request, PlanTemplate $template, SlugGenerator $slugGenerator): Response
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
                // Un plan créé en un clic naît avec un slug dérivé du titre par
                // défaut : on le régénère au premier renommage, mais uniquement dans
                // ce cas (un plan déjà nommé garde son lien de partage public).
                if ($slugGenerator->derivesFrom($template->getSlug(), self::DRAFT_PLAN_TITLE)) {
                    $template->setSlug($slugGenerator->generate($value, PlanTemplate::class));
                }
                break;
            case 'description':
                $template->setDescription('' === $value ? null : $value);
                break;
            case 'notes':
                // Bloc-notes privé : EDIT ne suffit pas, un coach accepté le porte
                // aussi. Seul le propriétaire écrit ici, comme lui seul le lit.
                if ($template->getOwner() !== $this->getUser()) {
                    return new Response('', Response::HTTP_FORBIDDEN);
                }
                $template->setNotes('' === $value ? null : $value);
                break;
            default:
                return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();

        return new Response($value);
    }

    /**
     * Rattache/détache un objectif à ce plan (relation N:N, réversible).
     *
     * Scoping : les objectifs proposés et acceptés sont ceux du PROPRIÉTAIRE du
     * plan, pas de l'utilisateur courant — un coach qui travaille sur le contenu de
     * son athlète doit rattacher les objectifs de l'athlète. Même raisonnement que
     * le repli `PlanScheduler::resync()`.
     */
    #[Route('/{id}/goals', name: 'app_plan_template_goals', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateGoals(Request $request, PlanTemplate $template): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);
        $payload = $request->getPayload();

        if ($this->isCsrfTokenValid('plan_goals'.$template->getId(), $payload->getString('_token'))) {
            $goal = $this->goalRepository->find($payload->getInt('goalId'));

            if (null !== $goal && $goal->getOwner() === $template->getOwner()) {
                if ('detach' === $payload->getString('action')) {
                    $template->removeGoal($goal);
                } else {
                    $template->addGoal($goal);
                }
                $this->entityManager->flush();
            }
        }

        return $this->goalsResponse($request, $template);
    }

    /**
     * Réponse d'une mutation de rattachement : stream ciblé sur #plan-goals (repli
     * sans JS : redirection vers l'éditeur), calqué sur `gridResponse()`.
     */
    private function goalsResponse(Request $request, PlanTemplate $template): Response
    {
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('plan_template/stream/goals.stream.html.twig', $this->goalsContext($template));
        }

        return $this->redirectToRoute('app_plan_template_edit', ['id' => $template->getId()]);
    }

    /**
     * Contexte du bandeau d'objectifs : les objectifs liés (via la relation, donc
     * à jour dans la requête courante) et ceux, à venir, qu'on peut encore lier.
     *
     * @return array<string, mixed>
     */
    private function goalsContext(PlanTemplate $template): array
    {
        $owner = $template->getOwner();
        $linked = $template->getGoals();

        $available = null === $owner ? [] : array_values(array_filter(
            $this->goalRepository->findUpcomingForOwner($owner),
            static fn ($goal) => !$linked->contains($goal),
        ));

        return [
            'template' => $template,
            'linkedGoals' => $linked,
            'availableGoals' => $available,
        ];
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
     * Duplique un plan visible en une copie appartenant au **propriétaire du plan
     * source** (utile pour itérer sans repartir de zéro). Le template source reste
     * intact. Un coach qui duplique le plan de son athlète obtient donc une variante
     * chez cet athlète, pas un plan orphelin dans sa propre bibliothèque : sinon les
     * copies locales lui appartiendraient et l'athlète ne verrait jamais le résultat.
     */
    #[Route('/{id}/duplicate', name: 'app_plan_template_duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(Request $request, PlanTemplate $template, SlugGenerator $slugGenerator, WorkoutCloner $cloner): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::VIEW, $template);

        if (!$this->isCsrfTokenValid('duplicate'.$template->getId(), $request->getPayload()->getString('_token'))) {
            return $this->redirectToRoute('app_plan_template_edit', ['id' => $template->getId()]);
        }

        $owner = $this->ownerOf($template);

        $copy = (new PlanTemplate())
            ->setOwner($owner)
            ->setTitle($template->getTitle().' (copie)')
            ->setDescription($template->getDescription())
            ->setDurationWeeks($template->getDurationWeeks())
            ->setSlug($slugGenerator->generate($template->getTitle().' copie', PlanTemplate::class));

        // Chaque case porte sa PROPRE copie de séance (progression indépendante) :
        // on clone donc la copie locale, pas seulement le placement. Sans ça, les
        // deux plans partageraient les mêmes séances et s'éditeraient mutuellement.
        foreach ($template->getPlanItems() as $item) {
            $workoutCopy = $cloner->cloneWorkout($item->getWorkout(), $owner, $item->getWorkout()->getTitle(), true);
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
     * Re-rend la trame seule, sans muter quoi que ce soit. Sert au rafraîchissement
     * de la grille après une édition rapide (mini-modale) : la durée estimée et les
     * volumes de semaine dérivent du contenu des séances, mais leur enregistrement
     * passe par WorkoutController, qui ne connaît pas le plan. Plutôt que recharger
     * la page (et perdre la position de défilement), le contrôleur `plangrid`
     * redemande ce stream à la fermeture de la modale.
     *
     * Sans JS, cette route n'est jamais appelée : le repli reste la redirection
     * vers l'éditeur (gridResponse).
     */
    #[Route('/{id}/grid', name: 'app_plan_template_grid', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function grid(Request $request, PlanTemplate $template): Response
    {
        $this->denyAccessUnlessGranted(PlanTemplateVoter::EDIT, $template);

        return $this->gridResponse($request, $template);
    }

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

            // Case valide + séance possédée par le PROPRIÉTAIRE DU PLAN et de
            // bibliothèque (jamais une copie locale d'un autre plan). Scoper sur
            // l'utilisateur courant interdirait à un coach de poser une séance de
            // son athlète — c'est-à-dire tout ce que la palette lui propose.
            if ($week >= 1 && $week <= (int) $template->getDurationWeeks() && $day >= 1 && $day <= 7
                && null !== $source && $source->getOwner()?->getId() === $this->ownerOf($template)->getId() && !$source->isPlanLocal()) {
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

        if ($this->isCsrfTokenValid('week_add'.$template->getId(), $request->getPayload()->getString('_token'))) {
            // `count` absent (bouton « + 1 semaine ») = une seule semaine. On borne
            // à ce qui reste avant le plafond plutôt que de refuser tout le paquet.
            $current = (int) $template->getDurationWeeks();
            $requested = max(1, $request->getPayload()->getInt('count', 1));
            $added = min($requested, self::MAX_WEEKS - $current);

            if ($added > 0) {
                $template->setDurationWeeks($current + $added);
                $this->entityManager->flush();
            }
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
                $scheduler->rescheduleItem($item, $this->ownerOf($template));
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
                $copy = $cloner->cloneWorkout($item->getWorkout(), $this->ownerOf($template), $item->getWorkout()->getTitle(), true);
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
                $scheduler->rescheduleItem($item, $this->ownerOf($template));
            }
        }

        return $this->gridResponse($request, $template);
    }

    // ---- Helpers -----------------------------------------------------------

    /**
     * Propriétaire du plan — la seule référence valable pour tout ce que cet
     * éditeur lit ou crée (bibliothèque proposée, copies locales, calendrier ciblé).
     * L'utilisateur courant n'est pas forcément le propriétaire : un coach accepté
     * édite la trame de son athlète, et le contenu doit rester à l'athlète
     * (cf. CLAUDE.md §3). Repli sur l'utilisateur courant pour un plan sans owner
     * (données anciennes) — le cas normal ne s'y appuie pas.
     */
    private function ownerOf(PlanTemplate $template): User
    {
        /** @var User $current */
        $current = $this->getUser();

        return $template->getOwner() ?? $current;
    }

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
     * La copie appartient au propriétaire du plan (le contenu appartient toujours
     * à l'athlète) : c'est elle que référencera la séance datée au calendrier.
     * Persiste l'item ; le flush et le resync calendrier restent à l'appelant
     * (pour grouper les poses multiples si besoin).
     */
    private function placeWorkoutInCell(PlanTemplate $template, Workout $source, int $week, int $day, ?string $notes, WorkoutCloner $cloner): PlanItem
    {
        $copy = $cloner->cloneWorkout($source, $this->ownerOf($template), $source->getTitle(), true);
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
     * Portée : la bibliothèque du **propriétaire du plan**, pas celle de
     * l'utilisateur courant. Un coach qui compose la trame d'un athlète doit y
     * retrouver les séances de cet athlète — dont celles qu'il a lui-même créées
     * pour lui, qui appartiennent à l'athlète (cf. CLAUDE.md §3).
     *
     * @return array<string, mixed>
     */
    private function paletteContext(PlanTemplate $template): array
    {
        $workouts = $this->workoutRepository->findLibraryForOwnerWithContent($this->ownerOf($template));

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
