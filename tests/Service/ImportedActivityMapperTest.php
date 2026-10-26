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

    /**
     * L'échelle `feel` d'Intervals est à l'envers de celle de Kadens (1 =
     * « Strong ») ; `icu_rpe` est à l'endroit. Les dérivés et le champ Strava ne
     * sont pas des notes de séance.
     */
    public function testTheRatingIsFlippedOntoTheKadensScale(): void
    {
        self::assertSame(['feeling' => 5, 'sessionRpe' => 8], ImportedActivityMapper::ratingsFromIntervals(['feel' => 1, 'icu_rpe' => 8, 'session_rpe' => 480, 'perceived_exertion' => 3.0]));
        self::assertSame(['feeling' => 1, 'sessionRpe' => 1], ImportedActivityMapper::ratingsFromIntervals(['feel' => 5, 'icu_rpe' => 1]));
        self::assertSame(['feeling' => 3, 'sessionRpe' => null], ImportedActivityMapper::ratingsFromIntervals(['feel' => 3]));
    }

    /** Hors échelle : rien, plutôt qu'une note ramenée au bord qui fausserait une moyenne. */
    public function testARatingOutOfScaleIsDropped(): void
    {
        self::assertSame(['feeling' => null, 'sessionRpe' => null], ImportedActivityMapper::ratingsFromIntervals(['feel' => 7, 'icu_rpe' => 12]));
        self::assertSame(['feeling' => null, 'sessionRpe' => null], ImportedActivityMapper::ratingsFromIntervals(['feel' => null, 'icu_rpe' => 0]));
        self::assertSame(['feeling' => null, 'sessionRpe' => null], ImportedActivityMapper::ratingsFromIntervals([]));
    }

    public function testANewActivityCarriesItsRating(): void
    {
        $activity = (new ImportedActivityMapper())->fromIntervals(new User(), [
            'id' => 'i77',
            'type' => 'Run',
            'start_date_local' => '2026-09-12T08:00:00',
            'feel' => 2,
            'icu_rpe' => 6,
        ]);

        self::assertNotNull($activity);
        self::assertSame(4, $activity->getFeeling());
        self::assertSame(6, $activity->getSessionRpe());
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
