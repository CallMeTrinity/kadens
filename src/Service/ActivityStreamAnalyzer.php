<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * Tire des séries temporelles d'une activité ce que le résumé de la source ne
 * dit pas dans les termes de Kadens : les **splits au kilomètre** et le **temps
 * passé dans chaque zone cardio**.
 *
 * **Les zones sont celles du profil Kadens**, via `HeartRateZones`, pas celles
 * réglées dans Intervals. C'est avec elles que les séances sont prescrites
 * (« 40 min en Z2 ») : comparer le réalisé à d'autres bornes que celles de la
 * consigne ferait dire à la page ce que la consigne ne disait pas. Conséquence
 * assumée : le calcul est figé à l'import.
 *
 * Deux conventions, écrites ici parce qu'elles changent les chiffres :
 * - un écart de plus de `MAX_GAP_SECONDS` entre deux échantillons est une
 *   **pause** (montre arrêtée) : il ne compte dans aucune zone ;
 * - sous la borne basse de Z1, on compte en Z1 : c'est de la récupération, pas
 *   du temps hors séance.
 */
final class ActivityStreamAnalyzer
{
    /** Types de streams à demander à la source pour cette analyse. */
    public const array STREAM_TYPES = ['time', 'distance', 'heartrate', 'altitude'];

    private const int MAX_GAP_SECONDS = 30;

    /** Un reliquat de fin plus court n'est pas un split, c'est le retour au parking. */
    private const int MIN_LAST_SPLIT_METERS = 100;

    public function __construct(private readonly HeartRateZones $zones)
    {
    }

    /**
     * @param array<string, list<int|float|null>> $streams
     *
     * @return array{splits: list<array{meters: int, seconds: int, averageHeartRate: ?int, elevationGain: ?int}>|null, hrZoneSeconds: list<int>|null}
     */
    public function analyze(User $user, array $streams): array
    {
        $time = $streams['time'] ?? [];

        if (\count($time) < 2) {
            return ['splits' => null, 'hrZoneSeconds' => null];
        }

        return [
            'splits' => $this->splits($time, $streams['distance'] ?? [], $streams['heartrate'] ?? [], $streams['altitude'] ?? []),
            'hrZoneSeconds' => $this->zoneSeconds($user, $time, $streams['heartrate'] ?? []),
        ];
    }

    /**
     * @param list<int|float|null> $time
     * @param list<int|float|null> $heartRate
     *
     * @return list<int>|null
     */
    private function zoneSeconds(User $user, array $time, array $heartRate): ?array
    {
        $bands = $this->zones->forUser($user);

        if ([] === $heartRate || null === $bands[0]['max']) {
            return null;
        }

        $seconds = array_fill(0, \count($bands), 0);
        $counted = false;

        for ($i = 1, $n = min(\count($time), \count($heartRate)); $i < $n; ++$i) {
            $dt = (float) $time[$i] - (float) $time[$i - 1];
            $bpm = $heartRate[$i];

            if ($dt <= 0 || $dt > self::MAX_GAP_SECONDS || null === $bpm || $bpm <= 0) {
                continue;
            }

            $seconds[$this->zoneIndex($bands, (float) $bpm)] += $dt;
            $counted = true;
        }

        return $counted ? array_map(static fn (float|int $s): int => (int) round($s), $seconds) : null;
    }

    /**
     * @param list<array{zone: mixed, min: ?int, max: ?int}> $bands
     */
    private function zoneIndex(array $bands, float $bpm): int
    {
        $last = \count($bands) - 1;

        foreach ($bands as $index => $band) {
            if ($index === $last || $bpm < (float) $band['max']) {
                return $index;
            }
        }

        return $last;
    }

    /**
     * @param list<int|float|null> $time
     * @param list<int|float|null> $distance
     * @param list<int|float|null> $heartRate
     * @param list<int|float|null> $altitude
     *
     * @return list<array{meters: int, seconds: int, averageHeartRate: ?int, elevationGain: ?int}>|null
     */
    private function splits(array $time, array $distance, array $heartRate, array $altitude): ?array
    {
        $n = min(\count($time), \count($distance));

        if ($n < 2) {
            return null;
        }

        $splits = [];
        $startIndex = 0;
        $startMeters = 0.0;
        $nextMark = 1000.0;

        $close = function (int $from, int $to, float $meters) use ($time, $heartRate, $altitude): array {
            $hrSum = 0.0;
            $hrCount = 0;
            $gain = 0.0;
            $hasAltitude = false;

            for ($i = $from + 1; $i <= $to; ++$i) {
                if (isset($heartRate[$i]) && $heartRate[$i] > 0) {
                    $hrSum += (float) $heartRate[$i];
                    ++$hrCount;
                }

                if (isset($altitude[$i], $altitude[$i - 1])) {
                    $hasAltitude = true;
                    $gain += max(0.0, (float) $altitude[$i] - (float) $altitude[$i - 1]);
                }
            }

            return [
                'meters' => (int) round($meters),
                'seconds' => (int) round((float) $time[$to] - (float) $time[$from]),
                'averageHeartRate' => $hrCount > 0 ? (int) round($hrSum / $hrCount) : null,
                'elevationGain' => $hasAltitude ? (int) round($gain) : null,
            ];
        };

        for ($i = 1; $i < $n; ++$i) {
            if (null === $distance[$i] || null === $time[$i]) {
                continue;
            }

            if ((float) $distance[$i] >= $nextMark) {
                $splits[] = $close($startIndex, $i, (float) $distance[$i] - $startMeters);
                $startIndex = $i;
                $startMeters = (float) $distance[$i];
                $nextMark = (floor($startMeters / 1000) + 1) * 1000;
            }
        }

        $lastIndex = $n - 1;
        $remaining = (float) ($distance[$lastIndex] ?? $startMeters) - $startMeters;

        if ($lastIndex > $startIndex && $remaining >= self::MIN_LAST_SPLIT_METERS) {
            $splits[] = $close($startIndex, $lastIndex, $remaining);
        }

        return [] === $splits ? null : $splits;
    }
}
