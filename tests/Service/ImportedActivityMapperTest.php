<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\ActivitySource;
use App\Enum\ActivityType;
use App\Service\ImportedActivityMapper;
use PHPUnit\Framework\TestCase;

final class ImportedActivityMapperTest extends TestCase
{
    public function testAGarminRunIsMappedInNormalizedUnits(): void
    {
        $activity = (new ImportedActivityMapper())->fromIntervals(new User(), [
            'id' => 'i12345',
            'source' => 'GARMIN_CONNECT',
            'type' => 'TrailRun',
            'name' => 'Sortie Moucherotte',
            'start_date_local' => '2026-09-12T23:30:00',
            'start_date' => '2026-09-12T21:30:00Z',
            'distance' => 12345.6,
            'moving_time' => 4500,
            'elapsed_time' => 4800,
            'total_elevation_gain' => 812.4,
            'average_heartrate' => 151,
            'max_heartrate' => 178,
            'average_cadence' => 83.6,
        ]);

        self::assertNotNull($activity);
        self::assertSame(ActivitySource::INTERVALS, $activity->getSource());
        self::assertSame('i12345', $activity->getExternalId());
        self::assertSame(ActivityType::RUNNING, $activity->getActivity());
        self::assertSame(12346, $activity->getDistanceMeters());
        self::assertSame(812, $activity->getElevationGainMeters());
        self::assertSame(84, $activity->getAverageCadence());
        // 4500 s / 12,346 km = 364,5 s/km.
        self::assertSame(364, $activity->getPaceSecondsPerKm());
        // Le jour LOCAL, pas le jour UTC.
        self::assertSame('2026-09-12', $activity->getLocalDate()->format('Y-m-d'));
    }

    /** Une activité arrivée par Strava n'est qu'une coquille côté API : on ne l'importe pas. */
    public function testAStravaStubIsNotImported(): void
    {
        self::assertNull((new ImportedActivityMapper())->fromIntervals(new User(), [
            'id' => 'i999',
            'source' => 'STRAVA',
            'type' => 'Run',
            'start_date_local' => '2026-09-12T08:00:00',
        ]));
    }

    /** Type inconnu : importé, mais sans activité Kadens, donc jamais rattaché automatiquement. */
    public function testAnUnknownTypeKeepsItsRawTypeWithoutActivity(): void
    {
        $activity = (new ImportedActivityMapper())->fromIntervals(new User(), [
            'id' => 'i777',
            'type' => 'WeightTraining',
            'start_date_local' => '2026-09-12T18:00:00',
        ]);

        self::assertNotNull($activity);
        self::assertNull($activity->getActivity());
        self::assertSame('WeightTraining', $activity->getSportType());
        self::assertNull($activity->getPaceSecondsPerKm());
    }
}
