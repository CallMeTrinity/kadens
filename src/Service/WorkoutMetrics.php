<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Block;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\TargetArea;
use App\Enum\TargetRegion;

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
 * @phpstan-type RegionShare array{region: TargetRegion, sets: int, percent: float}
 * @phpstan-type TopLift array{exercise: string, weightKg: float, sets: int}
 * @phpstan-type WorkoutSummary array{tonnageKg: float, workingSets: int, exerciseCount: int, blockCount: int, supersets: int, circuits: int, averageRpe: float|null, topLift: TopLift|null, regions: list<RegionShare>}
 * @phpstan-type BlockStat array{block: Block, exerciseCount: int, workingSets: int, tonnageKg: float, seconds: int}
 */
final class WorkoutMetrics
{
    public function __construct(
        private readonly WorkoutEstimator $estimator,
    ) {
    }

    /**
     * Synthèse d'en-tête d'une séance : les repères qu'on lit avant d'entrer
     * dans le détail (bandeau de KPI de la page de consultation).
     *
     * Rien n'est recalculé à la main : le tonnage et les séries de travail
     * viennent de volume(), lui-même adossé aux helpers détaillé-aware de
     * PrescribedExercise. Le RPE moyen est pondéré par les séries de travail —
     * une moyenne simple donnerait autant de poids à un exercice de 2 séries
     * qu'à un exercice de 6.
     *
     * @return WorkoutSummary
     */
    public function summary(Workout $workout): array
    {
        $volume = $this->volume($workout);

        $supersets = 0;
        $circuits = 0;
        $rpeWeighted = 0.0;
        $rpeSets = 0;
        $topWeight = null;
        $topExercise = null;
        $topSets = 0;

        foreach ($workout->getBlocks() as $block) {
            $rounds = max(1, $block->getRounds() ?? 1);
            $count = $block->getPrescribedExercises()->count();

            // Un bloc enchaîné EST un superset (2 exos) ou un circuit (3+) :
            // même convention de nommage que le rendu des blocs en lecture.
            if (2 === $count) {
                ++$supersets;
            } elseif ($count >= 3) {
                ++$circuits;
            }

            foreach ($block->getPrescribedExercises() as $pe) {
                $sets = $pe->getWorkingSetCount() * $rounds;

                if (null !== $pe->getRpe() && $sets > 0) {
                    $rpeWeighted += $pe->getRpe() * $sets;
                    $rpeSets += $sets;
                }

                $weight = $pe->getTopWeightKg();
                if (null !== $weight && (null === $topWeight || $weight > $topWeight)) {
                    $topWeight = $weight;
                    $topExercise = $pe->getExercise()?->getName();
                    $topSets = $sets;
                }
            }
        }

        return [
            'tonnageKg' => $volume['gym']['tonnageKg'],
            'workingSets' => $volume['gym']['totalSets'],
            'exerciseCount' => $this->exerciseCount($workout),
            'blockCount' => $workout->getBlocks()->count(),
            'supersets' => $supersets,
            'circuits' => $circuits,
            'averageRpe' => $rpeSets > 0 ? round($rpeWeighted / $rpeSets, 1) : null,
            'topLift' => null !== $topWeight && null !== $topExercise
                ? ['exercise' => $topExercise, 'weightKg' => $topWeight, 'sets' => $topSets]
                : null,
            'regions' => $this->regionShares($volume['gym']['setsByArea']),
        ];
    }

    /**
     * Répartition du volume de renforcement par grande région anatomique, en
     * part des séries attribuées. Voir TargetRegion : ventiler les 17 zones de
     * TargetArea donnerait une barre illisible.
     *
     * Attention, le total des séries par zone dépasse le nombre de séries
     * réelles (une série compte pour CHAQUE zone ciblée). Le pourcentage se
     * calcule donc sur ce total attribué, pas sur `totalSets`.
     *
     * @param array<string, int> $setsByArea
     *
     * @return list<RegionShare>
     */
    private function regionShares(array $setsByArea): array
    {
        $byRegion = [];
        $total = 0;

        foreach ($setsByArea as $areaValue => $sets) {
            $area = TargetArea::tryFrom($areaValue);
            if (null === $area) {
                continue;
            }

            $region = TargetRegion::of($area);
            $byRegion[$region->value] = ($byRegion[$region->value] ?? 0) + $sets;
            $total += $sets;
        }

        if (0 === $total) {
            return [];
        }

        $shares = [];
        foreach (TargetRegion::cases() as $region) {
            $sets = $byRegion[$region->value] ?? 0;
            if ($sets > 0) {
                $shares[] = [
                    'region' => $region,
                    'sets' => $sets,
                    'percent' => round($sets / $total * 100, 1),
                ];
            }
        }

        usort($shares, static fn (array $a, array $b): int => $b['sets'] <=> $a['sets']);

        return $shares;
    }

    /**
     * Statistiques par bloc, dans l'ordre du contenu : alimentent le résumé
     * d'en-tête d'accordéon (page de consultation) ET l'onglet « Analyse ».
     * La durée déléguée à WorkoutEstimator pour que la somme des blocs égale
     * exactement le total affiché en en-tête de séance.
     *
     * @return list<BlockStat>
     */
    public function blockBreakdown(Workout $workout): array
    {
        $stats = [];

        foreach ($workout->getBlocks() as $block) {
            $rounds = max(1, $block->getRounds() ?? 1);
            $sets = 0;
            $tonnage = 0.0;

            foreach ($block->getPrescribedExercises() as $pe) {
                // Même périmètre que volume() : séries et tonnage ne se comptent
                // que sur le renforcement, sinon la somme des blocs ne
                // retomberait pas sur le volume total de la séance.
                if (ActivityType::GYM !== $pe->getExercise()?->getActivity()) {
                    continue;
                }

                $sets += $pe->getWorkingSetCount() * $rounds;
                $tonnage += $pe->getTonnageKg() * $rounds;
            }

            $stats[] = [
                'block' => $block,
                'exerciseCount' => $block->getPrescribedExercises()->count(),
                'workingSets' => $sets,
                'tonnageKg' => $tonnage,
                'seconds' => $this->estimator->estimateBlockSeconds($block),
            ];
        }

        return $stats;
    }

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
                    // Séries de travail (hors échauffement) et tonnage sont dérivés
                    // par PrescribedExercise : identiques au compteur scalaire en
                    // mode simple, ventilés par ligne en mode « séries détaillées ».
                    $sets = $pe->getWorkingSetCount() * $rounds;
                    if ($sets > 0) {
                        $gymTotalSets += $sets;
                        foreach ($exercise->getTargetAreas() ?? [] as $area) {
                            $gymSetsByArea[$area->value] = ($gymSetsByArea[$area->value] ?? 0) + $sets;
                        }
                    }

                    $gymTonnage += $pe->getTonnageKg() * $rounds;

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
