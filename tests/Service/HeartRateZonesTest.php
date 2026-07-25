<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\IntensityZone;
use App\Service\HeartRateZones;
use PHPUnit\Framework\TestCase;

final class HeartRateZonesTest extends TestCase
{
    private HeartRateZones $zones;

    protected function setUp(): void
    {
        $this->zones = new HeartRateZones();
    }

    public function testWithoutMaxHeartRateBoundsAreNull(): void
    {
        $bands = $this->zones->forUser(new User());

        self::assertCount(5, $bands);
        self::assertSame(IntensityZone::Z1, $bands[0]['zone']);
        self::assertNull($bands[0]['min']);
        self::assertNull($bands[4]['max']);
    }

    /**
     * Karvonen : bpm = repos + pct × (max − repos). Réserve = 140 (190 − 50).
     * Les zones sont contiguës et Z5 plafonne à la FC max.
     */
    public function testKarvonenDerivation(): void
    {
        $user = (new User())->setMaxHeartRate(190)->setRestingHeartRate(50);

        $bands = $this->zones->forUser($user);

        self::assertSame(120, $bands[0]['min']); // 50 + 0.50 × 140
        self::assertSame(134, $bands[0]['max']); // 50 + 0.60 × 140
        self::assertSame(134, $bands[1]['min']); // contiguïté
        self::assertSame(176, $bands[3]['max']); // 50 + 0.90 × 140
        self::assertSame(176, $bands[4]['min']);
        self::assertSame(190, $bands[4]['max']); // plafond FC max
    }

    public function testManualOverrideShiftsOnlyItsBoundary(): void
    {
        $user = (new User())
            ->setMaxHeartRate(190)
            ->setRestingHeartRate(50)
            ->setHrZone4Max(170);

        $bands = $this->zones->forUser($user);

        self::assertSame(170, $bands[3]['max']); // override
        self::assertSame(170, $bands[4]['min']); // Z5 repart de l'override
        self::assertSame(134, $bands[0]['max']); // les autres restent dérivées
    }
}
