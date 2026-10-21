<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Goal;
use App\Entity\GoalBlock;
use App\Entity\ImportedActivity;
use App\Entity\ScheduledWorkout;
use App\Enum\GoalBlockType;
use App\Enum\ScheduledStatus;
use App\Repository\ImportedActivityRepository;
use App\Repository\ScheduledWorkoutRepository;

/**
 * Le récit d'un objectif mis en forme pour l'affichage, et les règles de ce
 * qu'on peut y insérer. Une seule lecture pour les trois écrans qui le rendent
 * (fiche objectif, éditeur, page publique) : ils ne peuvent pas raconter deux
 * versions de la même sortie.
 *
 * Les blocs de référence ne recopient aucun chiffre : une activité se lit par
 * `ImportedActivityPresenter`, une séance faite par `LogMetrics` (le réalisé de
 * salle) et par ses activités rattachées (le réel cardio) — les mêmes sources
 * que la page de la séance datée. Le prescrit n'y entre jamais : le récit dit ce
 * qui a eu lieu, pas ce qui était prévu.
 *
 * @phpstan-type StoryItem array{block: GoalBlock, type: string, orphan: bool, activity: array<string, mixed>|null, session: array<string, mixed>|null}
 */
final class GoalStory
{
    /** Fenêtre des candidats : la prépa (comme « Séances menant à l'échéance ») et l'après-course. */
    private const WINDOW_BEFORE = '-16 weeks';
    private const WINDOW_AFTER = '+14 days';

    public function __construct(
        private readonly ImportedActivityPresenter $activityPresenter,
        private readonly ImportedActivityRepository $activities,
        private readonly ScheduledWorkoutRepository $scheduledWorkouts,
        private readonly LogMetrics $logMetrics,
        private readonly WorkoutMetrics $workoutMetrics,
        private readonly UnitFormatter $units,
    ) {
    }

    /**
     * @return list<StoryItem>
     */
    public function present(Goal $goal): array
    {
        $items = [];

        foreach ($goal->getOrderedBlocks() as $block) {
            $activity = $block->getImportedActivity();
            $scheduled = $block->getScheduledWorkout();

            $items[] = [
                'block' => $block,
                'type' => $block->getType()->value,
                'orphan' => $block->isOrphan(),
                'activity' => GoalBlockType::ACTIVITY === $block->getType() && null !== $activity
                    ? $this->activityPresenter->present($activity)
                    : null,
                'session' => GoalBlockType::SESSION === $block->getType() && null !== $scheduled
                    ? $this->session($scheduled)
                    : null,
            ];
        }

        return $items;
    }

    /**
     * Ce qu'on peut encore insérer : les activités importées et les séances
     * faites du propriétaire autour de l'échéance, moins celles déjà racontées.
     *
     * @return array{activities: list<ImportedActivity>, sessions: list<ScheduledWorkout>}
     */
    public function candidates(Goal $goal): array
    {
        $owner = $goal->getOwner();
        $target = $goal->getTargetDate();
        if (null === $owner || null === $target) {
            return ['activities' => [], 'sessions' => []];
        }

        $start = $target->modify(self::WINDOW_BEFORE);
        $end = $target->modify(self::WINDOW_AFTER);

        $activities = array_values(array_filter(
            $this->activities->findForOwnerBetween($owner, $start, $end),
            static fn (ImportedActivity $a): bool => !$goal->hasReferenceTo($a),
        ));

        // Plus récente en tête, comme les activités : on raconte souvent la fin.
        $sessions = array_reverse(array_values(array_filter(
            $this->scheduledWorkouts->findByOwnerBetween($owner, $start, $end),
            fn (ScheduledWorkout $s): bool => $this->acceptsSession($goal, $s) && !$goal->hasReferenceTo($s),
        )));

        return ['activities' => $activities, 'sessions' => $sessions];
    }

    /**
     * Une activité n'entre que dans le récit de son propriétaire. Le contrôle
     * vaut aussi pour un identifiant forgé : la liste proposée est déjà scopée,
     * la garde couvre le reste. Même règle que les rattachements objectif↔plan.
     */
    public function acceptsActivity(Goal $goal, ImportedActivity $activity): bool
    {
        return null !== $goal->getOwner() && $activity->getOwner() === $goal->getOwner();
    }

    /** Une séance n'entre que si elle est à lui ET faite : le récit ne raconte pas l'intention. */
    public function acceptsSession(Goal $goal, ScheduledWorkout $scheduled): bool
    {
        return null !== $goal->getOwner()
            && $scheduled->getOwner() === $goal->getOwner()
            && ScheduledStatus::DONE === $scheduled->getStatus();
    }

    /**
     * @return array<string, mixed>
     */
    private function session(ScheduledWorkout $scheduled): array
    {
        $workout = $scheduled->getWorkout();
        $log = $this->logMetrics->summary($scheduled);
        $imported = $this->activities->findForScheduledWorkout($scheduled);

        $meters = 0;
        $seconds = 0;
        foreach ($imported as $activity) {
            $meters += $activity->getDistanceMeters() ?? 0;
            $seconds += $activity->getMovingSeconds() ?? 0;
        }

        return [
            'entity' => $scheduled,
            'title' => $scheduled->getDisplayTitle(),
            'date' => $scheduled->getScheduledDate(),
            'activities' => null !== $workout ? $this->workoutMetrics->distinctActivities($workout) : [],
            'tonnage' => null !== $log && $log['tonnageKg'] > 0 ? $this->units->tonnage($log['tonnageKg']) : null,
            'workingSets' => $log['workingSets'] ?? null,
            'topLift' => $log['topLift'] ?? null,
            'distance' => $meters > 0 ? $this->units->distance($meters) : null,
            'duration' => $seconds > 0 ? $this->units->duration($seconds) : null,
        ];
    }
}
