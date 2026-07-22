<?php

namespace App\Controller;

use App\Entity\Exercise;
use App\Form\ExerciseType;
use App\Repository\ExerciseRepository;
use App\Security\Voter\ExerciseVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/exercise')]
final class ExerciseController extends AbstractController
{
    #[Route('', name: 'app_exercise_index', methods: ['GET'])]
    public function index(ExerciseRepository $exerciseRepository): Response
    {
        return $this->render('exercise/index.html.twig', [
            'exercises' => $exerciseRepository->findLibraryForUser($this->getUser()),
        ]);
    }

    #[Route('/new', name: 'app_exercise_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $exercise = new Exercise();
        $form = $this->createForm(ExerciseType::class, $exercise);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $exercise->setOwner($this->getUser());
            $entityManager->persist($exercise);
            $entityManager->flush();

            $this->addFlash('success', 'Exercice créé.');

            return $this->redirectToRoute('app_exercise_index');
        }

        return $this->render('exercise/new.html.twig', [
            'exercise' => $exercise,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_exercise_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Exercise $exercise): Response
    {
        $this->denyAccessUnlessGranted(ExerciseVoter::VIEW, $exercise);

        return $this->render('exercise/show.html.twig', [
            'exercise' => $exercise,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_exercise_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Exercise $exercise, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(ExerciseVoter::EDIT, $exercise);

        $form = $this->createForm(ExerciseType::class, $exercise);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Exercice mis à jour.');

            return $this->redirectToRoute('app_exercise_index');
        }

        return $this->render('exercise/edit.html.twig', [
            'exercise' => $exercise,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_exercise_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Exercise $exercise, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(ExerciseVoter::DELETE, $exercise);

        if ($this->isCsrfTokenValid('delete'.$exercise->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($exercise);
            $entityManager->flush();

            $this->addFlash('success', 'Exercice supprimé.');
        }

        return $this->redirectToRoute('app_exercise_index');
    }
}
