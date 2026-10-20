<?php

namespace App\Controller;

use App\Entity\Goal;
use App\Entity\GoalBlock;
use App\Entity\PlanTemplate;
use App\Entity\Workout;
use App\Service\GoalPhotoStorage;
use App\Service\GoalStory;
use App\Service\PlanFlattener;
use App\Service\WorkoutMetrics;
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
 *
 * **Le récit d'objectif est l'exception opt-in** (#42) : un objectif est privé,
 * son slug n'existe qu'après une première publication, et il ne vaut lecture que
 * tant que `publishedAt` est posé. Dépublié, la page et ses photos rendent 404 —
 * le même code qu'un slug inconnu, pour ne rien confirmer.
 */
final class PublicShareController extends AbstractController
{
    #[Route('/s/{slug}', name: 'app_public_share', methods: ['GET'])]
    public function workout(
        #[MapEntity(mapping: ['slug' => 'slug'])] Workout $workout,
        PlanFlattener $planFlattener,
        WorkoutMetrics $metrics,
    ): Response {
        // Mêmes repères qu'en interne : la synthèse est dérivée du contenu déjà
        // publié, elle n'expose rien de plus que la séance elle-même.
        return $this->render('public_share/workout.html.twig', [
            'flat' => $planFlattener->flattenWorkout($workout),
            'summary' => $metrics->summary($workout),
            'blockStats' => $metrics->blockBreakdown($workout),
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

    /**
     * Le récit publié d'un objectif. N'affiche que ce qui se raconte : intitulé,
     * échéance, cible, présentation, récit, résultat. Jamais les plans rattachés
     * ni les séances à venir — c'est la préparation, pas l'histoire.
     */
    #[Route('/s/goal/{slug}', name: 'app_public_share_goal', methods: ['GET'])]
    public function goal(
        #[MapEntity(mapping: ['slug' => 'slug'])] Goal $goal,
        GoalStory $story,
    ): Response {
        if (!$goal->isPublished()) {
            throw $this->createNotFoundException();
        }

        return $this->render('public_share/goal.html.twig', [
            'goal' => $goal,
            'story' => $story->present($goal),
        ]);
    }

    /** Cache public, mais court : dépublier doit finir par se voir chez un intermédiaire. */
    #[Route('/s/goal/{slug}/photo/{blockId}', name: 'app_public_share_goal_photo', methods: ['GET'], requirements: ['blockId' => '\d+'])]
    public function goalPhoto(
        #[MapEntity(mapping: ['slug' => 'slug'])] Goal $goal,
        #[MapEntity(id: 'blockId')] GoalBlock $block,
        GoalPhotoStorage $photos,
    ): Response {
        if (!$goal->isPublished() || $block->getGoal()?->getId() !== $goal->getId()) {
            throw $this->createNotFoundException();
        }

        $response = $photos->response($block);
        if (null === $response) {
            throw $this->createNotFoundException();
        }
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
