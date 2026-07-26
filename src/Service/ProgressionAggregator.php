<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\PlanTemplate;
use App\Enum\ActivityType;
use App\Enum\PaceUnit;

/**
 * Lecture agrégée de la progression PRÉVUE d'un plan (lot A de
 * docs/feature-progression.md). On ne lit QUE ce qui est planifié : le fork à la
 * pose fait que chaque case porte sa propre copie de séance, donc ses propres
 * paramètres (charges/allures) — cette rampe existe déjà en base, on la trace.
 * Aucun réalisé, aucune migration.
 *
 * Consomme WorkoutMetrics (volume par séance) et UnitFormatter (formatage) :
 * jamais de reparsing ni de mise à plat réimplémentée. Les hauteurs de barres
 * (heightPct) sont précalculées ici pour garder le rendu Twig « bête ».
 *
 * @phpstan-type VolumePoint array{week: int, value: float, label: string, heightPct: int}
 * @phpstan-type VolumeSeries array{key: string, label: string, modifier: string, points: list<VolumePoint>, max: float}
 * @phpstan-type TrajPoint array{week: int, value: float|null, label: string|null, present: bool, heightPct: int}
 * @phpstan-type Trajectory array{exercise: Exercise, metric: string, metricLabel: string, lowerIsBetter: bool, points: list<TrajPoint>, min: float, max: float, weeksPresent: int, direction: string, firstLabel: string|null, lastLabel: string|null}
 */
final class ProgressionAggregator
{
    public function __construct(
        private readonly UnitFormatter $units,
        private readonly WorkoutMetrics $metrics,
    ) {
    }

    /**
     * Charge par semaine, ventilée en séries traçables (temps estimé, tonnage,
     * séries, distances par activité). Une série n'est renvoyée que si elle porte
     * au moins une valeur non nulle sur le plan. Montre la périodisation (montée
     * en charge, semaine de décharge).
     *
     * @return list<VolumeSeries>
     */
    public function weeklyVolume(PlanTemplate $template): array
    {
        $weeksCount = max(1, (int) $template->getDurationWeeks());

        $acc = [];
        for ($w = 1; $w <= $weeksCount; ++$w) {
            $acc[$w] = ['minutes' => 0, 'tonnage' => 0.0, 'sets' => 0, 'running' => 0, 'cycling' => 0, 'swimming' => 0];
        }

        foreach ($template->getPlanItems() as $item) {
            $week = $item->getWeekNumber();
            $workout = $item->getWorkout();
            if (!isset($acc[$week]) || null === $workout) {
                continue;
            }
            $vol = $this->metrics->volume($workout);
            $acc[$week]['minutes'] += (int) ($workout->getEstimatedDurationMinutes() ?? 0);
            $acc[$week]['tonnage'] += $vol['gym']['tonnageKg'];
            $acc[$week]['sets'] += $vol['gym']['totalSets'];
            $acc[$week]['running'] += $vol['running']['meters'];
            $acc[$week]['cycling'] += $vol['cycling']['meters'];
            $acc[$week]['swimming'] += $vol['swimming']['meters'];
        }

        $series = [
            $this->buildSeries($acc, 'minutes', 'Temps estimé', '', fn (float $v): string => $this->humanMinutes((int) $v)),
            $this->buildSeries($acc, 'tonnage', 'Tonnage', 'gym', fn (float $v): string => $this->units->weight($v)),
            $this->buildSeries($acc, 'sets', 'Séries', 'gym', fn (float $v): string => $this->plural((int) $v, 'série')),
            $this->buildSeries($acc, 'running', 'Course', 'run', fn (float $v): string => $this->units->distance((int) $v)),
            $this->buildSeries($acc, 'cycling', 'Vélo', '', fn (float $v): string => $this->units->distance((int) $v)),
            $this->buildSeries($acc, 'swimming', 'Natation', '', fn (float $v): string => $this->units->distance((int) $v)),
        ];

        return array_values(array_filter($series, static fn (array $s): bool => $s['max'] > 0.0));
    }

    /**
     * Trajectoire de chaque exercice récurrent (présent sur ≥ 2 semaines) à
     * travers les semaines du plan : la métrique primaire (charge, allure,
     * distance…) semaine par semaine. C'est la vue « progression » au sens strict
     * de l'athlète. Triée des exercices les plus récurrents aux moins récurrents.
     *
     * @return list<Trajectory>
     */
    public function exerciseTrajectories(PlanTemplate $template): array
    {
        $weeksCount = max(1, (int) $template->getDurationWeeks());

        // exId => ['exercise' => Exercise, 'weeks' => [week => list of ['pe', 'rounds']]]
        $byExercise = [];
        $order = [];
        foreach ($template->getPlanItems() as $item) {
            $week = $item->getWeekNumber();
            $workout = $item->getWorkout();
            if (null === $week || $week < 1 || $week > $weeksCount || null === $workout) {
                continue;
            }
            foreach ($workout->getBlocks() as $block) {
                $rounds = max(1, $block->getRounds() ?? 1);
                foreach ($block->getPrescribedExercises() as $pe) {
                    $exercise = $pe->getExercise();
                    $id = $exercise?->getId();
                    if (null === $exercise || null === $id) {
                        continue;
                    }
                    if (!isset($byExercise[$id])) {
                        $byExercise[$id] = ['exercise' => $exercise, 'weeks' => []];
                        $order[] = $id;
                    }
                    $byExercise[$id]['weeks'][$week][] = ['pe' => $pe, 'rounds' => $rounds];
                }
            }
        }

        $trajectories = [];
        foreach ($order as $id) {
            $data = $byExercise[$id];
            // Une « trajectoire » n'a de sens qu'à partir de 2 semaines distinctes.
            if (\count($data['weeks']) < 2) {
                continue;
            }
            $trajectory = $this->buildTrajectory($data['exercise'], $data['weeks'], $weeksCount);
            if (null !== $trajectory) {
                $trajectories[] = $trajectory;
            }
        }

        // Exercices les plus récurrents en tête (les plus parlants à tracer).
        usort($trajectories, static fn (array $a, array $b): int => $b['weeksPresent'] <=> $a['weeksPresent']);

        return $trajectories;
    }

    /**
     * @param array<int, array<string, float|int>> $acc
     * @param callable(float): string               $format
     *
     * @return VolumeSeries
     */
    private function buildSeries(array $acc, string $key, string $label, string $modifier, callable $format): array
    {
        $max = 0.0;
        foreach ($acc as $row) {
            $max = max($max, (float) $row[$key]);
        }

        $points = [];
        foreach ($acc as $week => $row) {
            $value = (float) $row[$key];
            $points[] = [
                'week' => $week,
                'value' => $value,
                'label' => $value > 0.0 ? $format($value) : '—',
                // Neutre : la barre représente une quantité, jamais un « mieux ».
                'heightPct' => ($max > 0.0 && $value > 0.0) ? max(6, (int) round($value / $max * 100)) : 0,
            ];
        }

        return ['key' => $key, 'label' => $label, 'modifier' => $modifier, 'points' => $points, 'max' => $max];
    }

    /**
     * @param array<int, list<array{pe: \App\Entity\PrescribedExercise, rounds: int}>> $weeks
     *
     * @return Trajectory|null
     */
    private function buildTrajectory(Exercise $exercise, array $weeks, int $weeksCount): ?array
    {
        // Métrique primaire déduite des paramètres réellement prescrits.
        $has = ['weight' => false, 'pace' => false, 'distance' => false, 'duration' => false, 'sets' => false];
        foreach ($weeks as $occurrences) {
            foreach ($occurrences as $occurrence) {
                $pe = $occurrence['pe'];
                $has['weight'] = $has['weight'] || null !== $pe->getWeightKg();
                $has['pace'] = $has['pace'] || null !== $pe->getPaceSecondsPerKm();
                $has['distance'] = $has['distance'] || null !== $pe->getDistanceMeters();
                $has['duration'] = $has['duration'] || null !== $pe->getDurationSeconds();
                $has['sets'] = $has['sets'] || null !== $pe->getSets();
            }
        }

        [$metric, $metricLabel, $lowerIsBetter] = match (true) {
            $has['weight'] => ['weight', 'Charge (top set)', false],
            $has['pace'] => ['pace', 'Allure', true],
            $has['distance'] => ['distance', 'Distance', false],
            $has['duration'] => ['duration', 'Durée', false],
            $has['sets'] => ['sets', 'Séries', false],
            default => [null, '', false],
        };
        if (null === $metric) {
            return null;
        }

        $activity = $exercise->getActivity();

        $min = null;
        $max = null;
        $present = 0;
        $points = [];
        for ($w = 1; $w <= $weeksCount; ++$w) {
            $value = isset($weeks[$w]) ? $this->weekMetric($metric, $weeks[$w]) : null;
            if (null !== $value) {
                ++$present;
                $min = null === $min ? $value : min($min, $value);
                $max = null === $max ? $value : max($max, $value);
            }
            $points[] = [
                'week' => $w,
                'value' => $value,
                'label' => null !== $value ? $this->formatMetric($metric, $value, $activity) : null,
                'present' => null !== $value,
                'heightPct' => 0, // rempli au second passage (on a besoin de min/max)
            ];
        }

        $min ??= 0.0;
        $max ??= 0.0;
        $span = $max - $min;
        foreach ($points as $i => $point) {
            if (null === $point['value']) {
                continue;
            }
            if ($span <= 0.0) {
                $points[$i]['heightPct'] = 60; // plan « plat » sur cet exo : hauteur neutre
                continue;
            }
            // Échelle entre min et max (plancher 15 %) pour faire ressortir la rampe.
            // Allure : plus bas = plus rapide, donc barre inversée (haute = mieux).
            $ratio = $lowerIsBetter ? ($max - $point['value']) / $span : ($point['value'] - $min) / $span;
            $points[$i]['heightPct'] = (int) round(15 + $ratio * 85);
        }

        // Sens de la progression : première vs dernière semaine renseignée.
        $firstValue = null;
        $lastValue = null;
        $firstLabel = null;
        $lastLabel = null;
        foreach ($points as $point) {
            if (null === $point['value']) {
                continue;
            }
            if (null === $firstValue) {
                $firstValue = $point['value'];
                $firstLabel = $point['label'];
            }
            $lastValue = $point['value'];
            $lastLabel = $point['label'];
        }
        $direction = 'flat';
        if (null !== $firstValue && null !== $lastValue && $firstValue !== $lastValue) {
            $increased = $lastValue > $firstValue;
            $direction = ($lowerIsBetter ? !$increased : $increased) ? 'up' : 'down';
        }

        return [
            'exercise' => $exercise,
            'metric' => $metric,
            'metricLabel' => $metricLabel,
            'lowerIsBetter' => $lowerIsBetter,
            'points' => $points,
            'min' => $min,
            'max' => $max,
            'weeksPresent' => $present,
            'direction' => $direction,
            'firstLabel' => $firstLabel,
            'lastLabel' => $lastLabel,
        ];
    }

    /**
     * Valeur de la métrique pour une semaine (agrégée sur toutes les occurrences
     * de l'exercice cette semaine-là). Charge/allure = meilleure occurrence (top
     * set / allure la plus rapide) ; distance/durée/séries = somme × tours de
     * bloc (aligné sur WorkoutMetrics, qui ne multiplie pas par `sets`).
     *
     * @param list<array{pe: \App\Entity\PrescribedExercise, rounds: int}> $occurrences
     */
    private function weekMetric(string $metric, array $occurrences): ?float
    {
        $result = null;
        foreach ($occurrences as $occurrence) {
            $pe = $occurrence['pe'];
            $rounds = $occurrence['rounds'];
            switch ($metric) {
                case 'weight':
                    if (null !== $pe->getWeightKg()) {
                        $result = null === $result ? $pe->getWeightKg() : max($result, $pe->getWeightKg());
                    }
                    break;
                case 'pace':
                    if (null !== $pe->getPaceSecondsPerKm()) {
                        $pace = (float) $pe->getPaceSecondsPerKm();
                        $result = null === $result ? $pace : min($result, $pace);
                    }
                    break;
                case 'distance':
                    if (null !== $pe->getDistanceMeters()) {
                        $result = ($result ?? 0.0) + $pe->getDistanceMeters() * $rounds;
                    }
                    break;
                case 'duration':
                    if (null !== $pe->getDurationSeconds()) {
                        $result = ($result ?? 0.0) + $pe->getDurationSeconds() * $rounds;
                    }
                    break;
                case 'sets':
                    if (null !== $pe->getSets()) {
                        $result = ($result ?? 0.0) + $pe->getSets() * $rounds;
                    }
                    break;
            }
        }

        return $result;
    }

    private function formatMetric(string $metric, float $value, ?ActivityType $activity): string
    {
        return match ($metric) {
            'weight' => $this->units->weight($value),
            'pace' => $this->units->pace((int) round($value), PaceUnit::forActivity($activity)),
            'distance' => $this->units->distance((int) round($value)),
            'duration' => $this->units->duration((int) round($value)),
            'sets' => $this->plural((int) $value, 'série'),
            default => (string) $value,
        };
    }

    private function plural(int $n, string $noun): string
    {
        return sprintf('%d %s%s', $n, $noun, $n > 1 ? 's' : '');
    }

    private function humanMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return 0 === $rest ? $hours.' h' : sprintf('%dh%02d', $hours, $rest);
    }
}
