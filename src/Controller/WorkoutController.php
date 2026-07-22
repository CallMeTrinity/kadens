<?php

namespace App\Controller;

use App\Entity\Block;
use App\Entity\PrescribedExercise;
use App\Entity\Workout;
use App\Enum\BlockRole;
use App\Form\BlockType;
use App\Form\PrescribedExerciseType;
use App\Form\WorkoutType;
use App\Repository\WorkoutRepository;
use App\Security\Voter\WorkoutVoter;
use App\Service\PlanFlattener;
use App\Service\SlugGenerator;
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
        ] + $this->blocksContext($workout));
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

    // ---- Édition des blocs -------------------------------------------------

    #[Route('/{id}/blocks', name: 'app_workout_block_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addBlock(Request $request, Workout $workout): Response
    {
        $this->denyAccessUnlessGranted(WorkoutVoter::EDIT, $workout);

        $block = (new Block())->setRole(BlockRole::MAIN);
        $form = $this->createAddBlockForm($workout, $block);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $block->setWorkout($workout);
            $block->setPosition($this->nextPosition($workout->getBlocks()->toArray()));
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
            $prescribed->setBlock($block);
            $prescribed->setPosition($this->nextPosition($block->getPrescribedExercises()->toArray()));
            $this->clearIrrelevantFields($prescribed);
            $this->entityManager->persist($prescribed);
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
        $addExerciseForms = [];
        $prescribedForms = [];

        foreach ($workout->getBlocks() as $block) {
            $blockForms[$block->getId()] = $this->createBlockForm($block)->createView();
            $addExerciseForms[$block->getId()] = $this->createAddPrescribedForm($block, new PrescribedExercise())->createView();

            foreach ($block->getPrescribedExercises() as $prescribed) {
                $prescribedForms[$prescribed->getId()] = $this->createPrescribedForm($prescribed)->createView();
            }
        }

        return [
            'workout' => $workout,
            'addBlockForm' => $this->createAddBlockForm($workout, (new Block())->setRole(BlockRole::MAIN))->createView(),
            'blockForms' => $blockForms,
            'addExerciseForms' => $addExerciseForms,
            'prescribedForms' => $prescribedForms,
        ];
    }

    /**
     * Répond à une mutation de l'éditeur : Turbo Stream si la requête l'accepte,
     * sinon repli par redirection vers la page d'édition (dégradation sans JS).
     */
    private function blocksResponse(Request $request, Workout $workout): Response
    {
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('workout/stream/blocks.stream.html.twig', $this->blocksContext($workout));
        }

        return $this->redirectToRoute('app_workout_edit', ['id' => $workout->getId()]);
    }
}
