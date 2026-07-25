<?php

namespace App\Controller;

use App\Entity\PlanTemplate;
use App\Entity\Workout;
use App\Service\PlanFlattener;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Partage lecture publique (Phase 4). Sert une vue lecture seule d'une séance OU
 * d'un plan via son slug, sans authentification et sans aucune action d'édition.
 *
 * L'accès n'est pas régi par les voters : le lien slug vaut autorisation de
 * lecture. L'édition reste réservée au propriétaire par ailleurs. Tout reste sous
 * le préfixe `/s` (hors access_control), donc anonyme-accessible.
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

    #[Route('/s/plan/{slug}', name: 'app_public_share_plan', methods: ['GET'])]
    public function plan(
        #[MapEntity(mapping: ['slug' => 'slug'])] PlanTemplate $template,
        PlanFlattener $planFlattener,
    ): Response {
        return $this->render('public_share/plan.html.twig', [
            'flat' => $planFlattener->flattenPlanTemplate($template),
            'weeks' => null,
        ]);
    }

    /**
     * Partage d'une plage de semaines : `{range}` = `2-4` ou `3`. Filtre la grille
     * sur ces semaines (bornées à la durée du plan). Stateless : la plage vit dans
     * l'URL, aucun stockage. 404 si la plage est vide/hors trame.
     */
    #[Route('/s/plan/{slug}/semaines/{range}', name: 'app_public_share_plan_weeks', methods: ['GET'], requirements: ['range' => '\d+(?:-\d+)?'])]
    public function planWeeks(
        #[MapEntity(mapping: ['slug' => 'slug'])] PlanTemplate $template,
        string $range,
        PlanFlattener $planFlattener,
    ): Response {
        $durationWeeks = (int) $template->getDurationWeeks();
        [$fromRaw, $toRaw] = str_contains($range, '-')
            ? array_map('intval', explode('-', $range, 2))
            : [(int) $range, (int) $range];

        $from = max(1, min($fromRaw, $toRaw));
        $to = min($durationWeeks, max($fromRaw, $toRaw));
        if ($from > $to) {
            throw $this->createNotFoundException('Plage de semaines hors de la trame.');
        }

        return $this->render('public_share/plan.html.twig', [
            'flat' => $planFlattener->flattenPlanTemplate($template),
            'weeks' => range($from, $to),
        ]);
    }
}
