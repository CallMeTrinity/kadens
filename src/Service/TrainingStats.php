<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\StatsRange;
use App\Repository\ExerciseRepository;
use App\Repository\LoggedSetRepository;
use App\Repository\ScheduledWorkoutRepository;

/**
 * Le moteur des statistiques d'entraînement sur une fenêtre de temps : tout ce
 * qu'affiche `/profile/stats`, et ce dont ProfileStats tire son résumé.
 *
 * **La règle qui organise tout le service : chaque chiffre vient de la source
 * qui fait autorité sur lui.**
 *
 * - La **salle** se lit sur le RÉALISÉ (`LoggedSet`) : tonnage, séries,
 *   ventilation par région, records, rampe hebdomadaire. Une séance cochée
 *   « faite » dit qu'elle a eu lieu, elle ne dit pas ce qui a été soulevé — et
 *   depuis Kadens Live, ce qui a été soulevé est en base.
 * - L'**endurance** se lit sur le PRESCRIT des séances faites. Ce n'est pas un
 *   repli : le cardio ne se logue jamais (règle du projet, cf. CLAUDE.md §3),
 *   son prescrit est la seule trace qui existe. Lui appliquer la règle du
 *   réalisé le ferait simplement disparaître.
 * - L'**observance** se lit sur le statut des séances datées, qui est sa
 *   définition même.
 *
 * Conséquence à ne pas casser : un historique antérieur à Kadens Live n'a pas
 * de log, donc pas de tonnage. C'est exact, pas cassé — la séance a bien eu
 * lieu (elle compte en observance et en régularité), on ne sait simplement pas
 * ce qu'elle pesait. Inventer son tonnage à partir du prescrit ferait passer
 * une intention pour un fait.
 *
 * **Coût.** Cinq requêtes agrégées sans hydratation (statuts, dates faites,
 * volume salle par jour, volume salle par exercice, records antérieurs) plus
 * UNE seule passe hydratante, celle du prescrit d'endurance, bornée par la
 * fenêtre. C'est ce qui rend « depuis le début » aussi tenable que « quatre
 * semaines » ; l'ancienne page remontait deux fois tout l'historique.
 *
 * @phpstan-import-type RegionShare from RegionBreakdown
 *
 * @phpstan-type Adherence array{done: int, missed: int, planned: int, total: int, adherence: float|null}
 * @phpstan-type EnduranceVolume array{meters: int, seconds: int, sessions: int, distanceLabel: string, durationLabel: string}
 * @phpstan-type Bucket array{label: string, short: string, tonnageKg: float, sessions: int, workingSets: int, tonnageLabel: string, tonnageHeightPct: int, sessionsHeightPct: int}
 * @phpstan-type TopLift array{name: string, weightKg: float, weightLabel: string, workingSets: int, sessions: int}
 * @phpstan-type NewRecord array{name: string, weightKg: float, weightLabel: string, previousKg: float, previousLabel: string, gainKg: float, gainLabel: string}
 * @phpstan-type PlanAdherence array{planId: int|null, planTitle: string, done: int, missed: int, planned: int, total: int, adherence: float|null}
 */
final class TrainingStats
{
    /**
     * Nombre maximal de barres tracées sur la rampe. Au-delà, la lecture n'y
     * gagne rien et la page devient un mur : on garde les plus récentes et on
     * le dit à l'écran.
     */
    private const MAX_BUCKETS = 24;

    /** Longueur du classement des charges et des records de la fenêtre. */
    private const TOP_LIFTS = 10;

    public function __construct(
        private readonly ScheduledWorkoutRepository $scheduled,
        private readonly LoggedSetRepository $loggedSets,
        private readonly ExerciseRepository $exercises,
        private readonly WorkoutMetrics $metrics,
        private readonly RegionBreakdown $regions,
        private readonly UnitFormatter $units,
    ) {
    }

    /**
     * Tout ce qu'il y a à dire d'une fenêtre. Une seule entrée : les blocs de la
     * page se composent du même agrégat, ils ne peuvent donc pas se contredire.
     *
     * @return array<string, mixed>
     */
    public function over(User $user, StatsPeriod $period): array
    {
        $bounds = $this->scheduled->dateBoundsForOwner($user);

        $adherence = $this->adherence(
            $this->scheduled->countByStatusForOwnerIn($user, $period->start, $period->end)
        );

        $doneDates = $this->scheduled->doneDatesForOwner($user, $period->start, $period->end);
        $gymByDate = $this->loggedSets->gymTotalsByDateForOwner($user, $period->start, $period->end);
        $gymByExercise = $this->loggedSets->gymTotalsByExerciseForOwner($user, $period->start, $period->end);

        $prescribed = $this->prescribedPass($user, $period);

        return [
            'period' => $period,
            'adherence' => $adherence,
            'regularity' => $this->regularity($doneDates, $period, $bounds['first'] ?? null),
            'volume' => [
                'gym' => $this->gymVolume($gymByDate),
                'running' => $this->endurance($prescribed['endurance']['running']),
                'cycling' => $this->endurance($prescribed['endurance']['cycling']),
                'swimming' => $this->endurance($prescribed['endurance']['swimming']),
            ],
            'regions' => $this->regionShares($gymByExercise),
            'records' => $this->records($user, $period, $gymByExercise),
            'progression' => $this->progression($gymByDate, $period),
            'plans' => $this->planAdherence($user, $period),
            'activityCounts' => $prescribed['activities'],
            'bounds' => $bounds,
        ];
    }

    /**
     * Les mois qui ont au moins un jour d'historique, le plus récent d'abord :
     * les options du sélecteur mensuel.
     *
     * Tous les mois de l'intervalle sont listés, y compris ceux sans séance —
     * un mois vide est une réponse (« je n'ai rien fait en août »), pas une
     * option à masquer. La borne haute inclut le mois courant même s'il est
     * encore vide, sinon la page n'aurait pas d'entrée pour « ce mois-ci ».
     *
     * @return list<array{value: string, label: string}>
     */
    public function availableMonths(User $user, ?\DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new \DateTimeImmutable())->setTime(0, 0);
        $bounds = $this->scheduled->dateBoundsForOwner($user);

        $first = ($bounds['first'] ?? $now)->modify('first day of this month')->setTime(0, 0);
        $last = max($bounds['last'] ?? $now, $now)->modify('first day of this month')->setTime(0, 0);

        $months = [];
        for ($cursor = $last; $cursor >= $first; $cursor = $cursor->modify('-1 month')) {
            $months[] = ['value' => $cursor->format('Y-m'), 'label' => StatsPeriod::monthLabel($cursor)];
        }

        return $months;
    }

    /**
     * Compteurs par statut -> observance. `adherence` se calcule sur les séances
     * ÉCHUES (faites + manquées) : une séance encore à venir n'est ni tenue ni
     * ratée, la compter au dénominateur ferait chuter le score à chaque case
     * posée en avance.
     *
     * @param array<string, int> $counts
     *
     * @return Adherence
     */
    private function adherence(array $counts): array
    {
        $done = $counts['done'] ?? 0;
        $missed = $counts['missed'] ?? 0;
        $planned = $counts['planned'] ?? 0;
        $settled = $done + $missed;

        return [
            'done' => $done,
            'missed' => $missed,
            'planned' => $planned,
            'total' => $done + $missed + $planned,
            'adherence' => $settled > 0 ? $done / $settled : null,
        ];
    }

    /**
     * La régularité, déduite du seul tableau des dates de séances faites.
     *
     * Deux séries de semaines sont renvoyées et elles ne disent pas la même
     * chose : `bestStreak` est le record de la fenêtre, `currentStreak` court
     * jusqu'à la semaine en cours et **tolère la semaine courante vide** — on
     * ne casse pas une série de onze semaines parce qu'on lit ses stats un
     * lundi matin.
     *
     * @param list<\DateTimeImmutable> $doneDates
     *
     * @return array{sessions: int, activeDays: int, perWeek: float|null, bestStreak: int, currentStreak: int, bestWeek: array{label: string, sessions: int}|null, bestMonth: array{label: string, sessions: int}|null}
     */
    private function regularity(array $doneDates, StatsPeriod $period, ?\DateTimeImmutable $firstActivity): array
    {
        $days = [];
        $byWeek = [];
        $byMonth = [];

        foreach ($doneDates as $date) {
            $days[$date->format('Y-m-d')] = true;
            $week = $date->format('o-\WW');
            $byWeek[$week] = ($byWeek[$week] ?? 0) + 1;
            $month = $date->format('Y-m');
            $byMonth[$month] = ($byMonth[$month] ?? 0) + 1;
        }

        $sessions = \count($doneDates);
        $dayCount = $period->dayCount($firstActivity);

        $bestWeek = null;
        if ([] !== $byWeek) {
            $key = (string) array_search(max($byWeek), $byWeek, true);
            $bestWeek = ['label' => $this->weekLabel($key), 'sessions' => $byWeek[$key]];
        }

        $bestMonth = null;
        if ([] !== $byMonth) {
            $key = (string) array_search(max($byMonth), $byMonth, true);
            $bestMonth = [
                'label' => StatsPeriod::monthLabel(new \DateTimeImmutable($key . '-01')),
                'sessions' => $byMonth[$key],
            ];
        }

        return [
            'sessions' => $sessions,
            'activeDays' => \count($days),
            // Sur moins d'une semaine pleine, une moyenne hebdomadaire
            // extrapolerait trois séances en vingt-et-une : on ne l'affiche pas.
            'perWeek' => $dayCount >= 7 ? round($sessions / ($dayCount / 7), 1) : null,
            'bestStreak' => $this->longestStreak(array_keys($byWeek)),
            'currentStreak' => $this->currentStreak(array_keys($byWeek), $period->end),
            'bestWeek' => $bestWeek,
            'bestMonth' => $bestMonth,
        ];
    }

    /**
     * Plus longue suite de semaines ISO consécutives portant au moins une
     * séance. Les semaines sont comparées par leur lundi et non par leur
     * numéro : « 2025-W52 » et « 2026-W01 » se suivent, leurs numéros non.
     *
     * @param list<string> $weekKeys clés `o-\WW`
     */
    private function longestStreak(array $weekKeys): int
    {
        $mondays = $this->mondays($weekKeys);
        if ([] === $mondays) {
            return 0;
        }

        $best = 1;
        $run = 1;
        for ($i = 1, $n = \count($mondays); $i < $n; ++$i) {
            // Comparaison par timestamp, jamais par identité : `modify()` rend
            // une NOUVELLE instance, donc `===` serait toujours faux et toute
            // série vaudrait 1.
            $follows = $mondays[$i]->getTimestamp() === $mondays[$i - 1]->modify('+7 days')->getTimestamp();
            $run = $follows ? $run + 1 : 1;
            $best = max($best, $run);
        }

        return $best;
    }

    /**
     * Suite de semaines consécutives se terminant à la fin de la fenêtre. La
     * semaine courante peut être vide sans casser la série : on repart alors de
     * la précédente.
     *
     * @param list<string> $weekKeys
     */
    private function currentStreak(array $weekKeys, \DateTimeImmutable $end): int
    {
        $mondays = $this->mondays($weekKeys);
        if ([] === $mondays) {
            return 0;
        }

        $last = end($mondays);
        $endMonday = $end->modify('monday this week')->setTime(0, 0);
        // Deux tolérances seulement : la semaine en cours et la précédente.
        // Au-delà, la série est bel et bien interrompue.
        if ($last < $endMonday->modify('-7 days')) {
            return 0;
        }

        $streak = 1;
        for ($i = \count($mondays) - 1; $i > 0; --$i) {
            // Même règle que longestStreak() : timestamp, pas identité d'instance.
            if ($mondays[$i - 1]->getTimestamp() !== $mondays[$i]->modify('-7 days')->getTimestamp()) {
                break;
            }
            ++$streak;
        }

        return $streak;
    }

    /**
     * Clés de semaine -> lundis triés et dédoublonnés.
     *
     * @param list<string> $weekKeys
     *
     * @return list<\DateTimeImmutable>
     */
    private function mondays(array $weekKeys): array
    {
        $mondays = [];
        foreach ($weekKeys as $key) {
            $mondays[$key] = (new \DateTimeImmutable())->setISODate(
                (int) substr($key, 0, 4),
                (int) substr($key, 6),
            )->setTime(0, 0);
        }

        $list = array_values($mondays);
        usort($list, static fn(\DateTimeImmutable $a, \DateTimeImmutable $b): int => $a <=> $b);

        return $list;
    }

    private function weekLabel(string $weekKey): string
    {
        $monday = (new \DateTimeImmutable())->setISODate(
            (int) substr($weekKey, 0, 4),
            (int) substr($weekKey, 6),
        )->setTime(0, 0);

        return 'semaine du ' . $monday->format('d/m/Y');
    }

    /**
     * Volume de salle sur la fenêtre, replié depuis les totaux par jour.
     *
     * @param list<array{date: \DateTimeImmutable, sessions: int, workingSets: int, tonnageKg: float, seconds: int, rpeSum: int, rpeCount: int}> $byDate
     *
     * @return array{tonnageKg: float, tonnageLabel: string, workingSets: int, sessions: int, seconds: int, durationLabel: string, averageRpe: float|null, perSessionSets: float|null, perSessionTonnageLabel: string|null}
     */
    private function gymVolume(array $byDate): array
    {
        $tonnage = 0.0;
        $sets = 0;
        $sessions = 0;
        $seconds = 0;
        $rpeSum = 0;
        $rpeCount = 0;

        foreach ($byDate as $row) {
            $tonnage += $row['tonnageKg'];
            $sets += $row['workingSets'];
            $sessions += $row['sessions'];
            $seconds += $row['seconds'];
            $rpeSum += $row['rpeSum'];
            $rpeCount += $row['rpeCount'];
        }

        return [
            'tonnageKg' => $tonnage,
            'tonnageLabel' => $this->units->weight(round($tonnage)),
            'workingSets' => $sets,
            'sessions' => $sessions,
            'seconds' => $seconds,
            'durationLabel' => $this->units->duration($seconds),
            // Moyenne sur les séries qui portent un RPE, jamais sur toutes :
            // une série non notée n'est pas une série à zéro.
            'averageRpe' => $rpeCount > 0 ? round($rpeSum / $rpeCount, 1) : null,
            'perSessionSets' => $sessions > 0 ? round($sets / $sessions, 1) : null,
            'perSessionTonnageLabel' => $sessions > 0 ? $this->units->weight(round($tonnage / $sessions)) : null,
        ];
    }

    /**
     * @param array{meters: int, seconds: int, sessions: int} $raw
     *
     * @return EnduranceVolume
     */
    private function endurance(array $raw): array
    {
        return [
            'meters' => $raw['meters'],
            'seconds' => $raw['seconds'],
            'sessions' => $raw['sessions'],
            'distanceLabel' => $this->units->distance($raw['meters']),
            'durationLabel' => $this->units->duration($raw['seconds']),
        ];
    }

    /**
     * L'UNIQUE passe hydratante : le prescrit des séances faites, dont on tire
     * le volume d'endurance et la répartition par activité. Les deux sortent du
     * même parcours — les calculer séparément voulait dire charger deux fois le
     * même historique, ce que faisait l'ancienne page.
     *
     * @return array{endurance: array<string, array{meters: int, seconds: int, sessions: int}>, activities: list<array{activity: ActivityType, sessions: int}>}
     */
    private function prescribedPass(User $user, StatsPeriod $period): array
    {
        $endurance = [
            'running' => ['meters' => 0, 'seconds' => 0, 'sessions' => 0],
            'cycling' => ['meters' => 0, 'seconds' => 0, 'sessions' => 0],
            'swimming' => ['meters' => 0, 'seconds' => 0, 'sessions' => 0],
        ];
        $activityCounts = [];

        foreach ($this->scheduled->findDoneWithContentForOwner($user, $period->start, $period->end) as $sw) {
            $workout = $sw->getWorkout();
            // Séance libre ou source supprimée : rien de prescrit à analyser.
            if (null === $workout) {
                continue;
            }

            foreach ($this->metrics->distinctActivities($workout) as $activity) {
                $activityCounts[$activity->value] = ($activityCounts[$activity->value] ?? 0) + 1;
            }

            $volume = $this->metrics->volume($workout);
            foreach ($endurance as $key => $_) {
                if ($volume[$key]['meters'] > 0 || $volume[$key]['seconds'] > 0) {
                    ++$endurance[$key]['sessions'];
                }
                $endurance[$key]['meters'] += $volume[$key]['meters'];
                $endurance[$key]['seconds'] += $volume[$key]['seconds'];
            }
        }

        arsort($activityCounts);
        $activities = [];
        foreach ($activityCounts as $value => $sessions) {
            $activities[] = ['activity' => ActivityType::from((string) $value), 'sessions' => $sessions];
        }

        return ['endurance' => $endurance, 'activities' => $activities];
    }

    /**
     * Ventilation du volume de salle par grande région anatomique.
     *
     * Les zones vivent sur la DÉFINITION en bibliothèque, pas sur le réalisé :
     * un exercice supprimé depuis (`exerciseId` null) porte du tonnage bien
     * réel mais n'a plus de zone à laquelle l'attribuer. Il sort donc de cette
     * seule lecture, et d'aucune autre — c'est la même limite que LogMetrics.
     *
     * @param list<array{exerciseId: int|null, name: string, workingSets: int, tonnageKg: float, topWeightKg: float|null, sessions: int}> $byExercise
     *
     * @return list<RegionShare>
     */
    private function regionShares(array $byExercise): array
    {
        $setsByExercise = [];
        foreach ($byExercise as $row) {
            if (null !== $row['exerciseId']) {
                $setsByExercise[$row['exerciseId']] = ($setsByExercise[$row['exerciseId']] ?? 0) + $row['workingSets'];
            }
        }

        if ([] === $setsByExercise) {
            return [];
        }

        $setsByArea = [];
        // findBy sur les seuls exercices réellement travaillés : leur nombre est
        // borné par la pratique, pas par l'historique.
        foreach ($this->exercises->findBy(['id' => array_keys($setsByExercise)]) as $exercise) {
            $sets = $setsByExercise[$exercise->getId()] ?? 0;
            foreach ($exercise->getTargetAreas() ?? [] as $area) {
                $setsByArea[$area->value] = ($setsByArea[$area->value] ?? 0) + $sets;
            }
        }

        return $this->regions->shares($setsByArea);
    }

    /**
     * Les charges de la fenêtre : le classement des plus lourdes, et parmi
     * elles celles qui battent tout ce qui précède la fenêtre.
     *
     * `comparable` dit si la notion de *nouveau* record a un sens ici : « depuis
     * le début » n'a pas de passé antérieur, tous ses maximums sont des records
     * par construction. On affiche alors le classement seul plutôt qu'une liste
     * de faux exploits.
     *
     * @param list<array{exerciseId: int|null, name: string, workingSets: int, tonnageKg: float, topWeightKg: float|null, sessions: int}> $byExercise
     *
     * @return array{top: list<TopLift>, new: list<NewRecord>, comparable: bool}
     */
    private function records(User $user, StatsPeriod $period, array $byExercise): array
    {
        $lifted = array_values(array_filter(
            $byExercise,
            static fn(array $row): bool => null !== $row['topWeightKg'] && $row['topWeightKg'] > 0,
        ));

        usort($lifted, static fn(array $a, array $b): int => $b['topWeightKg'] <=> $a['topWeightKg']);

        $top = [];
        foreach (\array_slice($lifted, 0, self::TOP_LIFTS) as $row) {
            $top[] = [
                'name' => $row['name'],
                'weightKg' => $row['topWeightKg'],
                'weightLabel' => $this->units->weight($row['topWeightKg']),
                'workingSets' => $row['workingSets'],
                'sessions' => $row['sessions'],
            ];
        }

        $start = $period->start;
        if (null === $start) {
            return ['top' => $top, 'new' => [], 'comparable' => false];
        }

        $previous = $this->loggedSets->maxWeightByExerciseBefore($user, $start);

        $new = [];
        foreach ($lifted as $row) {
            $id = $row['exerciseId'];
            // Un exercice jamais chargé avant la fenêtre n'a rien à battre :
            // sa première charge est une première, pas un record.
            if (null === $id || !isset($previous[$id]) || $row['topWeightKg'] <= $previous[$id]) {
                continue;
            }

            $new[] = [
                'name' => $row['name'],
                'weightKg' => $row['topWeightKg'],
                'weightLabel' => $this->units->weight($row['topWeightKg']),
                'previousKg' => $previous[$id],
                'previousLabel' => $this->units->weight($previous[$id]),
                'gainKg' => $row['topWeightKg'] - $previous[$id],
                'gainLabel' => $this->units->weight($row['topWeightKg'] - $previous[$id]),
            ];
        }

        usort($new, static fn(array $a, array $b): int => $b['gainKg'] <=> $a['gainKg']);

        return ['top' => $top, 'new' => \array_slice($new, 0, self::TOP_LIFTS), 'comparable' => true];
    }

    /**
     * La rampe du volume de salle : une barre par bucket, hauteurs déjà
     * calculées côté PHP pour garder le rendu Twig « bête » (même principe que
     * ProgressionAggregator).
     *
     * La granularité suit la fenêtre : une semaine par barre sur quatre
     * semaines ou un mois, un mois par barre au-delà. Une fenêtre longue tracée
     * à la semaine donnerait cent barres de trois pixels.
     *
     * Les buckets **vides sont conservés** : un trou est l'information la plus
     * utile de ce graphique, le supprimer collerait deux semaines distantes
     * l'une à côté de l'autre.
     *
     * @param list<array{date: \DateTimeImmutable, sessions: int, workingSets: int, tonnageKg: float, seconds: int, rpeSum: int, rpeCount: int}> $byDate
     *
     * @return array{points: list<Bucket>, granularity: string, truncated: bool, hasVolume: bool}
     */
    private function progression(array $byDate, StatsPeriod $period): array
    {
        $weekly = \in_array($period->range, [StatsRange::FOUR_WEEKS, StatsRange::MONTH], true);

        $totals = [];
        foreach ($byDate as $row) {
            $key = $weekly ? $row['date']->format('o-\WW') : $row['date']->format('Y-m');
            $totals[$key] ??= ['tonnageKg' => 0.0, 'sessions' => 0, 'workingSets' => 0];
            $totals[$key]['tonnageKg'] += $row['tonnageKg'];
            $totals[$key]['sessions'] += $row['sessions'];
            $totals[$key]['workingSets'] += $row['workingSets'];
        }

        $keys = $this->bucketKeys($byDate, $period, $weekly);
        $truncated = \count($keys) > self::MAX_BUCKETS;
        if ($truncated) {
            $keys = \array_slice($keys, -self::MAX_BUCKETS);
        }

        $maxTonnage = 0.0;
        $maxSessions = 0;
        foreach ($keys as $key) {
            $maxTonnage = max($maxTonnage, $totals[$key]['tonnageKg'] ?? 0.0);
            $maxSessions = max($maxSessions, $totals[$key]['sessions'] ?? 0);
        }

        $points = [];
        foreach ($keys as $key) {
            $bucket = $totals[$key] ?? ['tonnageKg' => 0.0, 'sessions' => 0, 'workingSets' => 0];
            $points[] = [
                'label' => $weekly ? $this->weekLabel($key) : StatsPeriod::monthLabel(new \DateTimeImmutable($key . '-01')),
                'short' => $weekly ? 'S' . substr($key, 6) : substr($key, 5, 2) . '/' . substr($key, 2, 2),
                'tonnageKg' => $bucket['tonnageKg'],
                'sessions' => $bucket['sessions'],
                'workingSets' => $bucket['workingSets'],
                'tonnageLabel' => $bucket['tonnageKg'] > 0 ? $this->units->weight(round($bucket['tonnageKg'])) : '—',
                'tonnageHeightPct' => $maxTonnage > 0 ? (int) round($bucket['tonnageKg'] / $maxTonnage * 100) : 0,
                'sessionsHeightPct' => $maxSessions > 0 ? (int) round($bucket['sessions'] / $maxSessions * 100) : 0,
            ];
        }

        return [
            'points' => $points,
            'granularity' => $weekly ? 'week' : 'month',
            'truncated' => $truncated,
            'hasVolume' => $maxTonnage > 0,
        ];
    }

    /**
     * Les clés de bucket à tracer, trous compris.
     *
     * La fenêtre bornée donne ses propres bornes ; « depuis le début » les
     * emprunte aux données, faute de borne basse. Sans données du tout, il n'y
     * a pas de graphique à tracer.
     *
     * @param list<array{date: \DateTimeImmutable, sessions: int, workingSets: int, tonnageKg: float, seconds: int, rpeSum: int, rpeCount: int}> $byDate
     *
     * @return list<string>
     */
    private function bucketKeys(array $byDate, StatsPeriod $period, bool $weekly): array
    {
        $start = $period->start;
        if (null === $start) {
            if ([] === $byDate) {
                return [];
            }
            $start = $byDate[0]['date'];
        }

        $cursor = $weekly
            ? $start->modify('monday this week')->setTime(0, 0)
            : $start->modify('first day of this month')->setTime(0, 0);
        $end = $period->end->setTime(0, 0);
        $step = $weekly ? '+7 days' : '+1 month';

        $keys = [];
        while ($cursor <= $end) {
            $keys[] = $weekly ? $cursor->format('o-\WW') : $cursor->format('Y-m');
            $cursor = $cursor->modify($step);
        }

        return $keys;
    }

    /**
     * Observance ventilée par plan source : quel plan on tient, lequel on
     * lâche. Le bucket « hors plan » regroupe les séances isolées et celles
     * dont le plan a été supprimé (la FK passe à NULL).
     *
     * @return list<PlanAdherence>
     */
    private function planAdherence(User $user, StatsPeriod $period): array
    {
        $rows = [];
        foreach ($this->scheduled->statusCountsByPlanForOwner($user, $period->start, $period->end) as $bucket) {
            $stats = $this->adherence($bucket['counts']);
            if (0 === $stats['total']) {
                continue;
            }

            $rows[] = [
                'planId' => $bucket['planId'],
                'planTitle' => $bucket['planTitle'] ?? 'Hors plan',
                ...$stats,
            ];
        }

        // Le plan le plus suivi en premier ; à volume égal, la meilleure
        // observance. Un plan de deux séances tenues à 100 % ne passe pas devant
        // un bloc de vingt séances tenu à 90 %.
        usort($rows, static fn(array $a, array $b): int => [$b['total'], $b['adherence'] ?? -1] <=> [$a['total'], $a['adherence'] ?? -1]);

        return $rows;
    }
}
