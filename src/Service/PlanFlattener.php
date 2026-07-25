<?php

namespace App\Service;

use App\Entity\Block;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\Workout;
use App\Enum\PaceUnit;
use App\Enum\PrescriptionType;

/**
 * Source unique de mise à plat d'une séance ET d'un plan complet.
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
 * @phpstan-type FlatWorkout array{workout: Workout, blocks: list<FlatBlock>, activities: list<\App\Enum\ActivityType>, exerciseCount: int}
 * @phpstan-type FlatItem array{item: PlanItem, workout: FlatWorkout}
 * @phpstan-type FlatDay array{dayOfWeek: int, items: list<FlatItem>}
 * @phpstan-type FlatWeek array{weekNumber: int, days: list<FlatDay>}
 * @phpstan-type FlatPlan array{template: PlanTemplate, weeks: list<FlatWeek>}
 */
final class PlanFlattener
{
    public function __construct(
        private readonly UnitFormatter $units,
        private readonly WorkoutMetrics $metrics,
    ) {
    }

    /**
     * Mise à plat d'un plan complet : une grille semaines × jours (1..7, ISO :
     * 1=lundi..7=dimanche), chaque case portant la liste des séances placées,
     * elles-mêmes aplaties. La grille est dense (toutes les cases existent,
     * même vides) pour que le rendu et l'export n'aient aucun trou à gérer.
     *
     * @return FlatPlan
     */
    public function flattenPlanTemplate(PlanTemplate $template): array
    {
        // Indexation des items par semaine/jour pour un accès direct par case.
        $byCell = [];
        foreach ($template->getPlanItems() as $item) {
            $byCell[$item->getWeekNumber()][$item->getDayOfWeek()][] = $item;
        }

        $weeks = [];
        for ($week = 1; $week <= (int) $template->getDurationWeeks(); ++$week) {
            $days = [];
            for ($day = 1; $day <= 7; ++$day) {
                $items = [];
                foreach ($byCell[$week][$day] ?? [] as $item) {
                    $items[] = [
                        'item' => $item,
                        'workout' => $this->flattenWorkout($item->getWorkout()),
                    ];
                }

                $days[] = [
                    'dayOfWeek' => $day,
                    'items' => $items,
                ];
            }

            $weeks[] = [
                'weekNumber' => $week,
                'days' => $days,
            ];
        }

        return [
            'template' => $template,
            'weeks' => $weeks,
        ];
    }

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
            // Repères de lecture (badges de case, cartes de palette) : dérivés du
            // contenu, pas stockés. Voir WorkoutMetrics.
            'activities' => $this->metrics->distinctActivities($workout),
            'exerciseCount' => $this->metrics->exerciseCount($workout),
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
            $summary .= ' @ '.$this->units->weight($pe->getWeightKg());
        }

        return $summary;
    }

    private function summarizeSetsTime(PrescribedExercise $pe): string
    {
        $summary = sprintf('%s × %s', $pe->getSets() ?? '?', $this->units->duration($pe->getDurationSeconds()));

        if (null !== $pe->getWeightKg()) {
            $summary .= ' @ '.$this->units->weight($pe->getWeightKg());
        }

        return $summary;
    }

    private function summarizeAmrap(PrescribedExercise $pe): string
    {
        $summary = 'AMRAP '.$this->units->duration($pe->getDurationSeconds());

        if (null !== $pe->getTargetReps()) {
            $summary .= sprintf(' · cible %d reps', $pe->getTargetReps());
        }

        return $summary;
    }

    private function summarizeForTime(PrescribedExercise $pe): string
    {
        $summary = sprintf('%s reps for time', $pe->getTargetReps() ?? '?');

        if (null !== $pe->getCapSeconds()) {
            $summary .= ' · cap '.$this->units->duration($pe->getCapSeconds());
        }

        return $summary;
    }

    private function summarizeDistancePace(PrescribedExercise $pe): string
    {
        $summary = $this->units->distance($pe->getDistanceMeters());

        if (null !== $pe->getPaceSecondsPerKm()) {
            // Allure affichée dans l'unité naturelle de l'activité de l'exercice
            // (course min/km, vélo km/h, natation min/100m).
            $unit = PaceUnit::forActivity($pe->getExercise()?->getActivity());
            $summary .= ' @ '.$this->units->pace($pe->getPaceSecondsPerKm(), $unit);
        }

        return $summary;
    }

    private function summarizeDuration(PrescribedExercise $pe): string
    {
        $summary = $this->units->duration($pe->getDurationSeconds());

        if (null !== $pe->getIntensityZone() && '' !== $pe->getIntensityZone()) {
            $summary .= ' · '.$pe->getIntensityZone();
        }

        return $summary;
    }
}
