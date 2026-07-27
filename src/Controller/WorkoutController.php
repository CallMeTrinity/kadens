<?php

namespace App\Controller;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PrescribedExercise;
use App\Entity\PrescribedSet;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Form\BlockType;
use App\Form\PrescribedExerciseType;
use App\Form\PrescribedSetType;
use App\Repository\ExerciseRepository;
use App\Repository\WorkoutRepository;
use App\Security\Voter\WorkoutVoter;
use App\Service\PlanFlattener;
use App\Service\SetSynchronizer;
use App\Service\SlugGenerator;
use App\Service\WorkoutCloner;
use App\Service\WorkoutEstimator;
use App\Service\WorkoutMetrics;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

#[Route('/workout')]
final class WorkoutController extends AbstractController
{
    /**
     * Champs de valeurs qui existent sur PrescribedExercise, pour le nettoyage
     * serveur (on annule tout champ hors sous-ensemble du type choisi).
     */
    private const VALUE_FIELDS = [
        'sets', 'reps', 'weightKg', 'durationSeconds', 'distanceMeters',
        'paceSecondsPerKm', 'targetReps', 'capSeconds', 'intensityZone',
        'elevationGainMeters',
    ];

    /**
     * Titre d'un brouillon créé en un clic. Sert aussi de repère pour savoir si le
     * slug mérite d'être régénéré au premier vrai renommage (cf. updateMeta).
     */
    public const DRAFT_WORKOUT_TITLE = 'Nouvelle séance';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FormFactoryInterface $formFactory,
        private readonly PlanFlattener $planFlattener,
        private readonly ExerciseRepository $exerciseRepository,
        private readonly WorkoutEstimator $estimator,
        private readonly SetSynchronizer $setSynchronizer,
    ) {
    }

    #[Route('', name: 'app_workout_index', methods: ['GET'])]
    public function index(WorkoutRepository $workoutRepository, WorkoutMetrics $workoutMetrics): Response
    {
        // Fetch-join du contenu : les activités distinctes par séance servent aux
        // facettes/filtres de l'index (sans ce join ce serait un N+1).
        $workouts = $workoutRepository->findLibraryForOwnerWithContent($this->getUser());

        $items = [];
        $counts = [];
        foreach ($workouts as $workout) {
            $activities = $workoutMetrics->distinctActivities($workout);
            $items[] = ['workout' => $workout, 'activities' => $activities];
            foreach ($activities as $activity) {
                $counts[$activity->value] = ($counts[$activity->value] ?? 0) + 1;
            }
        }

        return $this->render('workout/index.html.twig', [
            'items' => $items,
            'total' => \count($items),
            'activityFacets' => $this->buildActivityFacets($counts),
        ]);
    }

    /**
     * Puces d'activité présentes, ordonnées selon l'enum, avec leur effectif.
     *
     * @param array<string, int> $counts
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    private function buildActivityFacets(array $counts): array
    {
        $facets = [];
        foreach (ActivityType::cases() as $case) {
            if (isset($counts[$case->value])) {
                $facets[] = ['value' => $case->value, 'label' => $case->getLabel(), 'count' => $counts[$case->value]];
            }
        }

        return $facets;
    }

    /**
     * Crée un brouillon titré par défaut et bascule directement sur le compositeur
     * (pas d'écran de formulaire intermédiaire : le titre s'édite en ligne, et
     * l'éditeur l'ouvre tout de suite grâce au paramètre `rename`). Même geste que
     * `CoachController::newWorkout`, qui procédait déjà ainsi.
     */
    #[Route('/new', name: 'app_workout_new', methods: ['POST'])]
    public function new(Request $request, SlugGenerator $slugGenerator): Response
    {
        if (!$this->isCsrfTokenValid('workout_new', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $title = trim($request->getPayload()->getString('title')) ?: self::DRAFT_WORKOUT_TITLE;

        $workout = (new Workout())
            ->setOwner($this->getUser())
            ->setTitle($title)
            ->setSlug($slugGenerator->generate($title, Workout::class));

        $this->entityManager->persist($workout);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_workout_edit', ['id' => $workout->getId(), 'rename' => 1]);
    }

    /**
     * Consultation d'une séance. En plus de la mise à plat, la page consomme la
     * synthèse et la ventilation par bloc de WorkoutMetrics : la vue ne calcule
     * rien, elle affiche. Voir components/_workout_read.html.twig.
     */
    #[Route('/{id}', name: 'app_workout_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Workout $workout, PlanFlattener $planFlattener, WorkoutMetrics $metrics): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::VIEW, $workout);

        return $this->render('workout/show.html.twig', [
            'flat' => $planFlattener->flattenWorkout($workout),
            'summary' => $metrics->summary($workout),
            'blockStats' => $metrics->blockBreakdown($workout),
        ]);
    }

    /**
     * Le compositeur. Titre et description s'éditent en ligne (endpoint `meta`),
     * le contenu par les endpoints de blocs/exercices : plus aucun formulaire de
     * métadonnées ici, donc plus de POST sur cette route.
     */
    #[Route('/{id}/edit', name: 'app_workout_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(Workout $workout): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);

        return $this->render('workout/edit.html.twig', [
            'workout' => $workout,
        ] + $this->blocksContext($workout) + $this->libraryContext());
    }

    /**
     * Édition en ligne d'un champ de la séance (titre/description) depuis l'en-tête
     * cliquable du compositeur (contrôleur `inline-edit`, même pattern que le plan).
     * Renvoie la valeur persistée (texte brut) que le JS réaffiche. C'est le SEUL
     * chemin d'édition des métadonnées : il n'y a plus de formulaire de repli.
     */
    #[Route('/{id}/meta', name: 'app_workout_meta', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateMeta(Request $request, Workout $workout, SlugGenerator $slugGenerator): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);
        $payload = $request->getPayload();

        if (!$this->isCsrfTokenValid('workout_meta'.$workout->getId(), $payload->getString('_token'))) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $value = trim($payload->getString('value'));
        switch ($payload->getString('field')) {
            case 'title':
                if ('' === $value) {
                    return new Response('Le titre ne peut pas être vide.', Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $workout->setTitle($value);
                $this->refreshDraftSlug($workout, $value, $slugGenerator);
                break;
            case 'description':
                $workout->setDescription('' === $value ? null : $value);
                break;
            default:
                return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();

        return new Response($value);
    }

    /**
     * Une séance créée en un clic naît avec un slug dérivé du titre par défaut
     * (`nouvelle-seance-7`). On le régénère au premier renommage, mais UNIQUEMENT
     * dans ce cas : une séance déjà nommée garde son slug, sinon chaque renommage
     * casserait son lien de partage public.
     */
    private function refreshDraftSlug(Workout $workout, string $title, SlugGenerator $slugGenerator): void
    {
        if ($slugGenerator->derivesFrom($workout->getSlug(), self::DRAFT_WORKOUT_TITLE)) {
            $workout->setSlug($slugGenerator->generate($title, Workout::class));
        }
    }

    #[Route('/{id}/delete', name: 'app_workout_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Workout $workout): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::DELETE, $workout);

        if ($this->isCsrfTokenValid('delete'.$workout->getId(), $request->getPayload()->getString('_token'))) {
            $this->entityManager->remove($workout);
            $this->entityManager->flush();

            $this->addFlash('success', 'Séance supprimée.');
        }

        return $this->redirectToRoute('app_workout_index');
    }

    #[Route('/{id}/duplicate', name: 'app_workout_duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(Request $request, Workout $workout, WorkoutCloner $cloner): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::VIEW, $workout);

        if (!$this->isCsrfTokenValid('duplicate'.$workout->getId(), $request->getPayload()->getString('_token'))) {
            return $this->redirectToRoute('app_workout_show', ['id' => $workout->getId()]);
        }

        // Copie de bibliothèque (planLocal = false) : réutilisable et listée.
        $copy = $cloner->cloneWorkout($workout, $this->getUser(), $workout->getTitle().' (copie)', false);
        $this->entityManager->flush();

        $this->addFlash('success', 'Séance dupliquée. Compose-la maintenant.');

        return $this->redirectToRoute('app_workout_edit', ['id' => $copy->getId()]);
    }

    // ---- Édition des blocs -------------------------------------------------

    #[Route('/{id}/blocks', name: 'app_workout_block_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addBlock(Request $request, Workout $workout): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);

        $block = (new Block())->setRole(BlockRole::MAIN);
        $form = $this->createAddBlockForm($workout, $block);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $block->setPosition($this->nextPosition($workout->getBlocks()->toArray()));
            // addBlock maintient les DEUX côtés de la relation. Sans ça, la
            // collection en mémoire reste inchangée et le stream re-rendu dans la
            // foulée ne montre pas le nouveau bloc (visible seulement au rechargement).
            $workout->addBlock($block);
            $this->entityManager->persist($block);
            $this->entityManager->flush();
        }

        return $this->blocksResponse($request, $workout);
    }

    #[Route('/{id}/blocks/{blockId}', name: 'app_workout_block_edit', methods: ['POST'], requirements: ['id' => '\d+', 'blockId' => '\d+'])]
    public function editBlock(Request $request, Workout $workout, int $blockId): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);
        $block = $this->findBlock($workout, $blockId);

        $form = $this->createBlockForm($block);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
        }

        return $this->blocksResponse($request, $workout);
    }

    #[Route('/{id}/blocks/{blockId}/delete', name: 'app_workout_block_delete', methods: ['POST'], requirements: ['id' => '\d+', 'blockId' => '\d+'])]
    public function deleteBlock(Request $request, Workout $workout, int $blockId): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);
        $block = $this->findBlock($workout, $blockId);

        if ($this->isCsrfTokenValid('block_delete'.$blockId, $request->getPayload()->getString('_token'))) {
            $workout->removeBlock($block);
            $this->entityManager->flush();
        }

        return $this->blocksResponse($request, $workout);
    }

    #[Route('/{id}/blocks/{blockId}/move/{direction}', name: 'app_workout_block_move', methods: ['POST'], requirements: ['id' => '\d+', 'blockId' => '\d+', 'direction' => 'up|down'])]
    public function moveBlock(Request $request, Workout $workout, int $blockId, string $direction): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);
        $block = $this->findBlock($workout, $blockId);

        if ($this->isCsrfTokenValid('block_move'.$blockId, $request->getPayload()->getString('_token'))) {
            $this->swapPosition($workout->getBlocks()->toArray(), $block, $direction);
            $this->entityManager->flush();
        }

        return $this->blocksResponse($request, $workout);
    }

    // ---- Édition des exercices prescrits -----------------------------------

    #[Route('/{id}/exercises/quick-add', name: 'app_workout_prescribed_quick_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function quickAddPrescribed(Request $request, Workout $workout): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);
        $payload = $request->getPayload();

        if ($this->isCsrfTokenValid('prescribed_quick_add'.$workout->getId(), $payload->getString('_token'))) {
            $block = $this->findBlock($workout, $payload->getInt('blockId'));
            $exercise = $this->findLibraryExercise($payload->getInt('exerciseId'));

            if (null !== $exercise) {
                // Ajout express : type par défaut déduit de l'activité (distance ×
                // allure pour course/vélo/natation, séries × répétitions sinon), à
                // affiner ensuite via le panneau de paramètres. Aucune valeur n'est posée.
                $prescribed = (new PrescribedExercise())
                    ->setExercise($exercise)
                    ->setPrescriptionType($this->defaultPrescriptionType($exercise))
                    ->setPosition($this->nextPosition($block->getPrescribedExercises()->toArray()));
                // addPrescribedExercise maintient les DEUX côtés de la relation :
                // sans ça, la collection en mémoire reste vide et le stream re-rendu
                // ne montre pas l'ajout (visible seulement après rechargement).
                $block->addPrescribedExercise($prescribed);
                $this->entityManager->persist($prescribed);
                $this->entityManager->flush();

                // Placement précis si le glisser-déposer fournit un point de dépôt
                // (afterId = 0 -> tête du bloc, sinon juste après cet exercice).
                // Champ absent/vide (bouton +) -> l'exercice reste en fin de bloc.
                $afterRaw = $payload->get('afterId');
                if (null !== $afterRaw && '' !== $afterRaw) {
                    $this->repositionPrescribed($prescribed, $block, (int) $afterRaw);
                    $this->entityManager->flush();
                }
            }
        }

        return $this->blocksResponse($request, $workout);
    }

    #[Route('/{id}/exercises/reorder', name: 'app_workout_prescribed_reorder', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reorderPrescribed(Request $request, Workout $workout): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);
        $payload = $request->getPayload();

        if ($this->isCsrfTokenValid('prescribed_reorder'.$workout->getId(), $payload->getString('_token'))) {
            $prescribed = $this->findPrescribed($workout, $payload->getInt('prescribedId'));
            $targetBlock = $this->findBlock($workout, $payload->getInt('targetBlockId'));
            // afterId = 0 -> place en tête du bloc cible ; sinon juste après cet exercice.
            $this->repositionPrescribed($prescribed, $targetBlock, $payload->getInt('afterId'));
            $this->entityManager->flush();
        }

        return $this->blocksResponse($request, $workout);
    }

    #[Route('/{id}/exercises/{prescribedId}', name: 'app_workout_prescribed_edit', methods: ['POST'], requirements: ['id' => '\d+', 'prescribedId' => '\d+'])]
    public function editPrescribed(Request $request, Workout $workout, int $prescribedId): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);
        $prescribed = $this->findPrescribed($workout, $prescribedId);
        $detailedBefore = $prescribed->hasDetailedSets();
        $countBefore = $prescribed->getWorkingSetCount();

        $form = $this->createPrescribedForm($prescribed);
        $form->handleRequest($request);

        $listChanged = false;
        if ($form->isSubmitted() && $form->isValid()) {
            // En mode détaillé, le compteur `sets` pilote la liste : le monter ou le
            // descendre ajoute/retire des séries de travail en fin (l'échauffement
            // n'est jamais touché). C'est l'autre sens de la synchro.
            if ($detailedBefore && $prescribed->getSets() !== $countBefore) {
                foreach ($this->setSynchronizer->applyScalarToDetailed($prescribed, (int) $prescribed->getSets()) as $created) {
                    $this->entityManager->persist($created);
                }
                $this->setSynchronizer->syncScalarFromDetailed($prescribed);
                $listChanged = true;
            }

            $this->clearIrrelevantFields($prescribed);
            // La durée estimée dérive du contenu : on la recalcule à chaque save.
            $workout->setEstimatedDurationMinutes($this->estimator->estimateMinutes($workout));
            $this->entityManager->flush();
        }

        // La liste de séries a changé : le panneau entier doit être re-rendu, sinon
        // les lignes ajoutées/retirées n'apparaissent qu'au rechargement.
        if ($listChanged) {
            return $this->setsResponse($request, $workout, $prescribed);
        }

        // Enregistrement silencieux : on ne re-rend QUE la ligne de résumé de cet
        // exercice (pastille + type), pas tout #workout-blocks. Le panneau de
        // paramètres (le formulaire en cours de saisie) reste intact et ouvert,
        // le focus n'est pas perturbé. Sans JS : repli par redirection.
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('workout/stream/prescribed_row.stream.html.twig', [
                'workout' => $workout,
                'prescribed' => $prescribed,
                'summary' => $this->prescribedSummaries($workout)[$prescribed->getId()] ?? '',
            ]);
        }

        return $this->redirectToRoute('app_workout_edit', ['id' => $workout->getId()]);
    }

    // ---- Séries détaillées (mode force : SETS_REPS / SETS_TIME) -------------

    /**
     * Passe un exercice en « séries détaillées » (première fois : éclate le compteur
     * scalaire en N lignes explicites) ou ajoute une série de travail (valeurs
     * reprises de la dernière). Chaque série peut ensuite porter son propre type
     * (échauffement, dégressive, drop set…) et ses valeurs — ce que le compteur
     * `sets`/`reps` ne permet pas.
     *
     * Le compteur scalaire suit le décompte des séries de travail (SetSynchronizer) :
     * les deux modes affichent toujours le même nombre.
     */
    #[Route('/{id}/exercises/{prescribedId}/sets', name: 'app_workout_set_add', methods: ['POST'], requirements: ['id' => '\d+', 'prescribedId' => '\d+'])]
    public function addSet(Request $request, Workout $workout, int $prescribedId): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);
        $prescribed = $this->findPrescribed($workout, $prescribedId);

        if ($this->isCsrfTokenValid('set_add'.$prescribedId, $request->getPayload()->getString('_token'))) {
            if (!$prescribed->hasDetailedSets()) {
                // Éclatement du mode scalaire : autant de lignes que le compteur,
                // chacune reprenant reps/durée/charge existants (point de départ à ajuster).
                $count = max(1, $prescribed->getSets() ?? 1);
                for ($i = 0; $i < $count; ++$i) {
                    $prescribed->addDetailedSet($this->newSetFrom($prescribed, $i));
                }
            } else {
                // Ajout d'une série de TRAVAIL, valeurs reprises de la dernière.
                // Toujours NORMAL : ajouter une série, c'est ajouter du travail, et
                // le compteur scalaire doit monter d'autant.
                $this->setSynchronizer->applyScalarToDetailed(
                    $prescribed,
                    $prescribed->getWorkingSetCount() + 1,
                );
            }
            $this->setSynchronizer->syncScalarFromDetailed($prescribed);
            foreach ($prescribed->getDetailedSets() as $set) {
                $this->entityManager->persist($set);
            }
        }

        return $this->setsResponse($request, $workout, $prescribed);
    }

    /**
     * Revient au mode simple : retire toutes les séries détaillées. Le compteur
     * scalaire reprend la main — et il est juste, puisqu'il a suivi chaque mutation
     * de la collection (SetSynchronizer). Geste réversible : on repart du nombre de
     * séries de travail réellement décrit, pas de celui d'avant le détail.
     */
    #[Route('/{id}/exercises/{prescribedId}/sets/clear', name: 'app_workout_set_clear', methods: ['POST'], requirements: ['id' => '\d+', 'prescribedId' => '\d+'])]
    public function clearSets(Request $request, Workout $workout, int $prescribedId): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);
        $prescribed = $this->findPrescribed($workout, $prescribedId);

        if ($this->isCsrfTokenValid('set_clear'.$prescribedId, $request->getPayload()->getString('_token'))) {
            foreach ($prescribed->getDetailedSets()->toArray() as $set) {
                $prescribed->removeDetailedSet($set);
            }
        }

        return $this->setsResponse($request, $workout, $prescribed);
    }

    /**
     * Enregistre une série (type + valeurs). En général on ne re-rend QUE la ligne
     * de résumé (pastille) : le champ en cours de saisie n'est pas re-rendu, le
     * focus reste. Exception : si le décompte de travail a bougé (une ligne est
     * passée en échauffement, ou en est sortie), le compteur `sets` affiché dans le
     * panneau devient faux — on re-rend alors tout le panneau. Ça ne concerne que
     * le `<select>` de type, jamais la saisie de reps/charge.
     * Repli sans JS : redirection.
     */
    #[Route('/{id}/sets/{setId}', name: 'app_workout_set_edit', methods: ['POST'], requirements: ['id' => '\d+', 'setId' => '\d+'])]
    public function editSet(Request $request, Workout $workout, int $setId): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);
        $set = $this->findSet($workout, $setId);
        $prescribed = $set->getPrescribedExercise();
        $countBefore = $prescribed->getWorkingSetCount();

        $form = $this->createSetForm($set);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->setSynchronizer->syncScalarFromDetailed($prescribed);
            $workout->setEstimatedDurationMinutes($this->estimator->estimateMinutes($workout));
            $this->entityManager->flush();
        }

        if ($prescribed->getWorkingSetCount() !== $countBefore) {
            return $this->setsResponse($request, $workout, $prescribed);
        }

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('workout/stream/prescribed_row.stream.html.twig', [
                'workout' => $workout,
                'prescribed' => $prescribed,
                'summary' => $this->prescribedSummaries($workout)[$prescribed->getId()] ?? '',
            ]);
        }

        return $this->redirectToRoute('app_workout_edit', ['id' => $workout->getId()]);
    }

    #[Route('/{id}/sets/{setId}/delete', name: 'app_workout_set_delete', methods: ['POST'], requirements: ['id' => '\d+', 'setId' => '\d+'])]
    public function deleteSet(Request $request, Workout $workout, int $setId): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);
        $set = $this->findSet($workout, $setId);
        $prescribed = $set->getPrescribedExercise();

        if ($this->isCsrfTokenValid('set_delete'.$setId, $request->getPayload()->getString('_token'))) {
            $prescribed->removeDetailedSet($set);
            // Renumérotation dense des positions restantes (0..n), puis report du
            // décompte de travail dans le compteur scalaire.
            $this->setSynchronizer->renumber($prescribed);
            $this->setSynchronizer->syncScalarFromDetailed($prescribed);
        }

        return $this->setsResponse($request, $workout, $prescribed);
    }

    #[Route('/{id}/sets/{setId}/move/{direction}', name: 'app_workout_set_move', methods: ['POST'], requirements: ['id' => '\d+', 'setId' => '\d+', 'direction' => 'up|down'])]
    public function moveSet(Request $request, Workout $workout, int $setId, string $direction): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);
        $set = $this->findSet($workout, $setId);
        $prescribed = $set->getPrescribedExercise();

        if ($this->isCsrfTokenValid('set_move'.$setId, $request->getPayload()->getString('_token'))) {
            $this->swapPosition($prescribed->getDetailedSets()->toArray(), $set, $direction);
        }

        return $this->setsResponse($request, $workout, $prescribed);
    }

    // ---- Édition rapide (mini-modale de l'éditeur de plan) ------------------

    /**
     * Panneau d'édition rapide d'une séance : ses exercices prescrits avec leurs
     * paramètres éditables (reps/séries/repos…), groupés par bloc. Fragment injecté
     * dans la modale de l'éditeur de plan (fetch), sans layout. La séance est la
     * copie locale portée par la case (référence vivante) : l'éditer se reflète au
     * calendrier sans toucher la biblio ni les autres cases. Pour la structure
     * (ajout/suppression de blocs, glisser-déposer), l'utilisateur passe par le lien
     * « Édition complète » vers le compositeur.
     */
    #[Route('/{id}/quick-panel', name: 'app_workout_quick_panel', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function quickPanel(Workout $workout): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);

        return $this->render('workout/_quick_panel.html.twig', $this->quickPanelContext($workout));
    }

    /**
     * Enregistre les paramètres d'un exercice depuis la mini-modale. Même
     * traitement que editPrescribed, mais renvoie le stream du panneau rapide
     * (#quick-panel) et non celui du compositeur (#workout-blocks). Sans JS, repli
     * par redirection vers le compositeur.
     */
    #[Route('/{id}/exercises/{prescribedId}/quick-edit', name: 'app_workout_prescribed_quick_edit', methods: ['POST'], requirements: ['id' => '\d+', 'prescribedId' => '\d+'])]
    public function quickEditPrescribed(Request $request, Workout $workout, int $prescribedId): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);
        $prescribed = $this->findPrescribed($workout, $prescribedId);

        $form = $this->createPrescribedForm($prescribed, 'app_workout_prescribed_quick_edit');
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->clearIrrelevantFields($prescribed);
            // La durée estimée dérive du contenu : on la recalcule (elle s'affiche
            // sur la case du plan, rafraîchie à la fermeture de la modale).
            $workout->setEstimatedDurationMinutes($this->estimator->estimateMinutes($workout));
            $this->entityManager->flush();
        }

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('workout/stream/quick_panel.stream.html.twig', $this->quickPanelContext($workout));
        }

        return $this->redirectToRoute('app_workout_edit', ['id' => $workout->getId()]);
    }

    #[Route('/{id}/exercises/{prescribedId}/delete', name: 'app_workout_prescribed_delete', methods: ['POST'], requirements: ['id' => '\d+', 'prescribedId' => '\d+'])]
    public function deletePrescribed(Request $request, Workout $workout, int $prescribedId): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);
        $prescribed = $this->findPrescribed($workout, $prescribedId);

        if ($this->isCsrfTokenValid('prescribed_delete'.$prescribedId, $request->getPayload()->getString('_token'))) {
            $prescribed->getBlock()->removePrescribedExercise($prescribed);
            $this->entityManager->flush();
        }

        return $this->blocksResponse($request, $workout);
    }

    #[Route('/{id}/exercises/{prescribedId}/move/{direction}', name: 'app_workout_prescribed_move', methods: ['POST'], requirements: ['id' => '\d+', 'prescribedId' => '\d+', 'direction' => 'up|down'])]
    public function movePrescribed(Request $request, Workout $workout, int $prescribedId, string $direction): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);
        $prescribed = $this->findPrescribed($workout, $prescribedId);

        if ($this->isCsrfTokenValid('prescribed_move'.$prescribedId, $request->getPayload()->getString('_token'))) {
            $this->swapPosition($prescribed->getBlock()->getPrescribedExercises()->toArray(), $prescribed, $direction);
            $this->entityManager->flush();
        }

        return $this->blocksResponse($request, $workout);
    }

    // ---- Helpers -----------------------------------------------------------

    private function findBlock(Workout $workout, int $blockId): Block
    {
        foreach ($workout->getBlocks() as $block) {
            if ($block->getId() === $blockId) {
                return $block;
            }
        }

        throw $this->createNotFoundException('Bloc introuvable dans cette séance.');
    }

    private function findPrescribed(Workout $workout, int $prescribedId): PrescribedExercise
    {
        foreach ($workout->getBlocks() as $block) {
            foreach ($block->getPrescribedExercises() as $prescribed) {
                if ($prescribed->getId() === $prescribedId) {
                    return $prescribed;
                }
            }
        }

        throw $this->createNotFoundException('Exercice prescrit introuvable dans cette séance.');
    }

    private function findSet(Workout $workout, int $setId): PrescribedSet
    {
        foreach ($workout->getBlocks() as $block) {
            foreach ($block->getPrescribedExercises() as $prescribed) {
                foreach ($prescribed->getDetailedSets() as $set) {
                    if ($set->getId() === $setId) {
                        return $set;
                    }
                }
            }
        }

        throw $this->createNotFoundException('Série introuvable dans cette séance.');
    }

    /**
     * Nouvelle série reprenant reps/durée/charge scalaires de l'exercice (point de
     * départ à l'éclatement du mode simple). Type NORMAL par défaut.
     */
    private function newSetFrom(PrescribedExercise $prescribed, int $position): PrescribedSet
    {
        return (new PrescribedSet())
            ->setPosition($position)
            ->setReps($prescribed->getReps())
            ->setWeightKg($prescribed->getWeightKg())
            ->setDurationSeconds($prescribed->getDurationSeconds());
    }

    /**
     * Exercice de la bibliothèque visible par l'utilisateur courant (perso ou
     * global). Renvoie null si l'id n'existe pas ou appartient à un autre membre.
     */
    private function findLibraryExercise(int $id): ?Exercise
    {
        $exercise = $this->exerciseRepository->find($id);
        if (null === $exercise) {
            return null;
        }

        $owner = $exercise->getOwner();

        return (null === $owner || $owner === $this->getUser()) ? $exercise : null;
    }

    /**
     * Type d'effort par défaut à l'ajout express, déduit de l'activité : les
     * activités d'endurance (course, vélo, natation) partent sur « distance ×
     * allure », les autres sur « séries × répétitions ».
     */
    private function defaultPrescriptionType(Exercise $exercise): PrescriptionType
    {
        return match ($exercise->getActivity()) {
            ActivityType::RUNNING, ActivityType::CYCLING, ActivityType::SWIMMING => PrescriptionType::DISTANCE_PACE,
            default => PrescriptionType::SETS_REPS,
        };
    }

    /**
     * Replace un exercice prescrit dans le bloc cible, juste après $afterId
     * (0 = en tête). Gère le déplacement inter-blocs et renumérote les positions
     * du bloc cible de 0..n pour un ordre dense sans trou.
     */
    private function repositionPrescribed(PrescribedExercise $prescribed, Block $targetBlock, int $afterId): void
    {
        $source = $prescribed->getBlock();
        if ($source !== $targetBlock) {
            $source?->removePrescribedExercise($prescribed);
            $targetBlock->addPrescribedExercise($prescribed);
        }

        // Ordre courant du bloc cible sans l'élément déplacé, trié par position.
        $others = array_filter(
            $targetBlock->getPrescribedExercises()->toArray(),
            static fn (PrescribedExercise $pe) => $pe !== $prescribed,
        );
        usort($others, static fn (PrescribedExercise $a, PrescribedExercise $b) => $a->getPosition() <=> $b->getPosition());

        $ordered = [];
        if (0 === $afterId) {
            $ordered[] = $prescribed;
        }
        foreach ($others as $pe) {
            $ordered[] = $pe;
            if ($pe->getId() === $afterId) {
                $ordered[] = $prescribed;
            }
        }
        if (!in_array($prescribed, $ordered, true)) {
            $ordered[] = $prescribed; // afterId introuvable -> à la fin
        }

        foreach ($ordered as $index => $pe) {
            $pe->setPosition($index);
        }
    }

    /**
     * Position suivante = max des positions existantes + 1 (évite les collisions
     * après suppression, contrairement à un simple count()).
     *
     * @param array<object> $items entités exposant getPosition()
     */
    private function nextPosition(array $items): int
    {
        $max = -1;
        foreach ($items as $item) {
            $max = max($max, $item->getPosition());
        }

        return $max + 1;
    }

    /**
     * Échange la position d'un élément avec son voisin dans la liste ordonnée.
     *
     * @param array<object> $ordered liste triée par position croissante
     */
    private function swapPosition(array $ordered, object $entity, string $direction): void
    {
        $index = array_search($entity, $ordered, true);
        if (false === $index) {
            return;
        }

        $target = 'up' === $direction ? $index - 1 : $index + 1;
        if ($target < 0 || $target >= count($ordered)) {
            return;
        }

        $neighbour = $ordered[$target];
        $position = $entity->getPosition();
        $entity->setPosition($neighbour->getPosition());
        $neighbour->setPosition($position);
    }

    /**
     * Applique la règle « seul le sous-ensemble pertinent est rempli » : on
     * annule tout champ de valeur hors du type de prescription choisi.
     */
    private function clearIrrelevantFields(PrescribedExercise $prescribed): void
    {
        $relevant = $prescribed->getPrescriptionType()->fields();

        foreach (array_diff(self::VALUE_FIELDS, $relevant) as $field) {
            $prescribed->{'set'.ucfirst($field)}(null);
        }
    }

    /*
     * Chaque formulaire porte un nom unique (add_block, block_<id>,
     * add_exercise_<blockId>, prescribed_<id>) car plusieurs formulaires
     * coexistent sur la page d'édition : sans ça, les id HTML des champs
     * entreraient en collision. Le nom doit être identique au rendu et au
     * traitement du POST pour que handleRequest lise les bonnes données.
     */

    private function createAddBlockForm(Workout $workout, Block $block): FormInterface
    {
        return $this->formFactory->createNamed('add_block', BlockType::class, $block, [
            'action' => $this->generateUrl('app_workout_block_add', ['id' => $workout->getId()]),
        ]);
    }

    private function createBlockForm(Block $block): FormInterface
    {
        return $this->formFactory->createNamed('block_'.$block->getId(), BlockType::class, $block, [
            'action' => $this->generateUrl('app_workout_block_edit', [
                'id' => $block->getWorkout()->getId(),
                'blockId' => $block->getId(),
            ]),
        ]);
    }

    private function createPrescribedForm(PrescribedExercise $prescribed, string $route = 'app_workout_prescribed_edit'): FormInterface
    {
        return $this->formFactory->createNamed('prescribed_'.$prescribed->getId(), PrescribedExerciseType::class, $prescribed, [
            'user' => $this->getUser(),
            'activity' => $prescribed->getExercise()?->getActivity(),
            // Séries détaillées : les valeurs par série sortent du formulaire,
            // seul le compteur `sets` reste (cf. PrescribedExerciseType).
            'detailed' => $prescribed->hasDetailedSets(),
            'action' => $this->generateUrl($route, [
                'id' => $prescribed->getBlock()->getWorkout()->getId(),
                'prescribedId' => $prescribed->getId(),
            ]),
        ]);
    }

    private function createSetForm(PrescribedSet $set): FormInterface
    {
        $prescribed = $set->getPrescribedExercise();

        return $this->formFactory->createNamed('set_'.$set->getId(), PrescribedSetType::class, $set, [
            'parent_type' => $prescribed->getPrescriptionType() ?? PrescriptionType::SETS_REPS,
            'action' => $this->generateUrl('app_workout_set_edit', [
                'id' => $prescribed->getBlock()->getWorkout()->getId(),
                'setId' => $set->getId(),
            ]),
        ]);
    }

    /**
     * Vues des formulaires de série d'un exercice, indexées par id de série.
     *
     * @return array<int, \Symfony\Component\Form\FormView>
     */
    private function setFormsFor(PrescribedExercise $prescribed): array
    {
        $forms = [];
        foreach ($prescribed->getDetailedSets() as $set) {
            $forms[$set->getId()] = $this->createSetForm($set)->createView();
        }

        return $forms;
    }

    /**
     * Contexte de re-rendu ciblé de la zone des séries d'un exercice (stream).
     *
     * @return array<string, mixed>
     */
    private function setsEditorContext(Workout $workout, PrescribedExercise $prescribed): array
    {
        return [
            'workout' => $workout,
            'prescribed' => $prescribed,
            'summary' => $this->prescribedSummaries($workout)[$prescribed->getId()] ?? '',
            // Vue du formulaire prescrit : le stream re-rend TOUT le panneau de
            // paramètres (form + séries), pour que la visibilité des champs scalaires
            // suive le passage simple <-> détaillé.
            'prescribedForm' => $this->createPrescribedForm($prescribed)->createView(),
            'setForms' => $this->setFormsFor($prescribed),
        ];
    }

    /**
     * Réponse d'une mutation structurelle des séries (ajout/retrait/déplacement) :
     * recalcule la durée estimée, puis re-rend la zone des séries + la pastille de
     * résumé (stream ciblé). Sans JS : redirection vers l'éditeur.
     */
    private function setsResponse(Request $request, Workout $workout, PrescribedExercise $prescribed): Response
    {
        $workout->setEstimatedDurationMinutes($this->estimator->estimateMinutes($workout));
        $this->entityManager->flush();

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('workout/stream/sets_editor.stream.html.twig', $this->setsEditorContext($workout, $prescribed));
        }

        return $this->redirectToRoute('app_workout_edit', ['id' => $workout->getId()]);
    }

    /**
     * Contexte de rendu du panneau d'édition rapide : les formulaires prescrits
     * (postant vers la route quick-edit) et les résumés lisibles par exercice.
     *
     * @return array<string, mixed>
     */
    private function quickPanelContext(Workout $workout): array
    {
        $prescribedForms = [];
        foreach ($workout->getBlocks() as $block) {
            foreach ($block->getPrescribedExercises() as $prescribed) {
                $prescribedForms[$prescribed->getId()] = $this
                    ->createPrescribedForm($prescribed, 'app_workout_prescribed_quick_edit')
                    ->createView();
            }
        }

        return [
            'workout' => $workout,
            'prescribedForms' => $prescribedForms,
            'summaries' => $this->prescribedSummaries($workout),
        ];
    }

    /**
     * Contexte de rendu de l'éditeur de blocs : toutes les vues de formulaires
     * (édition inline de chaque bloc / exercice, ajout de bloc et d'exercice).
     *
     * @return array<string, mixed>
     */
    private function blocksContext(Workout $workout): array
    {
        $blockForms = [];
        $prescribedForms = [];
        $setForms = [];

        foreach ($workout->getBlocks() as $block) {
            $blockForms[$block->getId()] = $this->createBlockForm($block)->createView();

            foreach ($block->getPrescribedExercises() as $prescribed) {
                $prescribedForms[$prescribed->getId()] = $this->createPrescribedForm($prescribed)->createView();

                foreach ($prescribed->getDetailedSets() as $set) {
                    $setForms[$set->getId()] = $this->createSetForm($set)->createView();
                }
            }
        }

        return [
            'workout' => $workout,
            'addBlockForm' => $this->createAddBlockForm($workout, (new Block())->setRole(BlockRole::MAIN))->createView(),
            'blockForms' => $blockForms,
            'prescribedForms' => $prescribedForms,
            'setForms' => $setForms,
            'summaries' => $this->prescribedSummaries($workout),
        ];
    }

    /**
     * Résumé lisible (pastille du compositeur) par exercice prescrit, indexé par
     * id. On réutilise PlanFlattener : aucune mise à plat n'est réimplémentée ici.
     *
     * @return array<int, string>
     */
    private function prescribedSummaries(Workout $workout): array
    {
        $summaries = [];
        foreach ($this->planFlattener->flattenWorkout($workout)['blocks'] as $flatBlock) {
            foreach ($flatBlock['exercises'] as $flat) {
                // On expose aussi le repos dans la pastille de l'éditeur : sans ça
                // il n'apparaissait nulle part pendant la composition.
                $summary = $flat['summary'];
                if (null !== $flat['rest']) {
                    $rest = 'repos '.$flat['rest'].' s';
                    $summary = '' !== $summary ? $summary.' · '.$rest : $rest;
                }
                $summaries[$flat['prescribed']->getId()] = $summary;
            }
        }

        return $summaries;
    }

    /**
     * Contexte de la bibliothèque affichée dans le compositeur : exercices
     * visibles par l'utilisateur (perso + global) et compteurs par activité.
     *
     * @return array<string, mixed>
     */
    private function libraryContext(): array
    {
        $exercises = $this->exerciseRepository->findLibraryForUser($this->getUser());

        $countsByActivity = [];
        foreach ($exercises as $exercise) {
            $key = $exercise->getActivity()->value;
            $countsByActivity[$key] = ($countsByActivity[$key] ?? 0) + 1;
        }

        // Filtres d'activité présents, dans l'ordre canonique de l'enum.
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
            'library' => $exercises,
            'libraryCount' => \count($exercises),
            'libraryActivities' => $activityFilters,
        ];
    }

    /**
     * Répond à une mutation de l'éditeur. Le contrôleur `composer` poste en
     * `fetch` avec un Accept « text/vnd.turbo-stream.html » : on renvoie alors un
     * Turbo Stream qui met à jour (action="update") le conteneur #workout-blocks,
     * appliqué côté client sans recharger. Sans JS (Accept text/html), repli par
     * redirection vers la page d'édition.
     */
    private function blocksResponse(Request $request, Workout $workout): Response
    {
        // La durée estimée est toujours dérivée du contenu : on la recalcule après
        // chaque mutation (l'utilisateur ne la saisit plus).
        $workout->setEstimatedDurationMinutes($this->estimator->estimateMinutes($workout));
        $this->entityManager->flush();

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('workout/stream/blocks.stream.html.twig', $this->blocksContext($workout));
        }

        return $this->redirectToRoute('app_workout_edit', ['id' => $workout->getId()]);
    }
}
