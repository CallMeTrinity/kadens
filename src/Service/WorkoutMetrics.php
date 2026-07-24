<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\PrescriptionType;

/**
 * Repères dérivés du contenu d'une séance (aucun stockage) : activités
 * distinctes, nombre d'exercices, et volume ventilé par activité.
 *
 * Réutilisé par :
 * - PlanFlattener (badges d'activité + compteur d'exos par case) ;
 * - les cartes de la palette de séances de l'éditeur de trame ;
 * - PlanVolumeAggregator (agrégat de charge par semaine).
 *
 * @phpstan-type GymVolume array{setsByArea: array<string, int>, tonnageKg: float, totalSets: int}
 * @phpstan-type EnduranceVolume array{meters: int, seconds: int}
 * @phpstan-type WorkoutVolume array{gym: GymVolume, running: EnduranceVolume, cycling: EnduranceVolume, swimming: EnduranceVolume}
 */
final class WorkoutMetrics
{
    /**
     * Activités distinctes présentes dans une séance (via les exercices de ses
     * blocs), dans l'ordre de première apparition. Helper absent des entités.
     *
     * @return list<ActivityType>
     */
    public function distinctActivities(Workout $workout): array
    {
        $seen = [];
        foreach ($workout->getBlocks() as $block) {
            foreach ($block->getPrescribedExercises() as $prescribed) {
                $activity = $prescribed->getExercise()?->getActivity();
                if (null !== $activity && !isset($seen[$activity->value])) {
                    $seen[$activity->value] = $activity;
                }
            }
        }

        return array_values($seen);
    }

    public function exerciseCount(Workout $workout): int
    {
        $count = 0;
        foreach ($workout->getBlocks() as $block) {
            $count += $block->getPrescribedExercises()->count();
        }

        return $count;
    }

    /**
     * Volume ventilé par activité pour UNE séance :
     * - salle : séries attribuées à CHAQUE groupe musculaire ciblé (métrique
     *   standard « séries par groupe musculaire »), + tonnage (séries × reps ×
     *   charge) quand une charge est présente ;
     * - course / vélo / natation : distance (m) et durée (s) cumulées.
     *
     * Les tours de bloc (rounds) multiplient le volume : un exercice dans un bloc
     * à 3 tours compte 3 fois.
     *
     * @return WorkoutVolume
     */
    public function volume(Workout $workout): array
    {
        $gymSetsByArea = [];
        $gymTonnage = 0.0;
        $gymTotalSets = 0;
        $endurance = [
            'running' => ['meters' => 0, 'seconds' => 0],
            'cycling' => ['meters' => 0, 'seconds' => 0],
            'swimming' => ['meters' => 0, 'seconds' => 0],
        ];

        foreach ($workout->getBlocks() as $block) {
            $rounds = max(1, $block->getRounds() ?? 1);

            foreach ($block->getPrescribedExercises() as $pe) {
                $exercise = $pe->getExercise();
                $activity = $exercise?->getActivity();
                if (null === $activity) {
                    continue;
                }

                if (ActivityType::GYM === $activity) {
                    $sets = ($pe->getSets() ?? 0) * $rounds;
                    if ($sets > 0) {
                        $gymTotalSets += $sets;
                        foreach ($exercise->getTargetAreas() ?? [] as $area) {
                            $gymSetsByArea[$area->value] = ($gymSetsByArea[$area->value] ?? 0) + $sets;
                        }
                    }

                    if (PrescriptionType::SETS_REPS === $pe->getPrescriptionType()
                        && null !== $pe->getWeightKg() && null !== $pe->getReps()) {
                        $gymTonnage += $sets * $pe->getReps() * $pe->getWeightKg();
                    }

                    continue;
                }

                $key = match ($activity) {
                    ActivityType::RUNNING => 'running',
                    ActivityType::CYCLING => 'cycling',
                    ActivityType::SWIMMING => 'swimming',
                    default => null,
                };
                if (null === $key) {
                    continue;
                }

                $endurance[$key]['meters'] += ($pe->getDistanceMeters() ?? 0) * $rounds;
                $endurance[$key]['seconds'] += ($pe->getDurationSeconds() ?? 0) * $rounds;
            }
        }

        return [
            'gym' => [
                'setsByArea' => $gymSetsByArea,
                'tonnageKg' => $gymTonnage,
                'totalSets' => $gymTotalSets,
            ],
            'running' => $endurance['running'],
            'cycling' => $endurance['cycling'],
            'swimming' => $endurance['swimming'],
        ];
    }
}
