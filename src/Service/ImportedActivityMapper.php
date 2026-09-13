<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ImportedActivity;
use App\Entity\User;
use App\Enum\ActivitySource;
use App\Enum\ActivityType;

/**
 * Traduit une activité Intervals.icu en `ImportedActivity`. Aucune règle métier
 * ici, seulement des unités et des noms de champs : ce qui est rattaché, et à
 * quoi, se décide ailleurs (`ActivityMatcher`).
 */
final class ImportedActivityMapper
{
    /**
     * Types de la source reconnus comme une activité d'endurance Kadens. Tout le
     * reste est importé avec `activity = null` : visible, jamais rattaché
     * automatiquement (une séance de renfo enregistrée à la montre n'est pas le
     * réalisé d'une sortie course).
     */
    private const array TYPES = [
        'Run' => ActivityType::RUNNING,
        'TrailRun' => ActivityType::RUNNING,
        'VirtualRun' => ActivityType::RUNNING,
        'Ride' => ActivityType::CYCLING,
        'GravelRide' => ActivityType::CYCLING,
        'MountainBikeRide' => ActivityType::CYCLING,
        'VirtualRide' => ActivityType::CYCLING,
        'EBikeRide' => ActivityType::CYCLING,
        'EMountainBikeRide' => ActivityType::CYCLING,
        'Swim' => ActivityType::SWIMMING,
        'OpenWaterSwim' => ActivityType::SWIMMING,
    ];

    /**
     * Null quand l'activité est inexploitable : identifiant ou dates manquants,
     * ou activité **arrivée par Strava**. Intervals n'expose pas celles-là par son
     * API (conditions de Strava) et n'en rend qu'une coquille vide ; l'importer
     * créerait une sortie à zéro kilomètre.
     *
     * @param array<string, mixed> $payload
     */
    public function fromIntervals(User $owner, array $payload): ?ImportedActivity
    {
        $id = $payload['id'] ?? null;
        $startLocal = $payload['start_date_local'] ?? null;

        if (!\is_scalar($id) || '' === (string) $id || !\is_string($startLocal) || self::isStravaStub($payload)) {
            return null;
        }

        try {
            $localDate = new \DateTimeImmutable(substr($startLocal, 0, 10));
            // `start_date` est en UTC (« …Z ») ; repli sur l'heure locale sans
            // fuseau quand il manque, faute de mieux.
            $startedAt = isset($payload['start_date']) && \is_string($payload['start_date'])
                ? (new \DateTimeImmutable($payload['start_date']))->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                : new \DateTimeImmutable($startLocal);
        } catch (\Exception) {
            return null;
        }

        $sportType = \is_string($payload['type'] ?? null) ? $payload['type'] : 'Other';

        $activity = new ImportedActivity($owner, ActivitySource::INTERVALS, (string) $id, $sportType, $startedAt, $localDate);

        return $activity
            ->setActivity(self::TYPES[$sportType] ?? null)
            ->setName(\is_string($payload['name'] ?? null) ? mb_substr($payload['name'], 0, 255) : null)
            ->setDistanceMeters(self::int($payload['distance'] ?? $payload['icu_distance'] ?? null))
            ->setMovingSeconds(self::int($payload['moving_time'] ?? null))
            ->setElapsedSeconds(self::int($payload['elapsed_time'] ?? null))
            ->setElevationGainMeters(self::int($payload['total_elevation_gain'] ?? null))
            ->setAverageHeartRate(self::int($payload['average_heartrate'] ?? null))
            ->setMaxHeartRate(self::int($payload['max_heartrate'] ?? null))
            ->setAverageCadence(self::int($payload['average_cadence'] ?? null))
            ->setAverageWatts(self::int($payload['icu_average_watts'] ?? null));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function isStravaStub(array $payload): bool
    {
        return 'STRAVA' === ($payload['source'] ?? null);
    }

    /** Arrondi à l'unité ; null pour une valeur absente, non numérique ou nulle. */
    private static function int(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $rounded = (int) round((float) $value);

        return $rounded > 0 ? $rounded : null;
    }
}
