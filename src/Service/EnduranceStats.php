<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ActivityType;
use App\Enum\PaceUnit;
use App\Repository\ImportedActivityRepository;

/**
 * Ce que l'onglet Endurance de `/profile/stats` dit d'une fenêtre, au-delà du
 * volume : allure réelle, D+, temps en zones, records, prévu contre réel.
 *
 * Un collaborateur de `TrainingStats`, comme `RegionBreakdown` : le moteur reste
 * unique (`TrainingStats::over()` le compose), ce service ne fait que mettre en
 * forme ce que la passe d'endurance lui donne. Il n'interroge rien.
 *
 * **Tout ce qui sort d'ici vient du RÉEL IMPORTÉ**, et seulement des sorties
 * rattachées à une séance faite. Le prescrit ne porte ni FC, ni D+, ni allure
 * tenue : il ne peut pas remplir ces blocs, et le volume (qui, lui, se replie
 * sur le prescrit) n'en est pas la base. D'où les compteurs `outings` partout :
 * l'écran dit sur combien de sorties un chiffre repose.
 *
 * @phpstan-import-type EnduranceRow from ImportedActivityRepository
 * @phpstan-import-type ZoneShare from ZoneBreakdown
 *
 * @phpstan-type PlannedVolume array{meters: int, seconds: int, derivedMeters: int, derivedSeconds: int}
 * @phpstan-type ActualVolume array{meters: int, seconds: int}
 * @phpstan-type EndurancePair array{activity: ActivityType, planned: PlannedVolume, actual: ActualVolume}
 * @phpstan-type EnduranceCoverage array{sessions: int, withActivity: int, unattached: int}
 * @phpstan-type EnduranceSummary array{activity: ActivityType, outings: int, meters: int, seconds: int, elevation: int, paceLabel: string|null, elevationLabel: string|null}
 * @phpstan-type EnduranceZones array{shares: list<ZoneShare>, outings: int, easyPercent: int, durationLabel: string}
 * @phpstan-type EnduranceRecord array{activity: ActivityType, label: string, value: string, note: string|null, date: \DateTimeImmutable, scheduledId: int}
 * @phpstan-type ComparedMeasure array{planned: string, actual: string, delta: string, percent: int, sessions: int}
 * @phpstan-type EnduranceComparison array{activity: ActivityType, sessions: int, distance: ComparedMeasure|null, duration: ComparedMeasure|null, pace: array{planned: string, actual: string, sessions: int}|null}
 */
final class EnduranceStats
{
    /** Les sports dont on parle, dans l'ordre d'affichage. */
    public const SPORTS = [ActivityType::RUNNING, ActivityType::CYCLING, ActivityType::SWIMMING];

    /**
     * Distance minimale d'une sortie pour concourir à la meilleure allure. Sans
     * seuil, le record serait un sprint de 400 m ou un aller-retour à la
     * boulangerie à vélo : vrai, mais sans rien dire de la forme.
     */
    private const PACE_RECORD_MIN_METERS = [
        'running' => 5000,
        'cycling' => 20000,
        'swimming' => 1000,
    ];

    /**
     * Un split ne concourt au meilleur kilomètre que s'il fait au moins un
     * kilomètre : le dernier, plus court, n'est pas comparable. Les splits
     * comptent le temps de pause qu'ils contiennent (limite connue de l'import) :
     * le record est donc prudent, jamais flatteur.
     */
    private const FULL_SPLIT_METERS = 1000;

    public function __construct(
        private readonly UnitFormatter $units,
        private readonly ZoneBreakdown $zones,
    ) {
    }

    /**
     * @param list<EnduranceRow>    $rows     les sorties importées rattachées aux séances faites de la fenêtre
     * @param list<EndurancePair>   $pairs    par séance et par sport, le prescrit et le réel quand les deux existent
     * @param EnduranceCoverage     $coverage
     *
     * @return array{summary: array<string, EnduranceSummary>, zones: EnduranceZones|null, records: list<EnduranceRecord>, comparison: list<EnduranceComparison>, coverage: EnduranceCoverage}
     */
    public function over(array $rows, array $pairs, array $coverage): array
    {
        return [
            'summary' => $this->summary($rows),
            'zones' => $this->zones($rows),
            'records' => $this->records($rows),
            'comparison' => $this->comparison($pairs),
            'coverage' => $coverage,
        ];
    }

    /**
     * Par sport : sorties importées, D+ cumulé et allure réelle. L'allure est
     * Σtemps ÷ Σdistance sur les sorties qui portent les deux, jamais la moyenne
     * des allures : une sortie de 3 km ne pèse pas autant qu'une de 30.
     *
     * @param list<EnduranceRow> $rows
     *
     * @return array<string, EnduranceSummary> indexé par valeur d'ActivityType, sports sans sortie omis
     */
    private function summary(array $rows): array
    {
        $summary = [];

        foreach (self::SPORTS as $sport) {
            $mine = $this->of($rows, $sport);
            if ([] === $mine) {
                continue;
            }

            $meters = 0;
            $seconds = 0;
            $elevation = 0;
            $pacedMeters = 0;
            $pacedSeconds = 0;

            foreach ($mine as $row) {
                $meters += $row['meters'] ?? 0;
                $seconds += $row['seconds'] ?? 0;
                $elevation += $row['elevation'] ?? 0;

                if (($row['meters'] ?? 0) > 0 && ($row['seconds'] ?? 0) > 0) {
                    $pacedMeters += (int) $row['meters'];
                    $pacedSeconds += (int) $row['seconds'];
                }
            }

            $summary[$sport->value] = [
                'activity' => $sport,
                'outings' => \count($mine),
                'meters' => $meters,
                'seconds' => $seconds,
                'elevation' => $elevation,
                'paceLabel' => $pacedMeters > 0 ? $this->pace($pacedSeconds, $pacedMeters, $sport) : null,
                // Le D+ d'une piscine ne dit rien, même quand la montre en invente.
                'elevationLabel' => $elevation > 0 && ActivityType::SWIMMING !== $sport ? $elevation.' m' : null,
            ];
        }

        return $summary;
    }

    /**
     * Le temps en zones de toute la fenêtre, tous sports confondus : la
     * question est « à quelle intensité je m'entraîne », pas « à quelle
     * intensité je pédale ». `easyPercent` est la part Z1 + Z2, à lire contre
     * le repère 80/20 de l'entraînement polarisé.
     *
     * @param list<EnduranceRow> $rows
     *
     * @return EnduranceZones|null null quand aucune sortie n'a de temps en zones (pas de cardio, ou pas de FC max au profil à l'import)
     */
    private function zones(array $rows): ?array
    {
        $totals = ZoneBreakdown::seconds(null);
        $outings = 0;

        foreach ($rows as $row) {
            $seconds = ZoneBreakdown::seconds($row['zones']);
            if (0 === array_sum($seconds)) {
                continue;
            }

            ++$outings;
            $totals = array_map(static fn (int $sum, int $value): int => $sum + $value, $totals, $seconds);
        }

        $total = array_sum($totals);
        if (0 === $total) {
            return null;
        }

        return [
            'shares' => $this->zones->shares($totals),
            'outings' => $outings,
            'easyPercent' => (int) round(($totals[0] + $totals[1]) * 100 / $total),
            'durationLabel' => $this->units->duration($total),
        ];
    }

    /**
     * Les sorties marquantes de la fenêtre, par sport : la plus longue, le plus
     * gros D+, la meilleure allure au-delà d'un seuil, le meilleur kilomètre
     * (course). À égalité, la plus ancienne garde le record : c'est elle qui
     * l'a établi.
     *
     * @param list<EnduranceRow> $rows
     *
     * @return list<EnduranceRecord>
     */
    private function records(array $rows): array
    {
        $records = [];

        foreach (self::SPORTS as $sport) {
            $mine = $this->of($rows, $sport);

            $longest = $this->best($mine, static fn (array $r): ?float => ($r['meters'] ?? 0) > 0 ? (float) $r['meters'] : null);
            if (null !== $longest) {
                $records[] = $this->record($longest, 'Plus longue sortie', $this->units->distance($longest['meters']),
                    null !== $longest['seconds'] ? $this->units->duration($longest['seconds']) : null);
            }

            if (ActivityType::SWIMMING !== $sport) {
                $climb = $this->best($mine, static fn (array $r): ?float => ($r['elevation'] ?? 0) > 0 ? (float) $r['elevation'] : null);
                if (null !== $climb) {
                    $records[] = $this->record($climb, 'Plus gros D+', $climb['elevation'].' m', $this->units->distance($climb['meters']));
                }
            }

            $threshold = self::PACE_RECORD_MIN_METERS[$sport->value];
            // Allure en secondes par km : la plus basse gagne, d'où le signe.
            $fastest = $this->best($mine, static fn (array $r): ?float => ($r['meters'] ?? 0) >= $threshold && ($r['seconds'] ?? 0) > 0
                ? -((int) $r['seconds'] / (int) $r['meters'])
                : null);
            if (null !== $fastest) {
                $records[] = $this->record(
                    $fastest,
                    (ActivityType::CYCLING === $sport ? 'Meilleure vitesse' : 'Meilleure allure').' (≥ '.$this->units->distance($threshold).')',
                    $this->pace((int) $fastest['seconds'], (int) $fastest['meters'], $sport),
                    'sur '.$this->units->distance($fastest['meters']),
                );
            }

            if (ActivityType::RUNNING === $sport) {
                $records = [...$records, ...$this->bestKilometre($mine)];
            }
        }

        return $records;
    }

    /**
     * @param list<EnduranceRow> $rows
     *
     * @return list<EnduranceRecord> zéro ou un record
     */
    private function bestKilometre(array $rows): array
    {
        $best = null;
        $bestPace = null;

        foreach ($rows as $row) {
            foreach ($row['splits'] ?? [] as $split) {
                if (!\is_array($split) || !is_numeric($split['meters'] ?? null) || !is_numeric($split['seconds'] ?? null)) {
                    continue;
                }

                $meters = (int) $split['meters'];
                $seconds = (int) $split['seconds'];
                if ($meters < self::FULL_SPLIT_METERS || $seconds <= 0) {
                    continue;
                }

                $pace = $seconds / $meters;
                if (null === $bestPace || $pace < $bestPace) {
                    $bestPace = $pace;
                    $best = [$row, $seconds, $meters];
                }
            }
        }

        if (null === $best) {
            return [];
        }

        [$row, $seconds, $meters] = $best;

        return [$this->record($row, 'Meilleur kilomètre', $this->pace($seconds, $meters, ActivityType::RUNNING), 'pauses comprises')];
    }

    /**
     * Prévu contre réel, replié par sport sur les séances qui portent les deux.
     * Même règle que `ImportedActivityPresenter::comparison()` pour une séance :
     * la distance ne se compare que là où une distance était prescrite, la durée
     * que là où une durée l'était. Un écart contre zéro ne dit rien, et une
     * valeur déduite de l'allure n'est pas une consigne.
     *
     * L'allure, elle, se compare sur le prescrit déduit compris : prescrire
     * 45 min à 5:00/km, c'est prescrire cette allure-là.
     *
     * @param list<EndurancePair> $pairs
     *
     * @return list<EnduranceComparison>
     */
    private function comparison(array $pairs): array
    {
        $rows = [];

        foreach (self::SPORTS as $sport) {
            $sessions = 0;
            $distance = ['planned' => 0, 'actual' => 0, 'sessions' => 0];
            $duration = ['planned' => 0, 'actual' => 0, 'sessions' => 0];
            $pace = ['plannedMeters' => 0, 'plannedSeconds' => 0, 'actualMeters' => 0, 'actualSeconds' => 0, 'sessions' => 0];

            foreach ($pairs as $pair) {
                if ($sport !== $pair['activity']) {
                    continue;
                }

                $planned = $pair['planned'];
                $actual = $pair['actual'];
                if (0 === $planned['meters'] && 0 === $planned['seconds']) {
                    continue;
                }

                ++$sessions;

                if ($planned['meters'] > 0) {
                    $distance['planned'] += $planned['meters'];
                    $distance['actual'] += $actual['meters'];
                    ++$distance['sessions'];
                }

                if ($planned['seconds'] > 0) {
                    $duration['planned'] += $planned['seconds'];
                    $duration['actual'] += $actual['seconds'];
                    ++$duration['sessions'];
                }

                $plannedMeters = $planned['meters'] + $planned['derivedMeters'];
                $plannedSeconds = $planned['seconds'] + $planned['derivedSeconds'];
                if ($plannedMeters > 0 && $plannedSeconds > 0 && $actual['meters'] > 0 && $actual['seconds'] > 0) {
                    $pace['plannedMeters'] += $plannedMeters;
                    $pace['plannedSeconds'] += $plannedSeconds;
                    $pace['actualMeters'] += $actual['meters'];
                    $pace['actualSeconds'] += $actual['seconds'];
                    ++$pace['sessions'];
                }
            }

            if (0 === $sessions) {
                continue;
            }

            $rows[] = [
                'activity' => $sport,
                'sessions' => $sessions,
                'distance' => $distance['sessions'] > 0
                    ? $this->measure($distance, fn (int $v): string => $this->units->distance($v))
                    : null,
                'duration' => $duration['sessions'] > 0
                    ? $this->measure($duration, fn (int $v): string => $this->units->duration($v))
                    : null,
                'pace' => $pace['sessions'] > 0 ? [
                    'planned' => $this->pace($pace['plannedSeconds'], $pace['plannedMeters'], $sport),
                    'actual' => $this->pace($pace['actualSeconds'], $pace['actualMeters'], $sport),
                    'sessions' => $pace['sessions'],
                ] : null,
            ];
        }

        return $rows;
    }

    /**
     * @param array{planned: int, actual: int, sessions: int} $totals
     * @param callable(int): string                          $format
     *
     * @return ComparedMeasure
     */
    private function measure(array $totals, callable $format): array
    {
        $delta = $totals['actual'] - $totals['planned'];

        return [
            'planned' => $format($totals['planned']),
            'actual' => $format($totals['actual']),
            'delta' => 0 === $delta ? '=' : ($delta > 0 ? '+' : '−').$format(abs($delta)),
            'percent' => (int) round($delta * 100 / $totals['planned']),
            'sessions' => $totals['sessions'],
        ];
    }

    /**
     * La sortie qui maximise un critère ; null quand aucune n'y concourt
     * (critère null). Comparaison stricte : à égalité, la première gagne, et
     * les lignes arrivent par date croissante.
     *
     * @param list<EnduranceRow>                $rows
     * @param callable(EnduranceRow): ?float    $score
     *
     * @return EnduranceRow|null
     */
    private function best(array $rows, callable $score): ?array
    {
        $best = null;
        $bestScore = null;

        foreach ($rows as $row) {
            $value = $score($row);
            if (null !== $value && (null === $bestScore || $value > $bestScore)) {
                $best = $row;
                $bestScore = $value;
            }
        }

        return $best;
    }

    /**
     * @param EnduranceRow $row
     *
     * @return EnduranceRecord
     */
    private function record(array $row, string $label, string $value, ?string $note): array
    {
        return [
            'activity' => $row['activity'],
            'label' => $label,
            'value' => $value,
            'note' => $note,
            'date' => $row['date'],
            'scheduledId' => $row['scheduledId'],
        ];
    }

    /**
     * @param list<EnduranceRow> $rows
     *
     * @return list<EnduranceRow>
     */
    private function of(array $rows, ActivityType $sport): array
    {
        return array_values(array_filter($rows, static fn (array $row): bool => $sport === $row['activity']));
    }

    /** Une allure dans l'unité naturelle du sport (min/km, km/h, min/100m). */
    private function pace(int $seconds, int $meters, ActivityType $sport): string
    {
        return $this->units->pace((int) round($seconds * 1000 / $meters), PaceUnit::forActivity($sport));
    }
}
