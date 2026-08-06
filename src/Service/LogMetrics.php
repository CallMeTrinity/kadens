<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ScheduledWorkout;

/**
 * Le pendant RÉALISÉ de WorkoutMetrics : les mêmes repères, lus sur les
 * LoggedExercise d'une séance datée au lieu des blocs d'une séance prescrite.
 *
 * La forme de summary() est volontairement identique à celle de
 * WorkoutMetrics::summary() : c'est ce qui permet au bandeau de KPI de
 * `_workout_read` de se rendre tel quel sur du réalisé (KL-07). Deux clés
 * n'ont simplement pas d'équivalent et valent 0 — le réalisé est PLAT : il ne
 * porte ni blocs (`blockCount`) ni liaisons de superset (`supersets`,
 * `circuits`), qui n'appartiennent qu'à l'intention. Trois clés s'y ajoutent,
 * propres au fait accompli : `durationSeconds`, `skipped`, `loggedAt`.
 *
 * Périmètre du volume : tout le réalisé compte, sans filtrer sur
 * ActivityType::GYM comme le fait le prescrit. La règle du projet est que seule
 * la muscu se logue, et un LoggedExercise dont l'Exercise a été supprimé
 * (SET NULL) n'a plus d'activité du tout — l'écarter ferait disparaître le
 * tonnage d'une séance réellement faite. Seule la ventilation par région
 * dépend encore de la définition en bibliothèque, faute de zones ailleurs.
 *
 * @phpstan-import-type RegionShare from RegionBreakdown
 * @phpstan-import-type TopLift from WorkoutMetrics
 *
 * @phpstan-type LogSummary array{tonnageKg: float, workingSets: int, exerciseCount: int, blockCount: int, supersets: int, circuits: int, averageRpe: float|null, topLift: TopLift|null, regions: list<RegionShare>, durationSeconds: int|null, skipped: int, loggedAt: \DateTimeImmutable|null}
 */
final class LogMetrics
{
    public function __construct(
        private readonly RegionBreakdown $regions,
    ) {
    }

    /**
     * Synthèse d'en-tête du réalisé d'une séance datée.
     *
     * Renvoie null quand la séance ne porte aucun exercice réalisé : une séance
     * simplement cochée « faite » n'a pas de bandeau de KPI à montrer, et
     * l'appelant n'a pas à distinguer « zéro » de « rien ».
     *
     * @return LogSummary|null
     */
    public function summary(ScheduledWorkout $scheduled): ?array
    {
        if (!$scheduled->hasLog()) {
            return null;
        }

        $setsByArea = [];
        $tonnage = 0.0;
        $workingSets = 0;
        $exerciseCount = 0;
        $skipped = 0;
        $rpeTotal = 0;
        $rpeCount = 0;
        $topWeight = null;
        $topExercise = null;
        $topSets = 0;

        foreach ($scheduled->getLoggedExercises() as $logged) {
            if ($logged->isSkipped()) {
                // Sauté volontairement : c'est une information (KL-05 en fera un
                // écart), pas du volume. Il ne gonfle pas le compte d'exercices.
                ++$skipped;

                continue;
            }

            ++$exerciseCount;

            // Séries de travail : l'échauffement est exclu ici comme partout
            // ailleurs, et la série cochée sans valeur avec lui
            // (LoggedSet::countsAsWorking) — y compris du record.
            $sets = $logged->getWorkingSetCount();
            $workingSets += $sets;

            if ($sets > 0) {
                foreach ($logged->getExercise()?->getTargetAreas() ?? [] as $area) {
                    $setsByArea[$area->value] = ($setsByArea[$area->value] ?? 0) + $sets;
                }
            }

            foreach ($logged->getLoggedSets() as $set) {
                // getTonnageKg() renvoie déjà 0 pour un échauffement ou une série
                // non chiffrée en charge : ne pas re-filtrer ici.
                $tonnage += $set->getTonnageKg();

                if (!$set->countsAsWorking()) {
                    continue;
                }

                // Le RPE est porté par la SÉRIE, pas par l'exercice : chaque série
                // pèse pour une, la moyenne est donc déjà pondérée par le volume.
                // C'est le même résultat que la pondération explicite du prescrit.
                if (null !== $set->getRpe()) {
                    $rpeTotal += $set->getRpe();
                    ++$rpeCount;
                }

                $weight = $set->getWeightKg();
                if (null !== $weight && (null === $topWeight || $weight > $topWeight)) {
                    $topWeight = $weight;
                    $topExercise = $logged->getExerciseName();
                    $topSets = $sets;
                }
            }
        }

        return [
            'tonnageKg' => $tonnage,
            'workingSets' => $workingSets,
            'exerciseCount' => $exerciseCount,
            'blockCount' => 0,
            'supersets' => 0,
            'circuits' => 0,
            'averageRpe' => $rpeCount > 0 ? round($rpeTotal / $rpeCount, 1) : null,
            'topLift' => null !== $topWeight && null !== $topExercise
                ? ['exercise' => $topExercise, 'weightKg' => $topWeight, 'sets' => $topSets]
                : null,
            'regions' => $this->regions->shares($setsByArea),
            'durationSeconds' => $this->durationSeconds($scheduled),
            'skipped' => $skipped,
            'loggedAt' => $this->loggedAt($scheduled),
        ];
    }

    /**
     * Durée RÉELLE de la séance, bornes écrites par le mobile. Distincte de la
     * durée estimée du prescrit (WorkoutEstimator) et de la date planifiée : on
     * peut faire à 19h une séance prévue pour la journée.
     *
     * null dès qu'une borne manque — une séance synchronisée en cours d'exécution
     * n'a pas encore de fin, et une durée « depuis le début jusqu'à maintenant »
     * serait une valeur qui bouge à chaque rafraîchissement. Une fin antérieure au
     * début (horloge du téléphone rattrapée entre les deux écritures) est ramenée
     * à 0 plutôt que rendue négative.
     */
    public function durationSeconds(ScheduledWorkout $scheduled): ?int
    {
        $start = $scheduled->getStartedAt();
        $end = $scheduled->getEndedAt();

        if (null === $start || null === $end) {
            return null;
        }

        return max(0, $end->getTimestamp() - $start->getTimestamp());
    }

    /**
     * Quand la séance a réellement été faite : la fin d'exécution si elle est
     * connue, sinon la dernière série complétée. Le réalisé peut être synchronisé
     * sans bornes (saisie a posteriori), les séries portent alors seules la date.
     */
    private function loggedAt(ScheduledWorkout $scheduled): ?\DateTimeImmutable
    {
        if (null !== $scheduled->getEndedAt()) {
            return $scheduled->getEndedAt();
        }

        $last = null;
        foreach ($scheduled->getLoggedExercises() as $logged) {
            foreach ($logged->getLoggedSets() as $set) {
                $completedAt = $set->getCompletedAt();
                if (null !== $completedAt && (null === $last || $completedAt > $last)) {
                    $last = $completedAt;
                }
            }
        }

        return $last;
    }
}
