<?php

namespace App\Controller;

use App\Entity\Workout;
use App\Service\PlanFlattener;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Partage lecture publique (Phase 4). Sert une vue lecture seule d'une séance
 * via son slug, sans authentification et sans aucune action d'édition.
 *
 * L'accès n'est pas régi par WorkoutVoter : le lien slug vaut autorisation de
 * lecture. L'édition reste réservée au propriétaire par ailleurs.
 */
final class PublicShareController extends AbstractController
{
    #[Route('/s/{slug}', name: 'app_public_share', methods: ['GET'])]
    public function workout(
        #[MapEntity(mapping: ['slug' => 'slug'])] Workout $workout,
        PlanFlattener $planFlattener,
    ): Response {
        return $this->render('public_share/workout.html.twig', [
            'flat' => $planFlattener->flattenWorkout($workout),
        ]);
    }
}
