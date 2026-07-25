<?php

namespace App\Service;

use App\Entity\PrescribedExercise;
use App\Entity\Workout;
use App\Enum\PrescriptionType;

/**
 * Estime la durée d'une séance à partir de son contenu. L'utilisateur ne saisit
 * plus la durée à la main : une vraie valeur dérivée du travail prescrit est
 * toujours meilleure qu'une estimation faite de tête. Plus les paramètres
 * (séries, reps, repos, allure) sont renseignés, plus l'estimation est juste.
 *
 * Hypothèses par défaut quand une valeur manque :
 *   - 10 répétitions ≈ 1 minute de travail (6 s / rep) ;
 *   - repos par défaut de 2 min entre les séries de renforcement si non précisé.
 *
 * Les durées sont sommées par bloc puis multipliées par le nombre de tours.
 */
final class WorkoutEstimator
{
    private const SECONDS_PER_REP = 6;        // 10 reps ≈ 1 min
    private const DEFAULT_REST_SECONDS = 120; // 2 min de repos si non renseigné

    public function estimateSeconds(Workout $workout): int
    {
        $total = 0;

        foreach ($workout->getBlocks() as $block) {
            $blockSeconds = 0;
            foreach ($block->getPrescribedExercises() as $prescribed) {
                $blockSeconds += $this->prescribedSeconds($prescribed);
            }

            $total += $blockSeconds * max(1, $block->getRounds() ?? 1);
        }

        return $total;
    }

    /**
     * Durée estimée en minutes (arrondie au supérieur), ou null si la séance est
     * vide : les gabarits d'affichage masquent alors la badge de durée.
     */
    public function estimateMinutes(Workout $workout): ?int
    {
        $seconds = $this->estimateSeconds($workout);

        return $seconds > 0 ? (int) ceil($seconds / 60) : null;
    }

    private function prescribedSeconds(PrescribedExercise $pe): int
    {
        return match ($pe->getPrescriptionType()) {
            PrescriptionType::SETS_REPS => $this->setsReps($pe),
            PrescriptionType::SETS_TIME => $this->setsTime($pe),
            PrescriptionType::AMRAP => $pe->getDurationSeconds() ?? 0,
            PrescriptionType::FOR_TIME => $this->forTime($pe),
            PrescriptionType::DISTANCE_PACE => $this->distancePace($pe),
            PrescriptionType::DURATION => $pe->getDurationSeconds() ?? 0,
            null => 0,
        };
    }

    private function setsReps(PrescribedExercise $pe): int
    {
        $sets = max(1, $pe->getSets() ?? 1);
        $reps = $pe->getReps() ?? 10;
        $rest = $pe->getRestSeconds() ?? self::DEFAULT_REST_SECONDS;

        return $sets * ($reps * self::SECONDS_PER_REP + $rest);
    }

    private function setsTime(PrescribedExercise $pe): int
    {
        $sets = max(1, $pe->getSets() ?? 1);
        $duration = $pe->getDurationSeconds() ?? 0;
        $rest = $pe->getRestSeconds() ?? self::DEFAULT_REST_SECONDS;

        return $sets * ($duration + $rest);
    }

    private function forTime(PrescribedExercise $pe): int
    {
        // Le cap est la borne haute du temps ; sinon on estime depuis les reps cible.
        if (null !== $pe->getCapSeconds()) {
            return $pe->getCapSeconds();
        }

        return ($pe->getTargetReps() ?? 0) * self::SECONDS_PER_REP;
    }

    private function distancePace(PrescribedExercise $pe): int
    {
        $meters = $pe->getDistanceMeters() ?? 0;
        $pace = $pe->getPaceSecondsPerKm() ?? 0;
        $work = (int) round($meters / 1000 * $pace);

        return $work + ($pe->getRestSeconds() ?? 0);
    }
}
