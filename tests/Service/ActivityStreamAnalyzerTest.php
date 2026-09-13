<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\ActivityStreamAnalyzer;
use App\Service\HeartRateZones;
use PHPUnit\Framework\TestCase;

final class ActivityStreamAnalyzerTest extends TestCase
{
    private ActivityStreamAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new ActivityStreamAnalyzer(new HeartRateZones());
    }

    /**
     * FC max 190, repos 50 : Z1 [120, 134[, Z2 [134, 148[, Z3 [148, 162[,
     * Z4 [162, 176[, Z5 [176, 190]. Dix secondes à 140 (Z2), dix à 170 (Z4).
     */
    public function testTimeInZonesUsesTheKadensProfileZones(): void
    {
        $user = (new User())->setMaxHeartRate(190)->setRestingHeartRate(50);

        $time = range(0, 20);
        $hr = array_merge([140], array_fill(0, 10, 140), array_fill(0, 10, 170));

        $result = $this->analyzer->analyze($user, ['time' => $time, 'heartrate' => $hr]);

        self::assertSame([0, 10, 0, 10, 0], $result['hrZoneSeconds']);
    }

    /** Un trou de plus de 30 s est une pause : il ne compte dans aucune zone. */
    public function testAPauseCountsInNoZone(): void
    {
        $user = (new User())->setMaxHeartRate(190)->setRestingHeartRate(50);

        $result = $this->analyzer->analyze($user, [
            'time' => [0, 5, 300, 305],
            'heartrate' => [140, 140, 140, 140],
        ]);

        self::assertSame(10, array_sum($result['hrZoneSeconds']));
    }

    public function testWithoutMaxHeartRateThereAreNoZones(): void
    {
        $result = $this->analyzer->analyze(new User(), ['time' => [0, 1, 2], 'heartrate' => [140, 141, 142]]);

        self::assertNull($result['hrZoneSeconds']);
    }

    /**
     * 2,5 km à 5 m/s, un échantillon par seconde : deux splits pleins de 200 s,
     * et un reliquat de 500 m gardé (au-dessus du seuil de 100 m).
     */
    public function testSplitsAreCutEveryKilometreWithTheRemainderKept(): void
    {
        $time = range(0, 500);
        $distance = array_map(static fn (int $t): int => $t * 5, $time);

        $splits = $this->analyzer->analyze(new User(), ['time' => $time, 'distance' => $distance])['splits'];

        self::assertCount(3, $splits);
        self::assertSame(1000, $splits[0]['meters']);
        self::assertSame(200, $splits[0]['seconds']);
        self::assertSame(200, $splits[1]['seconds']);
        self::assertSame(500, $splits[2]['meters']);
        self::assertNull($splits[0]['averageHeartRate']);
    }

    public function testSplitsCarryAverageHeartRateAndPositiveElevationOnly(): void
    {
        $time = range(0, 200);
        $distance = array_map(static fn (int $t): int => $t * 5, $time);
        $hr = array_fill(0, 201, 150);
        // Monte de 10 m puis redescend de 10 m : le D+ vaut 10, pas 0.
        $altitude = array_map(static fn (int $t): float => $t <= 100 ? $t / 10 : 20 - $t / 10, $time);

        $splits = $this->analyzer->analyze(new User(), ['time' => $time, 'distance' => $distance, 'heartrate' => $hr, 'altitude' => $altitude])['splits'];

        self::assertSame(150, $splits[0]['averageHeartRate']);
        self::assertSame(10, $splits[0]['elevationGain']);
    }
}
