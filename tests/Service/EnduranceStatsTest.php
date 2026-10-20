<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\ActivityType;
use App\Repository\ImportedActivityRepository;
use App\Service\EnduranceStats;
use App\Service\UnitFormatter;
use App\Service\ZoneBreakdown;
use PHPUnit\Framework\TestCase;

/**
 * Ce que l'onglet Endurance dit du réel importé.
 *
 * Service pur : les lignes sont écrites à la main, telles que
 * `ImportedActivityRepository::enduranceRowsForOwner()` les rend. Ce qui se
 * joue ici, ce sont des définitions — une allure moyenne pondérée, un record
 * qui exige une distance minimale, un kilomètre qui en fait un — qu'une
 * réécriture distraite changerait sans qu'aucun chiffre n'ait l'air faux.
 *
 * @phpstan-import-type EnduranceRow from ImportedActivityRepository
 */
final class EnduranceStatsTest extends TestCase
{
    private EnduranceStats $stats;
    private int $nextId = 1;

    protected function setUp(): void
    {
        $units = new UnitFormatter();
        $this->stats = new EnduranceStats($units, new ZoneBreakdown($units));
    }

    /**
     * 10 km en 50 min (5:00/km) et 2 km en 8 min (4:00/km) : 58 min pour 12 km,
     * soit 4:50/km. La moyenne des deux allures dirait 4:30, comme si les deux
     * kilomètres du fractionné pesaient autant que la sortie longue.
     */
    public function testRealPaceIsWeightedByDistanceNotAveraged(): void
    {
        $summary = $this->over([
            $this->row(ActivityType::RUNNING, '2026-07-01', 10000, 3000),
            $this->row(ActivityType::RUNNING, '2026-07-03', 2000, 480),
        ])['summary'];

        self::assertSame('4:50/km', $summary['running']['paceLabel']);
        self::assertSame(2, $summary['running']['outings']);
        self::assertArrayNotHasKey('cycling', $summary, 'Un sport sans sortie n\'a pas de bilan.');
    }

    /** Une sortie sans distance (home-trainer, montre sans GPS) n'entre pas dans l'allure. */
    public function testAnOutingWithoutDistanceDoesNotSkewThePace(): void
    {
        $summary = $this->over([
            $this->row(ActivityType::RUNNING, '2026-07-01', 5000, 1500),
            $this->row(ActivityType::RUNNING, '2026-07-02', null, 3600),
        ])['summary'];

        self::assertSame('5:00/km', $summary['running']['paceLabel']);
        self::assertSame(5100, $summary['running']['seconds']);
    }

    /** Le D+ d'une piscine ne dit rien : ni au bilan, ni en record. */
    public function testElevationIsIgnoredForSwimming(): void
    {
        $result = $this->over([
            $this->row(ActivityType::SWIMMING, '2026-07-01', 2000, 2400, elevation: 12),
        ]);

        self::assertNull($result['summary']['swimming']['elevationLabel']);
        self::assertNotContains('Plus gros D+', array_column($result['records'], 'label'));
    }

    /**
     * Le record d'allure exige 5 km en course : le 3 km à 4:00/km est plus
     * rapide, mais c'est le 6 km à 5:00/km qui le porte.
     */
    public function testPaceRecordIgnoresOutingsBelowTheThreshold(): void
    {
        $records = $this->over([
            $this->row(ActivityType::RUNNING, '2026-07-01', 3000, 720),
            $this->row(ActivityType::RUNNING, '2026-07-05', 6000, 1800),
        ])['records'];

        $pace = $this->record($records, 'Meilleure allure (≥ 5 km)');
        self::assertSame('5:00/km', $pace['value']);
        self::assertSame('sur 6 km', $pace['note']);
        self::assertSame('2026-07-05', $pace['date']->format('Y-m-d'));
    }

    /** À égalité, la sortie la plus ancienne garde le record : c'est elle qui l'a établi. */
    public function testATieKeepsTheEarliestOuting(): void
    {
        $records = $this->over([
            $this->row(ActivityType::CYCLING, '2026-07-01', 40000, 4800),
            $this->row(ActivityType::CYCLING, '2026-07-08', 40000, 4000),
        ])['records'];

        self::assertSame('2026-07-01', $this->record($records, 'Plus longue sortie')['date']->format('Y-m-d'));
        // Le vélo se lit en vitesse : 40 km en 1:06:40, 36 km/h.
        self::assertSame('36 km/h', $this->record($records, 'Meilleure vitesse (≥ 20 km)')['value']);
    }

    /**
     * Le dernier split, plus court qu'un kilomètre, ne concourt pas : 400 m à
     * 3:20/km battraient sinon tous les vrais kilomètres. Le meilleur est le
     * deuxième, 1003 m en 4:20, soit 4:19/km.
     */
    public function testBestKilometreOnlyCountsFullSplits(): void
    {
        $records = $this->over([
            $this->row(ActivityType::RUNNING, '2026-07-01', 2403, 610, splits: [
                ['meters' => 1000, 'seconds' => 270, 'averageHeartRate' => null, 'elevationGain' => null],
                ['meters' => 1003, 'seconds' => 260, 'averageHeartRate' => null, 'elevationGain' => null],
                ['meters' => 400, 'seconds' => 80, 'averageHeartRate' => null, 'elevationGain' => null],
            ]),
        ])['records'];

        self::assertSame('4:19/km', $this->record($records, 'Meilleur kilomètre')['value']);
    }

    /** Un split illisible (JSON abîmé) se saute, il ne casse pas la page. */
    public function testAMalformedSplitIsSkipped(): void
    {
        $records = $this->over([
            $this->row(ActivityType::RUNNING, '2026-07-01', 1000, 300, splits: ['n\'importe quoi', ['meters' => 'x']]),
        ])['records'];

        self::assertNotContains('Meilleur kilomètre', array_column($records, 'label'));
    }

    /**
     * Les zones se somment sur les seules sorties qui en portent. Deux sorties
     * sur quatre ici : 60 s en Z1, 300 en Z2, 240 en Z3, soit 60 % de facile.
     */
    public function testZonesSumOnlyTheOutingsWithHeartRate(): void
    {
        $zones = $this->over([
            $this->row(ActivityType::RUNNING, '2026-07-01', 5000, 1500, zones: [60, 240, 0, 0, 0]),
            $this->row(ActivityType::RUNNING, '2026-07-02', 5000, 1500),
            $this->row(ActivityType::RUNNING, '2026-07-03', 5000, 1500, zones: [0, 0, 0, 0, 0]),
            $this->row(ActivityType::CYCLING, '2026-07-04', 20000, 2400, zones: [0, 60, 240, 0, 0]),
        ])['zones'];

        self::assertNotNull($zones);
        self::assertSame(2, $zones['outings']);
        self::assertSame(60, $zones['easyPercent']);
        self::assertSame([60, 300, 240, 0, 0], array_column($zones['shares'], 'seconds'));
        self::assertSame([10, 50, 40, 0, 0], array_column($zones['shares'], 'percent'));
    }

    /** Sans aucune seconde en zone, le bloc n'a rien à dire : null, pas cinq barres vides. */
    public function testZonesAreNullWithoutHeartRate(): void
    {
        self::assertNull($this->over([$this->row(ActivityType::RUNNING, '2026-07-01', 5000, 1500)])['zones']);
    }

    /**
     * La distance ne se compare que là où une distance était prescrite. Ici
     * 10 km prescrits sans durée (déduite de l'allure : 50 min), courus en
     * 10,5 km : +500 m, +5 %. Pas de ligne de durée — la durée déduite n'est pas
     * une consigne — mais l'allure, elle, se compare : 5:00/km de part et d'autre.
     * La séance sans prescrit chiffré ne compte pas.
     */
    public function testComparisonOnlyComparesWhatWasPrescribed(): void
    {
        $comparison = $this->stats->over([], [
            [
                'activity' => ActivityType::RUNNING,
                'planned' => ['meters' => 10000, 'seconds' => 0, 'derivedMeters' => 0, 'derivedSeconds' => 3000],
                'actual' => ['meters' => 10500, 'seconds' => 3150],
            ],
            [
                'activity' => ActivityType::RUNNING,
                'planned' => ['meters' => 0, 'seconds' => 0, 'derivedMeters' => 0, 'derivedSeconds' => 0],
                'actual' => ['meters' => 8000, 'seconds' => 2400],
            ],
        ], ['sessions' => 2, 'withActivity' => 2, 'unattached' => 0])['comparison'];

        self::assertCount(1, $comparison);
        $running = $comparison[0];
        self::assertSame(1, $running['sessions']);
        self::assertNotNull($running['distance']);
        self::assertSame('10 km', $running['distance']['planned']);
        self::assertSame('10,5 km', $running['distance']['actual']);
        self::assertSame('+500 m', $running['distance']['delta']);
        self::assertSame(5, $running['distance']['percent']);
        self::assertNull($running['duration']);
        self::assertNotNull($running['pace']);
        self::assertSame('5:00/km', $running['pace']['planned']);
        self::assertSame('5:00/km', $running['pace']['actual']);
    }

    // ------------------------------------------------------------- fixtures

    /**
     * @param list<EnduranceRow> $rows
     *
     * @return array<string, mixed>
     */
    private function over(array $rows): array
    {
        return $this->stats->over($rows, [], ['sessions' => \count($rows), 'withActivity' => \count($rows), 'unattached' => 0]);
    }

    /**
     * @param list<mixed>|null $splits
     * @param list<mixed>|null $zones
     *
     * @return EnduranceRow
     */
    private function row(ActivityType $activity, string $date, ?int $meters, ?int $seconds, ?int $elevation = null, ?array $splits = null, ?array $zones = null): array
    {
        $id = $this->nextId++;

        return [
            'id' => $id,
            'scheduledId' => 100 + $id,
            'date' => new \DateTimeImmutable($date),
            'activity' => $activity,
            'meters' => $meters,
            'seconds' => $seconds,
            'elevation' => $elevation,
            'splits' => $splits,
            'zones' => $zones,
        ];
    }

    /**
     * @param list<array{label: string, value: string, note: string|null, date: \DateTimeImmutable}> $records
     *
     * @return array{label: string, value: string, note: string|null, date: \DateTimeImmutable}
     */
    private function record(array $records, string $label): array
    {
        foreach ($records as $record) {
            if ($label === $record['label']) {
                return $record;
            }
        }

        self::fail(sprintf('Aucun record « %s ».', $label));
    }
}
