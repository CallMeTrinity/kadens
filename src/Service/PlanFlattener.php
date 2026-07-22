<?php

namespace App\Service;

use App\Entity\Block;
use App\Entity\PrescribedExercise;
use App\Entity\Workout;
use App\Enum\PrescriptionType;

/**
 * Source unique de mise à plat d'une séance (et, à terme, d'un plan complet).
 *
 * Produit une structure « plate » traversable, indépendante du rendu. La vue de
 * consultation Twig ET le futur export Excel (Phase 8) consomment cette même
 * sortie. Ne jamais dupliquer cette logique dans un contrôleur.
 *
 * Les valeurs numériques brutes (kg / mètres / secondes) sont conservées telles
 * quelles pour l'export ; un champ `summary` lisible est ajouté pour l'affichage.
 *
 * @phpstan-type FlatPrescribed array{prescribed: PrescribedExercise, exercise: \App\Entity\Exercise|null, type: PrescriptionType|null, summary: string, rest: ?int, notes: ?string}
 * @phpstan-type FlatBlock array{block: Block, exercises: list<FlatPrescribed>}
 * @phpstan-type FlatWorkout array{workout: Workout, blocks: list<FlatBlock>}
 */
final class PlanFlattener
{
    /**
     * @return FlatWorkout
     */
    public function flattenWorkout(Workout $workout): array
    {
        $blocks = [];
        foreach ($workout->getBlocks() as $block) {
            $blocks[] = $this->flattenBlock($block);
        }

        return [
            'workout' => $workout,
            'blocks' => $blocks,
        ];
    }

    /**
     * @return FlatBlock
     */
    private function flattenBlock(Block $block): array
    {
        $exercises = [];
        foreach ($block->getPrescribedExercises() as $prescribed) {
            $exercises[] = $this->flattenPrescribed($prescribed);
        }

        return [
            'block' => $block,
            'exercises' => $exercises,
        ];
    }

    /**
     * @return FlatPrescribed
     */
    private function flattenPrescribed(PrescribedExercise $prescribed): array
    {
        return [
            'prescribed' => $prescribed,
            'exercise' => $prescribed->getExercise(),
            'type' => $prescribed->getPrescriptionType(),
            'summary' => $this->summarize($prescribed),
            'rest' => $prescribed->getRestSeconds(),
            'notes' => $prescribed->getNotes(),
        ];
    }

    /**
     * Résumé lisible d'un exercice prescrit selon son type d'effort.
     */
    private function summarize(PrescribedExercise $pe): string
    {
        return match ($pe->getPrescriptionType()) {
            PrescriptionType::SETS_REPS => $this->summarizeSetsReps($pe),
            PrescriptionType::SETS_TIME => $this->summarizeSetsTime($pe),
            PrescriptionType::AMRAP => $this->summarizeAmrap($pe),
            PrescriptionType::FOR_TIME => $this->summarizeForTime($pe),
            PrescriptionType::DISTANCE_PACE => $this->summarizeDistancePace($pe),
            PrescriptionType::DURATION => $this->summarizeDuration($pe),
            null => '',
        };
    }

    private function summarizeSetsReps(PrescribedExercise $pe): string
    {
        $summary = trim(sprintf('%s × %s', $pe->getSets() ?? '?', $pe->getReps() ?? '?'));

        if (null !== $pe->getWeightKg()) {
            $summary .= ' @ '.$this->formatWeight($pe->getWeightKg());
        }

        return $summary;
    }

    private function summarizeSetsTime(PrescribedExercise $pe): string
    {
        $summary = sprintf('%s × %s', $pe->getSets() ?? '?', $this->formatDuration($pe->getDurationSeconds()));

        if (null !== $pe->getWeightKg()) {
            $summary .= ' @ '.$this->formatWeight($pe->getWeightKg());
        }

        return $summary;
    }

    private function summarizeAmrap(PrescribedExercise $pe): string
    {
        $summary = 'AMRAP '.$this->formatDuration($pe->getDurationSeconds());

        if (null !== $pe->getTargetReps()) {
            $summary .= sprintf(' · cible %d reps', $pe->getTargetReps());
        }

        return $summary;
    }

    private function summarizeForTime(PrescribedExercise $pe): string
    {
        $summary = sprintf('%s reps for time', $pe->getTargetReps() ?? '?');

        if (null !== $pe->getCapSeconds()) {
            $summary .= ' · cap '.$this->formatDuration($pe->getCapSeconds());
        }

        return $summary;
    }

    private function summarizeDistancePace(PrescribedExercise $pe): string
    {
        $summary = $this->formatDistance($pe->getDistanceMeters());

        if (null !== $pe->getPaceSecondsPerKm()) {
            $summary .= ' @ '.$this->formatPace($pe->getPaceSecondsPerKm());
        }

        return $summary;
    }

    private function summarizeDuration(PrescribedExercise $pe): string
    {
        $summary = $this->formatDuration($pe->getDurationSeconds());

        if (null !== $pe->getIntensityZone() && '' !== $pe->getIntensityZone()) {
            $summary .= ' · '.$pe->getIntensityZone();
        }

        return $summary;
    }

    private function formatWeight(float $kg): string
    {
        // Affiche un entier sans décimales inutiles (60 kg plutôt que 60,00 kg).
        $formatted = rtrim(rtrim(number_format($kg, 2, ',', ' '), '0'), ',');

        return $formatted.' kg';
    }

    private function formatDistance(?int $meters): string
    {
        if (null === $meters) {
            return '?';
        }

        if ($meters >= 1000) {
            $km = rtrim(rtrim(number_format($meters / 1000, 2, ',', ' '), '0'), ',');

            return $km.' km';
        }

        return $meters.' m';
    }

    /**
     * Secondes -> mm:ss (ou h:mm:ss au-delà d'une heure).
     */
    private function formatDuration(?int $seconds): string
    {
        if (null === $seconds) {
            return '?';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }

    /**
     * Secondes par km -> m:ss/km.
     */
    private function formatPace(int $secondsPerKm): string
    {
        $minutes = intdiv($secondsPerKm, 60);
        $secs = $secondsPerKm % 60;

        return sprintf('%d:%02d/km', $minutes, $secs);
    }
}
