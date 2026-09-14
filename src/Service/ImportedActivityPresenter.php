<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ImportedActivity;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\IntensityZone;
use App\Enum\PaceUnit;

/**
 * Met une activité importée en mots pour la page d'une séance datée. Tout le
 * formatage passe par `UnitFormatter` et `PaceUnit` : une allure de vélo se lit
 * en km/h, de natation en min/100m, comme partout ailleurs dans l'app.
 *
 * L'écart **prévu vs réel** se calcule sur le volume d'endurance de
 * `WorkoutMetrics::volume()`, la même lecture du prescrit que les statistiques :
 * la page et `/profile/stats` ne peuvent pas dire deux « prévus » différents.
 */
final class ImportedActivityPresenter
{
    public function __construct(
        private readonly UnitFormatter $units,
        private readonly WorkoutMetrics $metrics,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ImportedActivity $activity): array
    {
        $paceUnit = PaceUnit::forActivity($activity->getActivity());
        $pace = $activity->getPaceSecondsPerKm();

        return [
            'entity' => $activity,
            'title' => $activity->getName() ?? $activity->getSportType(),
            'sport' => $activity->getActivity()?->getLabel() ?? $activity->getSportType(),
            'startedAt' => $activity->getStartedAt(),
            'distance' => null !== $activity->getDistanceMeters() ? $this->units->distance($activity->getDistanceMeters()) : null,
            'duration' => null !== $activity->getMovingSeconds() ? $this->units->duration($activity->getMovingSeconds()) : null,
            'pace' => null !== $pace ? $this->units->pace($pace, $paceUnit) : null,
            'averageHeartRate' => $activity->getAverageHeartRate(),
            'maxHeartRate' => $activity->getMaxHeartRate(),
            'elevationGain' => $activity->getElevationGainMeters(),
            'cadence' => $activity->getAverageCadence(),
            'watts' => $activity->getAverageWatts(),
            'zones' => $this->zones($activity->getHrZoneSeconds()),
            'splits' => $this->splits($activity->getSplits(), $paceUnit),
        ];
    }

    /**
     * Prévu contre réel, par activité d'endurance présente des deux côtés. Rien
     * quand la séance n'a pas de prescrit chiffré pour cette activité : un écart
     * contre zéro ne dit rien.
     *
     * @param list<ImportedActivity> $activities
     *
     * @return list<array{activity: ActivityType, plannedDistance: ?string, actualDistance: ?string, distanceDelta: ?string, plannedDuration: ?string, actualDuration: ?string, durationDelta: ?string}>
     */
    public function comparison(?Workout $workout, array $activities): array
    {
        if (null === $workout || [] === $activities) {
            return [];
        }

        $volume = $this->metrics->volume($workout);
        $rows = [];

        foreach ([ActivityType::RUNNING, ActivityType::CYCLING, ActivityType::SWIMMING] as $type) {
            $planned = $volume[$type->value] ?? null;
            $mine = array_filter($activities, static fn (ImportedActivity $a): bool => $type === $a->getActivity());

            if (null === $planned || [] === $mine || (0 === $planned['meters'] && 0 === $planned['seconds'])) {
                continue;
            }

            $meters = array_sum(array_map(static fn (ImportedActivity $a): int => $a->getDistanceMeters() ?? 0, $mine));
            $seconds = array_sum(array_map(static fn (ImportedActivity $a): int => $a->getMovingSeconds() ?? 0, $mine));

            $rows[] = [
                'activity' => $type,
                'plannedDistance' => $planned['meters'] > 0 ? $this->units->distance($planned['meters']) : null,
                'actualDistance' => $planned['meters'] > 0 ? $this->units->distance($meters) : null,
                'distanceDelta' => $planned['meters'] > 0 ? $this->signed($meters - $planned['meters'], fn (int $v): string => $this->units->distance($v)) : null,
                'plannedDuration' => $planned['seconds'] > 0 ? $this->units->duration($planned['seconds']) : null,
                'actualDuration' => $planned['seconds'] > 0 ? $this->units->duration($seconds) : null,
                'durationDelta' => $planned['seconds'] > 0 ? $this->signed($seconds - $planned['seconds'], fn (int $v): string => $this->units->duration($v)) : null,
            ];
        }

        return $rows;
    }

    /**
     * @param list<int>|null $seconds
     *
     * @return list<array{zone: IntensityZone, duration: string, percent: int}>
     */
    private function zones(?array $seconds): array
    {
        if (null === $seconds || 0 === array_sum($seconds)) {
            return [];
        }

        $total = array_sum($seconds);
        $zones = [];

        foreach (IntensityZone::cases() as $index => $zone) {
            $value = (int) ($seconds[$index] ?? 0);
            $zones[] = [
                'zone' => $zone,
                'duration' => $this->units->duration($value),
                'percent' => (int) round($value * 100 / $total),
            ];
        }

        return $zones;
    }

    /**
     * @param list<array{meters: int, seconds: int, averageHeartRate: ?int, elevationGain: ?int}>|null $splits
     *
     * @return list<array{distance: string, duration: string, pace: ?string, averageHeartRate: ?int, elevationGain: ?int}>
     */
    private function splits(?array $splits, PaceUnit $unit): array
    {
        $rows = [];

        foreach ($splits ?? [] as $split) {
            $pace = $split['meters'] > 0 && $split['seconds'] > 0 ? (int) round($split['seconds'] * 1000 / $split['meters']) : null;

            $rows[] = [
                'distance' => $this->units->distance($split['meters']),
                'duration' => $this->units->duration($split['seconds']),
                'pace' => null !== $pace ? $this->units->pace($pace, $unit) : null,
                'averageHeartRate' => $split['averageHeartRate'],
                'elevationGain' => $split['elevationGain'],
            ];
        }

        return $rows;
    }

    /**
     * @param callable(int): string $format
     */
    private function signed(int $delta, callable $format): string
    {
        if (0 === $delta) {
            return '=';
        }

        return ($delta > 0 ? '+' : '−').$format(abs($delta));
    }
}
