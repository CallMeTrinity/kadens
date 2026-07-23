<?php

namespace App\Controller;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PrescribedExercise;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Form\BlockType;
use App\Form\PrescribedExerciseType;
use App\Form\WorkoutType;
use App\Repository\ExerciseRepository;
use App\Repository\WorkoutRepository;
use App\Security\Voter\WorkoutVoter;
use App\Service\PlanFlattener;
use App\Service\SlugGenerator;
use App\Service\WorkoutEstimator;
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
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FormFactoryInterface $formFactory,
        private readonly PlanFlattener $planFlattener,
        private readonly ExerciseRepository $exerciseRepository,
        private readonly WorkoutEstimator $estimator,
    ) {
    }

    #[Route('', name: 'app_workout_index', methods: ['GET'])]
    public function index(WorkoutRepository $workoutRepository): Response
    {
        return $this->render('workout/index.html.twig', [
            'workouts' => $workoutRepository->findBy(['owner' => $this->getUser()], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/new', name: 'app_workout_new', methods: ['GET', 'POST'])]
    public function new(Request $request, SlugGenerator $slugGenerator): Response
    {
        $workout = new Workout();
        $form = $this->createForm(WorkoutType::class, $workout);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $workout->setOwner($this->getUser());
            $workout->setSlug($slugGenerator->generate($workout->getTitle(), Workout::class));
            $this->entityManager->persist($workout);
            $this->entityManager->flush();

            $this->addFlash('success', 'Séance créée. Compose-la maintenant.');

            return $this->redirectToRoute('app_workout_edit', ['id' => $workout->getId()]);
        }

        return $this->render('workout/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_workout_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Workout $workout, PlanFlattener $planFlattener): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::VIEW, $workout);

        return $this->render('workout/show.html.twig', [
            'flat' => $planFlattener->flattenWorkout($workout),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_workout_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Workout $workout): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);

        $form = $this->createForm(WorkoutType::class, $workout);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Séance mise à jour.');

            return $this->redirectToRoute('app_workout_edit', ['id' => $workout->getId()]);
        }

        return $this->render('workout/edit.html.twig', [
            'workout' => $workout,
            'form' => $form,
        ] + $this->blocksContext($workout) + $this->libraryContext());
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
    public function duplicate(Request $request, Workout $workout, SlugGenerator $slugGenerator): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::VIEW, $workout);

        if (!$this->isCsrfTokenValid('duplicate'.$workout->getId(), $request->getPayload()->getString('_token'))) {
            return $this->redirectToRoute('app_workout_show', ['id' => $workout->getId()]);
        }

        $copy = (new Workout())
            ->setOwner($this->getUser())
            ->setTitle($workout->getTitle().' (copie)')
            ->setDescription($workout->getDescription())
            ->setEstimatedDurationMinutes($workout->getEstimatedDurationMinutes());
        $copy->setSlug($slugGenerator->generate($copy->getTitle(), Workout::class));

        // Copie profonde blocs -> exercices prescrits. La cascade persist de
        // Workout::blocks / Block::prescribedExercises persiste l'arbre entier.
        foreach ($workout->getBlocks() as $block) {
            $blockCopy = (new Block())
                ->setRole($block->getRole())
                ->setRounds($block->getRounds() ?? 1)
                ->setPosition($block->getPosition())
                ->setLabel($block->getLabel());

            foreach ($block->getPrescribedExercises() as $prescribed) {
                $blockCopy->addPrescribedExercise($this->copyPrescribed($prescribed));
            }

            $copy->addBlock($blockCopy);
        }

        $this->entityManager->persist($copy);
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

    #[Route('/{id}/blocks/{blockId}/exercises', name: 'app_workout_prescribed_add', methods: ['POST'], requirements: ['id' => '\d+', 'blockId' => '\d+'])]
    public function addPrescribed(Request $request, Workout $workout, int $blockId): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);
        $block = $this->findBlock($workout, $blockId);

        $prescribed = new PrescribedExercise();
        $form = $this->createAddPrescribedForm($block, $prescribed);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $prescribed->setPosition($this->nextPosition($block->getPrescribedExercises()->toArray()));
            // addPrescribedExercise maintient les DEUX côtés (voir addBlock).
            $block->addPrescribedExercise($prescribed);
            $this->clearIrrelevantFields($prescribed);
            $this->entityManager->persist($prescribed);
            $this->entityManager->flush();
        }

        return $this->blocksResponse($request, $workout);
    }

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

        $form = $this->createPrescribedForm($prescribed);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->clearIrrelevantFields($prescribed);
            $this->entityManager->flush();
        }

        return $this->blocksResponse($request, $workout);
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
     * Duplique un exercice prescrit avec tous ses paramètres (hors identité et
     * bloc, posés par l'appelant). La position est reprise telle quelle.
     */
    private function copyPrescribed(PrescribedExercise $source): PrescribedExercise
    {
        return (new PrescribedExercise())
            ->setExercise($source->getExercise())
            ->setPrescriptionType($source->getPrescriptionType())
            ->setPosition($source->getPosition())
            ->setSets($source->getSets())
            ->setReps($source->getReps())
            ->setWeightKg($source->getWeightKg())
            ->setDurationSeconds($source->getDurationSeconds())
            ->setDistanceMeters($source->getDistanceMeters())
            ->setPaceSecondsPerKm($source->getPaceSecondsPerKm())
            ->setTargetReps($source->getTargetReps())
            ->setCapSeconds($source->getCapSeconds())
            ->setIntensityZone($source->getIntensityZone())
            ->setRestSeconds($source->getRestSeconds())
            ->setNotes($source->getNotes());
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

    private function createAddPrescribedForm(Block $block, PrescribedExercise $prescribed): FormInterface
    {
        return $this->formFactory->createNamed('add_exercise_'.$block->getId(), PrescribedExerciseType::class, $prescribed, [
            'user' => $this->getUser(),
            'action' => $this->generateUrl('app_workout_prescribed_add', [
                'id' => $block->getWorkout()->getId(),
                'blockId' => $block->getId(),
            ]),
        ]);
    }

    private function createPrescribedForm(PrescribedExercise $prescribed): FormInterface
    {
        return $this->formFactory->createNamed('prescribed_'.$prescribed->getId(), PrescribedExerciseType::class, $prescribed, [
            'user' => $this->getUser(),
            'action' => $this->generateUrl('app_workout_prescribed_edit', [
                'id' => $prescribed->getBlock()->getWorkout()->getId(),
                'prescribedId' => $prescribed->getId(),
            ]),
        ]);
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

        foreach ($workout->getBlocks() as $block) {
            $blockForms[$block->getId()] = $this->createBlockForm($block)->createView();

            foreach ($block->getPrescribedExercises() as $prescribed) {
                $prescribedForms[$prescribed->getId()] = $this->createPrescribedForm($prescribed)->createView();
            }
        }

        return [
            'workout' => $workout,
            'addBlockForm' => $this->createAddBlockForm($workout, (new Block())->setRole(BlockRole::MAIN))->createView(),
            'blockForms' => $blockForms,
            'prescribedForms' => $prescribedForms,
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
