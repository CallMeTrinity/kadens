<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanTemplate;
use App\Enum\ActivityType;
use App\Enum\TargetArea;

/**
 * Agrège la charge d'une trame PAR SEMAINE et PAR ACTIVITÉ, pour équilibrer d'un
 * coup d'œil : salle = séries par groupe musculaire (+ tonnage), course/vélo/
 * natation = distance (et durée). S'appuie sur WorkoutMetrics (volume par séance)
 * et UnitFormatter (mise en forme lisible). Consommé par l'éditeur de trame.
 *
 * @phpstan-type VolumeChip array{activity: ActivityType, label: string, value: string}
 * @phpstan-type GymArea array{label: string, sets: int}
 * @phpstan-type WeekVolume array{sessions: int, totalMinutes: int, totalTime: string, chips: list<VolumeChip>, gymAreas: list<GymArea>, tonnage: ?string}
 */
final class PlanVolumeAggregator
{
    /** Libellés courts par activité (le libellé long de l'enum est trop verbeux en chip). */
    private const SHORT_LABELS = [
        'gym' => 'Salle',
        'running' => 'Course',
        'cycling' => 'Vélo',
        'swimming' => 'Natation',
    ];

    public function __construct(
        private readonly WorkoutMetrics $metrics,
        private readonly UnitFormatter $units,
    ) {
    }

    /**
     * @return array<int, WeekVolume> indexé par numéro de semaine (1..durationWeeks)
     */
    public function byWeek(PlanTemplate $template): array
    {
        $durationWeeks = (int) $template->getDurationWeeks();

        // Accumulateurs bruts par semaine.
        $raw = [];
        for ($w = 1; $w <= $durationWeeks; ++$w) {
            $raw[$w] = [
                'sessions' => 0,
                'totalMinutes' => 0,
                'gymSets' => 0,
                'tonnage' => 0.0,
                'byArea' => [],
                'endurance' => [
                    'running' => ['meters' => 0, 'seconds' => 0],
                    'cycling' => ['meters' => 0, 'seconds' => 0],
                    'swimming' => ['meters' => 0, 'seconds' => 0],
                ],
            ];
        }

        foreach ($template->getPlanItems() as $item) {
            $week = $item->getWeekNumber();
            if (!isset($raw[$week])) {
                continue;
            }
            $workout = $item->getWorkout();
            $vol = $this->metrics->volume($workout);

            ++$raw[$week]['sessions'];
            $raw[$week]['totalMinutes'] += (int) ($workout->getEstimatedDurationMinutes() ?? 0);
            $raw[$week]['gymSets'] += $vol['gym']['totalSets'];
            $raw[$week]['tonnage'] += $vol['gym']['tonnageKg'];
            foreach ($vol['gym']['setsByArea'] as $area => $sets) {
                $raw[$week]['byArea'][$area] = ($raw[$week]['byArea'][$area] ?? 0) + $sets;
            }
            foreach (['running', 'cycling', 'swimming'] as $key) {
                $raw[$week]['endurance'][$key]['meters'] += $vol[$key]['meters'];
                $raw[$week]['endurance'][$key]['seconds'] += $vol[$key]['seconds'];
            }
        }

        $weeks = [];
        foreach ($raw as $week => $data) {
            $weeks[$week] = $this->finalize($data);
        }

        return $weeks;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return WeekVolume
     */
    private function finalize(array $data): array
    {
        $chips = [];

        if ($data['gymSets'] > 0) {
            $chips[] = [
                'activity' => ActivityType::GYM,
                'label' => self::SHORT_LABELS['gym'],
                'value' => sprintf('%d série%s', $data['gymSets'], $data['gymSets'] > 1 ? 's' : ''),
            ];
        }

        $enduranceActivities = [
            'running' => ActivityType::RUNNING,
            'cycling' => ActivityType::CYCLING,
            'swimming' => ActivityType::SWIMMING,
        ];
        foreach ($enduranceActivities as $key => $activity) {
            $meters = $data['endurance'][$key]['meters'];
            $seconds = $data['endurance'][$key]['seconds'];
            if ($meters > 0) {
                $value = $this->units->distance($meters);
            } elseif ($seconds > 0) {
                $value = $this->units->duration($seconds);
            } else {
                continue;
            }
            $chips[] = [
                'activity' => $activity,
                'label' => self::SHORT_LABELS[$key],
                'value' => $value,
            ];
        }

        // Séries par groupe musculaire, triées du plus au moins sollicité.
        arsort($data['byArea']);
        $gymAreas = [];
        foreach ($data['byArea'] as $areaValue => $sets) {
            $area = TargetArea::tryFrom((string) $areaValue);
            $gymAreas[] = [
                'label' => null !== $area ? $area->getLabel() : (string) $areaValue,
                'sets' => $sets,
            ];
        }

        return [
            'sessions' => $data['sessions'],
            'totalMinutes' => $data['totalMinutes'],
            'totalTime' => $this->humanMinutes($data['totalMinutes']),
            'chips' => $chips,
            'gymAreas' => $gymAreas,
            'tonnage' => $data['tonnage'] > 0 ? $this->units->weight($data['tonnage']) : null,
        ];
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
