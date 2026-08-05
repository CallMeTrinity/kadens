<?php

namespace App\Controller;

use App\Entity\Exercise;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Form\ExerciseType;
use App\Repository\ExerciseRepository;
use App\Security\Voter\ExerciseVoter;
use App\Service\ExerciseTrajectory;
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
        $exercises = $exerciseRepository->findLibraryForUser($this->getUser());

        // Puces d'activité présentes (chaque exercice porte une activité).
        $counts = [];
        foreach ($exercises as $exercise) {
            $activity = $exercise->getActivity();
            if (null !== $activity) {
                $counts[$activity->value] = ($counts[$activity->value] ?? 0) + 1;
            }
        }
        $activityFacets = [];
        foreach (ActivityType::cases() as $case) {
            if (isset($counts[$case->value])) {
                $activityFacets[] = ['value' => $case->value, 'label' => $case->getLabel(), 'count' => $counts[$case->value]];
            }
        }

        return $this->render('exercise/index.html.twig', [
            'exercises' => $exercises,
            'activityFacets' => $activityFacets,
        ]);
    }

    /**
     * Un ROLE_ADMIN alimente la bibliothèque **globale** (owner null, visible par
     * tous) : c'est le pendant à la main de la commande d'import, et le seul rôle
     * qui peut ensuite l'éditer. Tout autre membre crée un exercice perso.
     */
    #[Route('/new', name: 'app_exercise_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $exercise = new Exercise();
        $form = $this->createForm(ExerciseType::class, $exercise);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $exercise->setOwner($this->isGranted('ROLE_ADMIN') ? null : $this->getUser());
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

    /**
     * La fiche d'un exercice, et sous elle **sa trajectoire** (KL-50) : record,
     * dernière performance, courbe de charge et dix dernières séances.
     *
     * L'historique est scopé sur `$this->getUser()`, et c'est ici volontaire — pas
     * un oubli du « scoper sur l'owner » que suit le reste des vues ouvertes au
     * coach. Un exercice de la bibliothèque globale n'a pas de propriétaire, et il
     * est pratiqué par tout le monde : « est-ce que je progresse » ne peut vouloir
     * dire que « moi ». Personne ne lit ici le réalisé d'un autre.
     */
    #[Route('/{id}', name: 'app_exercise_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Exercise $exercise, ExerciseTrajectory $trajectory): Response
    {
        $this->denyAccessUnlessGranted(ExerciseVoter::VIEW, $exercise);

        $user = $this->getUser();

        return $this->render('exercise/show.html.twig', [
            'exercise' => $exercise,
            'trajectory' => $user instanceof User ? $trajectory->for($user, $exercise) : null,
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
