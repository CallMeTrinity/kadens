<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Enum\ActivityType;
use App\Enum\PaceUnit;
use App\Enum\ScheduledStatus;

/**
 * Lecture agrégée de la progression d'un plan : la rampe PRÉVUE, et par-dessus
 * elle le RÉALISÉ d'une instanciation quand il y en a une (KL-49).
 *
 * Le prévu se lit sur la trame : le fork à la pose fait que chaque case porte sa
 * propre copie de séance, donc ses propres paramètres (charges/allures) — cette
 * rampe existe déjà en base, on la trace. Le réalisé, lui, ne vit que sur les
 * séances **datées** ; il entre ici par `realizedRun()`, dont la sortie se
 * repasse aux deux lectures existantes. Deux conséquences à ne pas casser :
 *
 * - **Une trame n'a pas de dates.** Ce qu'on superpose est donc toujours *une*
 *   instanciation, désignée par son `planAnchorDate` ; l'appelant choisit
 *   laquelle (la plus récente par défaut) et la lit avec
 *   `ScheduledWorkoutRepository::findPlanRunWithLog()`.
 * - **Chaque réalisé vient de sa source.** La muscu se logue (LogMetrics) ; la
 *   course, le vélo et la natation s'importent de la montre (`ImportedActivity`,
 *   agrégat passé par l'appelant). Sans activité rattachée, une série
 *   d'endurance n'a pas de deuxième barre — et surtout pas une barre à zéro :
 *   une barre absente et une barre nulle ne disent pas la même chose.
 * - **L'endurance se mesure dans l'unité que le plan permet.** Une sortie se
 *   prescrit en distance OU en durée, et un plan passe souvent de l'une à l'autre
 *   en cours de route. Chaque activité est donc tracée dans l'unité qui chiffre le
 *   plus de semaines (déduite de l'allure au besoin, marquée « ≈ »), et une
 *   semaine dont un effort ne se chiffre pas dans cette unité est signalée
 *   incomplète plutôt que montrée basse en silence.
 *
 * Consomme WorkoutMetrics (volume prescrit par séance), LogMetrics (son pendant
 * réalisé) et UnitFormatter (formatage) : jamais de reparsing ni de mise à plat
 * réimplémentée. Les hauteurs de barres (heightPct) sont précalculées ici pour
 * garder le rendu Twig « bête », et le prévu comme le réalisé sont mis à
 * l'échelle sur le **même** maximum — sans quoi deux barres de même hauteur
 * vaudraient deux valeurs différentes.
 *
 * @phpstan-type VolumePoint array{week: int, value: float, label: string, heightPct: int, estimated: bool, missing: int, realValue: float|null, realLabel: string|null, realHeightPct: int}
 * @phpstan-type VolumeSeries array{key: string, label: string, unitLabel: string|null, missingNoun: string|null, modifier: string, points: list<VolumePoint>, max: float, hasRealized: bool, totalLabel: string|null, realTotalLabel: string|null, partialWeeks: list<int>}
 * @phpstan-type PlannedWeek array{value: float, estimated: bool, missing: int}
 * @phpstan-type EnduranceTotals array<int, array<string, array{meters: int, seconds: int}>>
 * @phpstan-type TrajPoint array{week: int, value: float|null, label: string|null, present: bool, heightPct: int, realValue: float|null, realLabel: string|null, realHeightPct: int}
 * @phpstan-type Trajectory array{exercise: Exercise, metric: string, metricLabel: string, lowerIsBetter: bool, points: list<TrajPoint>, min: float, max: float, weeksPresent: int, direction: string, firstLabel: string|null, lastLabel: string|null, hasRealized: bool}
 * @phpstan-type RealizedWeek array{minutes: int, tonnage: float, sets: int, endurance: array<string, array{meters: int, seconds: int}>}
 * @phpstan-type RealizedExerciseWeek array{weight: float|null, sets: float|null, duration: float|null}
 * @phpstan-type Adherence array{done: int, missed: int, planned: int, total: int, percent: int, logged: int, imported: int}
 * @phpstan-type RealizedRun array{weeks: array<int, RealizedWeek>, exercises: array<int, array<int, RealizedExerciseWeek>>, adherence: Adherence}
 */
final class ProgressionAggregator
{
    /** Les activités d'endurance tracées, dans l'ordre d'affichage : clé de volume => [libellé, activité]. */
    private const ENDURANCE = [
        'running' => ['Course', ActivityType::RUNNING],
        'cycling' => ['Vélo', ActivityType::CYCLING],
        'swimming' => ['Natation', ActivityType::SWIMMING],
    ];

    public function __construct(
        private readonly UnitFormatter $units,
        private readonly WorkoutMetrics $metrics,
        private readonly LogMetrics $logMetrics,
    ) {
    }

    /**
     * Le réalisé d'**une** instanciation, replié sur les semaines de la trame :
     * ce que les deux lectures ci-dessous superposent à leur rampe.
     *
     * Renvoie **null** quand l'instanciation est vide — un plan jamais posé au
     * calendrier n'affiche que son prévu, sans espace réservé à un réalisé qui
     * n'existe pas.
     *
     * La semaine d'une séance datée se lit d'abord sur sa case d'origine
     * (`sourcePlanItem.weekNumber`), qui est la vérité même si la séance a été
     * déplacée depuis. Le repli sur l'écart de dates ne sert qu'aux séances dont
     * la case a disparu (FK en SET NULL) ; hors trame, la séance ne pèse sur
     * aucune semaine mais compte quand même dans l'observance : elle a bien été
     * posée par ce plan.
     *
     * `$imported` = le réel importé de ces séances
     * (`ImportedActivityRepository::enduranceTotalsForScheduledIds()`), indexé par
     * id de séance datée. C'est le réalisé de l'endurance, qui ne se logue jamais.
     *
     * @param list<ScheduledWorkout> $scheduled séances datées d'UNE instanciation
     * @param EnduranceTotals        $imported
     *
     * @return RealizedRun|null
     */
    public function realizedRun(PlanTemplate $template, array $scheduled, array $imported = []): ?array
    {
        if ([] === $scheduled) {
            return null;
        }

        $weeksCount = max(1, (int) $template->getDurationWeeks());

        /** @var array<int, RealizedWeek> $weeks */
        $weeks = [];
        for ($w = 1; $w <= $weeksCount; ++$w) {
            $weeks[$w] = ['minutes' => 0, 'tonnage' => 0.0, 'sets' => 0, 'endurance' => []];
        }

        $exercises = [];
        $counts = [ScheduledStatus::DONE->value => 0, ScheduledStatus::MISSED->value => 0, ScheduledStatus::PLANNED->value => 0];
        $logged = 0;
        $importedSessions = 0;

        foreach ($scheduled as $session) {
            ++$counts[$session->getStatus()->value];
            if ($session->hasLog()) {
                ++$logged;
            }
            $actual = $imported[(int) $session->getId()] ?? [];
            if ([] !== $actual) {
                ++$importedSessions;
            }

            // weekOf() borne déjà la semaine ; isset() le dit à l'analyse statique.
            $week = $this->weekOf($session, $weeksCount);
            if (null === $week || !isset($weeks[$week])) {
                continue;
            }

            // Durée RÉELLE, jamais l'estimée du prescrit : les bornes écrites par
            // le mobile, ou le temps en mouvement de la montre. Les deux mesurent
            // la même séance quand une sortie est rattachée à une séance loguée :
            // on garde la plus longue, les additionner compterait le recouvrement
            // deux fois.
            $duration = $this->logMetrics->durationSeconds($session);
            $importedSeconds = array_sum(array_column($actual, 'seconds'));
            if (null !== $duration || $importedSeconds > 0) {
                $weeks[$week]['minutes'] += (int) round(max($duration ?? 0, $importedSeconds) / 60);
            }

            foreach ($actual as $activity => $volume) {
                $slot = $weeks[$week]['endurance'][$activity] ?? ['meters' => 0, 'seconds' => 0];
                $weeks[$week]['endurance'][$activity] = [
                    'meters' => $slot['meters'] + $volume['meters'],
                    'seconds' => $slot['seconds'] + $volume['seconds'],
                ];
            }

            // Tonnage et séries de travail : LogMetrics est le pendant exact de
            // WorkoutMetrics côté réalisé, il tient déjà les mêmes exclusions
            // (exercice sauté, échauffement). Ne pas les recompter ici.
            $summary = $this->logMetrics->summary($session);
            if (null !== $summary) {
                $weeks[$week]['tonnage'] += $summary['tonnageKg'];
                $weeks[$week]['sets'] += $summary['workingSets'];
            }

            $this->collectExerciseWeek($session, $week, $exercises);
        }

        $total = \count($scheduled);

        return [
            'weeks' => $weeks,
            'exercises' => $exercises,
            'adherence' => [
                'done' => $counts[ScheduledStatus::DONE->value],
                'missed' => $counts[ScheduledStatus::MISSED->value],
                'planned' => $counts[ScheduledStatus::PLANNED->value],
                'total' => $total,
                // $scheduled n'est jamais vide ici (garde en tête de méthode).
                'percent' => (int) round($counts[ScheduledStatus::DONE->value] / $total * 100),
                'logged' => $logged,
                'imported' => $importedSessions,
            ],
        ];
    }

    /**
     * Charge par semaine, ventilée en séries traçables (temps estimé, tonnage,
     * séries, endurance par activité). Une série n'est renvoyée que si elle porte
     * au moins une valeur non nulle, prévue ou réalisée. Montre la périodisation
     * (montée en charge, semaine de décharge).
     *
     * `$realized` = la clé `weeks` de `realizedRun()`, ou `[]` pour n'avoir que la
     * rampe prévue. Le temps, le tonnage et les séries se superposent au réalisé
     * logué ; les activités d'endurance, au réel importé de la montre.
     *
     * @param array<int, RealizedWeek> $realized
     *
     * @return list<VolumeSeries>
     */
    public function weeklyVolume(PlanTemplate $template, array $realized = []): array
    {
        $weeksCount = max(1, (int) $template->getDurationWeeks());

        $emptyEndurance = ['meters' => 0, 'derivedMeters' => 0, 'seconds' => 0, 'derivedSeconds' => 0, 'efforts' => 0, 'withoutDistance' => 0, 'withoutDuration' => 0];
        $acc = [];
        for ($w = 1; $w <= $weeksCount; ++$w) {
            $acc[$w] = [
                'minutes' => 0,
                'tonnage' => 0.0,
                'sets' => 0,
                'running' => $emptyEndurance,
                'cycling' => $emptyEndurance,
                'swimming' => $emptyEndurance,
            ];
        }

        foreach ($template->getPlanItems() as $item) {
            $week = (int) $item->getWeekNumber();
            $workout = $item->getWorkout();
            if (!isset($acc[$week]) || null === $workout) {
                continue;
            }
            $vol = $this->metrics->volume($workout);
            $acc[$week]['minutes'] += (int) ($workout->getEstimatedDurationMinutes() ?? 0);
            $acc[$week]['tonnage'] += $vol['gym']['tonnageKg'];
            $acc[$week]['sets'] += $vol['gym']['totalSets'];
            foreach (self::ENDURANCE as $key => $_) {
                foreach ($emptyEndurance as $field => $__) {
                    $acc[$week][$key][$field] += $vol[$key][$field];
                }
            }
        }

        $scalar = static fn (string $key): array => array_map(
            static fn (array $row): array => ['value' => (float) $row[$key], 'estimated' => false, 'missing' => 0],
            $acc,
        );
        $realOf = static fn (string $key): array => array_map(
            static fn (array $week): float => (float) $week[$key],
            $realized,
        );

        $series = [
            $this->buildSeries('minutes', 'Temps', 'durée estimée', '', $scalar('minutes'), $realOf('minutes'), null, fn (float $v): string => $this->humanMinutes((int) $v)),
            $this->buildSeries('tonnage', 'Tonnage', null, ActivityType::GYM->cssKey(), $scalar('tonnage'), $realOf('tonnage'), null, fn (float $v): string => $this->compactTonnage($v)),
            $this->buildSeries('sets', 'Séries', 'de travail', ActivityType::GYM->cssKey(), $scalar('sets'), $realOf('sets'), null, static fn (float $v): string => (string) (int) $v),
        ];

        foreach (self::ENDURANCE as $key => [$label, $activity]) {
            $series[] = $this->enduranceSeries($key, $label, $activity, array_column($acc, $key, null), $realized);
        }

        return array_values(array_filter($series, static fn (array $s): bool => $s['max'] > 0.0));
    }

    /**
     * Trajectoire de chaque exercice récurrent (présent sur ≥ 2 semaines) à
     * travers les semaines du plan : la métrique primaire (charge, allure,
     * distance…) semaine par semaine. C'est la vue « progression » au sens strict
     * de l'athlète. Triée des exercices les plus récurrents aux moins récurrents.
     *
     * `$realized` = la clé `exercises` de `realizedRun()`, ou `[]`. La
     * superposition ne concerne que les métriques que le réalisé porte (charge,
     * séries, durée) : une allure ou une distance prescrite reste seule, la
     * course ne se logue pas.
     *
     * @param array<int, array<int, RealizedExerciseWeek>> $realized
     *
     * @return list<Trajectory>
     */
    public function exerciseTrajectories(PlanTemplate $template, array $realized = []): array
    {
        $weeksCount = max(1, (int) $template->getDurationWeeks());

        // exId => ['exercise' => Exercise, 'weeks' => [week => list of ['pe', 'rounds']]]
        $byExercise = [];
        $order = [];
        foreach ($template->getPlanItems() as $item) {
            $week = $item->getWeekNumber();
            $workout = $item->getWorkout();
            if (null === $week || $week < 1 || $week > $weeksCount || null === $workout) {
                continue;
            }
            foreach ($workout->getBlocks() as $block) {
                $rounds = max(1, $block->getRounds());
                foreach ($block->getPrescribedExercises() as $pe) {
                    $exercise = $pe->getExercise();
                    $id = $exercise?->getId();
                    if (null === $exercise || null === $id) {
                        continue;
                    }
                    if (!isset($byExercise[$id])) {
                        $byExercise[$id] = ['exercise' => $exercise, 'weeks' => []];
                        $order[] = $id;
                    }
                    $byExercise[$id]['weeks'][$week][] = ['pe' => $pe, 'rounds' => $rounds];
                }
            }
        }

        $trajectories = [];
        foreach ($order as $id) {
            $data = $byExercise[$id];
            // Une « trajectoire » n'a de sens qu'à partir de 2 semaines distinctes.
            if (\count($data['weeks']) < 2) {
                continue;
            }
            $trajectory = $this->buildTrajectory($data['exercise'], $data['weeks'], $weeksCount, $realized[$id] ?? []);
            if (null !== $trajectory) {
                $trajectories[] = $trajectory;
            }
        }

        // Exercices les plus récurrents en tête (les plus parlants à tracer).
        usort($trajectories, static fn (array $a, array $b): int => $b['weeksPresent'] <=> $a['weeksPresent']);

        return $trajectories;
    }

    /**
     * Une activité d'endurance, dans l'unité qui chiffre le plus de semaines du
     * plan : distance (saisie, ou déduite de durée × allure) ou durée (saisie, ou
     * déduite de distance × allure). À égalité, la distance — c'est l'unité dans
     * laquelle une sortie se raconte. Le réel importé portant les deux, il suit
     * l'unité retenue.
     *
     * @param list<array<string, int>>  $weeks    volume prescrit par semaine (index 0 = S1)
     * @param array<int, RealizedWeek>  $realized
     *
     * @return VolumeSeries
     */
    private function enduranceSeries(string $key, string $label, ActivityType $activity, array $weeks, array $realized): array
    {
        $byDistance = 0;
        $byDuration = 0;
        foreach ($weeks as $week) {
            if ($week['efforts'] > 0) {
                $byDistance += 0 === $week['withoutDistance'] ? 1 : 0;
                $byDuration += 0 === $week['withoutDuration'] ? 1 : 0;
            }
        }
        $distance = $byDistance >= $byDuration;

        $planned = [];
        foreach ($weeks as $i => $week) {
            $derived = $distance ? $week['derivedMeters'] : $week['derivedSeconds'];
            $planned[$i + 1] = [
                'value' => (float) (($distance ? $week['meters'] : $week['seconds']) + $derived),
                'estimated' => $derived > 0,
                'missing' => $distance ? $week['withoutDistance'] : $week['withoutDuration'],
            ];
        }

        $real = [];
        foreach ($realized as $w => $week) {
            $volume = $week['endurance'][$key] ?? null;
            $real[$w] = null === $volume ? null : (float) ($distance ? $volume['meters'] : $volume['seconds']);
        }

        return $this->buildSeries(
            $key,
            $label,
            $distance ? 'en distance' : 'en durée',
            $activity->cssKey(),
            $planned,
            $real,
            $distance ? 'distance' : 'durée',
            $distance
                ? fn (float $v): string => $this->compactDistance($v)
                : fn (float $v): string => $this->humanMinutes((int) round($v / 60)),
        );
    }

    /**
     * @param array<int, PlannedWeek>  $planned semaine => prévu
     * @param array<int, float|null>   $real    semaine => réalisé, null = rien de remonté
     * @param string|null              $missingNoun ce qui manque à un effort non chiffré (« distance »), null si sans objet
     * @param callable(float): string  $format
     *
     * @return VolumeSeries
     */
    private function buildSeries(string $key, string $label, ?string $unitLabel, string $modifier, array $planned, array $real, ?string $missingNoun, callable $format): array
    {
        // Une échelle unique pour les deux barres : c'est ce qui rend la
        // superposition lisible. Un maximum par barre les rendrait comparables
        // entre semaines mais plus entre elles, ce qui est tout l'objet du bloc.
        $max = 0.0;
        foreach ($planned as $week => $row) {
            $max = max($max, $row['value'], $real[$week] ?? 0.0);
        }

        $hasRealized = false;
        $anyEstimated = false;
        $total = 0.0;
        $realTotal = 0.0;
        $partialWeeks = [];
        $points = [];
        foreach ($planned as $week => $row) {
            $value = $row['value'];
            $realValue = $real[$week] ?? null;
            $hasRealized = $hasRealized || (null !== $realValue && $realValue > 0.0);
            $anyEstimated = $anyEstimated || $row['estimated'];
            $total += $value;
            $realTotal += $realValue ?? 0.0;
            if ($row['missing'] > 0) {
                $partialWeeks[] = $week;
            }

            $points[] = [
                'week' => $week,
                'value' => $value,
                'label' => $value > 0.0 ? ($row['estimated'] ? '≈ ' : '').$format($value) : '—',
                // Neutre : la barre représente une quantité, jamais un « mieux ».
                'heightPct' => $this->heightPct($value, $max),
                'estimated' => $row['estimated'],
                'missing' => $row['missing'],
                'realValue' => $realValue,
                'realLabel' => (null !== $realValue && $realValue > 0.0) ? $format($realValue) : null,
                'realHeightPct' => $this->heightPct($realValue ?? 0.0, $max),
            ];
        }

        return [
            'key' => $key,
            'label' => $label,
            'unitLabel' => $unitLabel,
            'missingNoun' => $missingNoun,
            'modifier' => $modifier,
            'points' => $points,
            'max' => $max,
            'hasRealized' => $hasRealized,
            'totalLabel' => $total > 0.0 ? ($anyEstimated ? '≈ ' : '').$format($total) : null,
            'realTotalLabel' => $hasRealized ? $format($realTotal) : null,
            'partialWeeks' => $partialWeeks,
        ];
    }

    private function heightPct(float $value, float $max): int
    {
        return ($max > 0.0 && $value > 0.0) ? max(6, (int) round($value / $max * 100)) : 0;
    }

    /**
     * @param array<int, list<array{pe: \App\Entity\PrescribedExercise, rounds: int}>> $weeks
     * @param array<int, RealizedExerciseWeek>                                         $realized
     *
     * @return Trajectory|null
     */
    private function buildTrajectory(Exercise $exercise, array $weeks, int $weeksCount, array $realized): ?array
    {
        // Métrique primaire déduite des paramètres réellement prescrits.
        $has = ['weight' => false, 'pace' => false, 'distance' => false, 'duration' => false, 'sets' => false];
        foreach ($weeks as $occurrences) {
            foreach ($occurrences as $occurrence) {
                $pe = $occurrence['pe'];
                // Charge/séries détaillé-aware : top set et décompte dérivent des
                // lignes en mode détaillé, du scalaire sinon.
                $has['weight'] = $has['weight'] || null !== $pe->getTopWeightKg();
                $has['pace'] = $has['pace'] || null !== $pe->getPaceSecondsPerKm();
                $has['distance'] = $has['distance'] || null !== $pe->getDistanceMeters();
                $has['duration'] = $has['duration'] || null !== $pe->getDurationSeconds();
                $has['sets'] = $has['sets'] || $pe->getWorkingSetCount() > 0;
            }
        }

        [$metric, $metricLabel, $lowerIsBetter] = match (true) {
            $has['weight'] => ['weight', 'Charge (top set)', false],
            $has['pace'] => ['pace', 'Allure', true],
            $has['distance'] => ['distance', 'Distance', false],
            $has['duration'] => ['duration', 'Durée', false],
            $has['sets'] => ['sets', 'Séries', false],
            default => [null, '', false],
        };
        if (null === $metric) {
            return null;
        }

        $activity = $exercise->getActivity();

        // Le réalisé ne parle que de charge, de séries et de durée : une allure ou
        // une distance n'est jamais loguée (seule la muscu l'est). La trajectoire
        // reste alors seule, sans deuxième barre à zéro.
        $realMetric = \in_array($metric, ['weight', 'sets', 'duration'], true) ? $metric : null;

        $min = null;
        $max = null;
        $present = 0;
        $hasRealized = false;
        $points = [];
        for ($w = 1; $w <= $weeksCount; ++$w) {
            $value = isset($weeks[$w]) ? $this->weekMetric($metric, $weeks[$w]) : null;
            if (null !== $value) {
                ++$present;
                $min = null === $min ? $value : min($min, $value);
                $max = null === $max ? $value : max($max, $value);
            }

            $realValue = null !== $realMetric ? ($realized[$w][$realMetric] ?? null) : null;
            if (null !== $realValue) {
                $hasRealized = true;
                // Même échelle que le prévu : les deux barres se comparent à l'œil.
                $min = null === $min ? $realValue : min($min, $realValue);
                $max = null === $max ? $realValue : max($max, $realValue);
            }

            $points[] = [
                'week' => $w,
                'value' => $value,
                'label' => null !== $value ? $this->formatMetric($metric, $value, $activity) : null,
                'present' => null !== $value,
                'heightPct' => 0, // rempli au second passage (on a besoin de min/max)
                'realValue' => $realValue,
                'realLabel' => null !== $realValue ? $this->formatMetric($metric, $realValue, $activity) : null,
                'realHeightPct' => 0,
            ];
        }

        $min ??= 0.0;
        $max ??= 0.0;
        $span = $max - $min;
        foreach ($points as $i => $point) {
            foreach (['value' => 'heightPct', 'realValue' => 'realHeightPct'] as $source => $target) {
                if (null === $point[$source]) {
                    continue;
                }
                if ($span <= 0.0) {
                    $points[$i][$target] = 60; // plan « plat » sur cet exo : hauteur neutre
                    continue;
                }
                // Échelle entre min et max (plancher 15 %) pour faire ressortir la rampe.
                // Allure : plus bas = plus rapide, donc barre inversée (haute = mieux).
                $ratio = $lowerIsBetter ? ($max - $point[$source]) / $span : ($point[$source] - $min) / $span;
                $points[$i][$target] = (int) round(15 + $ratio * 85);
            }
        }

        // Sens de la progression : première vs dernière semaine renseignée.
        $firstValue = null;
        $lastValue = null;
        $firstLabel = null;
        $lastLabel = null;
        foreach ($points as $point) {
            if (null === $point['value']) {
                continue;
            }
            if (null === $firstValue) {
                $firstValue = $point['value'];
                $firstLabel = $point['label'];
            }
            $lastValue = $point['value'];
            $lastLabel = $point['label'];
        }
        $direction = 'flat';
        if (null !== $firstValue && null !== $lastValue && $firstValue !== $lastValue) {
            $increased = $lastValue > $firstValue;
            $direction = ($lowerIsBetter ? !$increased : $increased) ? 'up' : 'down';
        }

        return [
            'exercise' => $exercise,
            'metric' => $metric,
            'metricLabel' => $metricLabel,
            'lowerIsBetter' => $lowerIsBetter,
            'points' => $points,
            'min' => $min,
            'max' => $max,
            'weeksPresent' => $present,
            'direction' => $direction,
            'firstLabel' => $firstLabel,
            'lastLabel' => $lastLabel,
            'hasRealized' => $hasRealized,
        ];
    }

    /**
     * La semaine de trame d'une séance datée. La case d'origine fait foi même si
     * la séance a été déplacée depuis — c'est elle qui dit à quelle semaine du
     * plan elle appartient. Sans case (FK en SET NULL), on retombe sur l'écart de
     * dates avec l'ancre. null = hors trame, la séance ne pèse sur aucune semaine.
     */
    private function weekOf(ScheduledWorkout $scheduled, int $weeksCount): ?int
    {
        $week = $scheduled->getSourcePlanItem()?->getWeekNumber();

        if (null === $week) {
            $anchor = $scheduled->getPlanAnchorDate();
            if (null === $anchor) {
                return null;
            }
            $week = intdiv((int) $anchor->diff($scheduled->getScheduledDate())->format('%r%a'), 7) + 1;
        }

        return ($week >= 1 && $week <= $weeksCount) ? $week : null;
    }

    /**
     * Le réalisé d'une séance, ventilé par exercice, cumulé sur la semaine.
     *
     * Mêmes règles que partout ailleurs : un exercice sauté n'apporte rien,
     * l'échauffement et la série cochée sans valeur n'entrent ni dans la charge
     * ni dans le décompte (LoggedSet::countsAsWorking). La charge
     * retenue est la plus lourde de la semaine (top set, comme le prescrit) ;
     * séries et durée se cumulent.
     *
     * @param array<int, array<int, RealizedExerciseWeek>> $exercises accumulateur, modifié en place
     */
    private function collectExerciseWeek(ScheduledWorkout $scheduled, int $week, array &$exercises): void
    {
        foreach ($scheduled->getLoggedExercises() as $logged) {
            $id = $logged->getExercise()?->getId();
            if ($logged->isSkipped() || null === $id) {
                continue;
            }

            $slot = $exercises[$id][$week] ?? ['weight' => null, 'sets' => null, 'duration' => null];

            foreach ($logged->getLoggedSets() as $set) {
                if (!$set->countsAsWorking()) {
                    continue;
                }

                $slot['sets'] = ($slot['sets'] ?? 0.0) + 1;

                $weight = $set->getWeightKg();
                if (null !== $weight) {
                    $slot['weight'] = null === $slot['weight'] ? $weight : max($slot['weight'], $weight);
                }

                $duration = $set->getDurationSeconds();
                if (null !== $duration) {
                    $slot['duration'] = ($slot['duration'] ?? 0.0) + $duration;
                }
            }

            if (null !== $slot['sets']) {
                $exercises[$id][$week] = $slot;
            }
        }
    }

    /**
     * Valeur de la métrique pour une semaine (agrégée sur toutes les occurrences
     * de l'exercice cette semaine-là). Charge/allure = meilleure occurrence (top
     * set / allure la plus rapide) ; distance/durée/séries = somme × tours de
     * bloc (aligné sur WorkoutMetrics, qui ne multiplie pas par `sets`).
     *
     * @param list<array{pe: \App\Entity\PrescribedExercise, rounds: int}> $occurrences
     */
    private function weekMetric(string $metric, array $occurrences): ?float
    {
        $result = null;
        foreach ($occurrences as $occurrence) {
            $pe = $occurrence['pe'];
            $rounds = $occurrence['rounds'];
            switch ($metric) {
                case 'weight':
                    if (null !== $pe->getTopWeightKg()) {
                        $result = null === $result ? $pe->getTopWeightKg() : max($result, $pe->getTopWeightKg());
                    }
                    break;
                case 'pace':
                    if (null !== $pe->getPaceSecondsPerKm()) {
                        $pace = (float) $pe->getPaceSecondsPerKm();
                        $result = null === $result ? $pace : min($result, $pace);
                    }
                    break;
                case 'distance':
                    if (null !== $pe->getDistanceMeters()) {
                        // Répétitions de l'effort comprises, comme dans le volume
                        // de séance : « 4 × 1 km » pèse 4 km sur la semaine.
                        $result = ($result ?? 0.0) + $pe->getDistanceMeters() * $pe->getEnduranceRepeats() * $rounds;
                    }
                    break;
                case 'duration':
                    if (null !== $pe->getDurationSeconds()) {
                        $result = ($result ?? 0.0) + $pe->getDurationSeconds() * $rounds;
                    }
                    break;
                case 'sets':
                    if ($pe->getWorkingSetCount() > 0) {
                        $result = ($result ?? 0.0) + $pe->getWorkingSetCount() * $rounds;
                    }
                    break;
            }
        }

        return $result;
    }

    private function formatMetric(string $metric, float $value, ?ActivityType $activity): string
    {
        return match ($metric) {
            'weight' => $this->units->weight($value),
            'pace' => $this->units->pace((int) round($value), PaceUnit::forActivity($activity)),
            'distance' => $this->units->distance((int) round($value)),
            'duration' => $this->units->duration((int) round($value)),
            'sets' => $this->plural((int) $value, 'série'),
            default => (string) $value,
        };
    }

    private function plural(int $n, string $noun): string
    {
        return sprintf('%d %s%s', $n, $noun, $n > 1 ? 's' : '');
    }

    /**
     * Formats courts des barres de charge : une colonne de semaine fait parfois
     * 28 px, « 32 626,5 kg » n'y tient pas. Une décimale suffit à lire une rampe.
     */
    private function compactTonnage(float $kg): string
    {
        if ($kg < 1000) {
            return $this->units->weight(round($kg));
        }

        return $this->oneDecimal($kg / 1000).' t';
    }

    private function compactDistance(float $meters): string
    {
        if ($meters < 1000) {
            return $this->units->distance((int) round($meters));
        }

        return $this->oneDecimal($meters / 1000).' km';
    }

    private function oneDecimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', ' '), '0'), ',');
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
