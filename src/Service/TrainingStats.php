<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\StatsRange;
use App\Repository\ExerciseRepository;
use App\Repository\ImportedActivityRepository;
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
 * - L'**endurance** se lit sur le RÉEL IMPORTÉ (`ImportedActivity`) quand une
 *   activité est rattachée à la séance faite, sur son PRESCRIT sinon. Le cardio
 *   ne se saisit jamais dans Kadens (cf. CLAUDE.md §3) : son réalisé s'importe
 *   de la montre, et tant qu'il n'y en a pas, le prescrit est la seule trace qui
 *   existe. La substitution se fait **par activité** : une séance brique dont
 *   seule la course a été enregistrée garde le vélo prescrit.
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
 * fenêtre. Le réel importé s'y greffe par une lecture scalaire, une ligne par
 * sortie et sans hydrater une seule activité : ses zones et ses splits sont du
 * JSON, que DQL ne sait pas sommer. Elle est bornée par le nombre de sorties,
 * pas par l'historique de séries, et nourrit aussi l'onglet Endurance
 * (`EnduranceStats`). C'est ce qui rend « depuis le début » aussi tenable que
 * « quatre semaines » ; l'ancienne page remontait deux fois tout l'historique.
 *
 * @phpstan-import-type RegionShare from RegionBreakdown
 * @phpstan-import-type EnduranceRow from ImportedActivityRepository
 * @phpstan-import-type EndurancePair from EnduranceStats
 *
 * @phpstan-type Adherence array{done: int, missed: int, planned: int, total: int, adherence: float|null}
 * @phpstan-type EnduranceVolume array{meters: int, seconds: int, sessions: int, derivedMeters: int, derivedSeconds: int, distanceLabel: string|null, durationLabel: string|null}
 * @phpstan-type EnduranceRaw array{meters: int, seconds: int, sessions: int, derivedMeters: int, derivedSeconds: int}
 * @phpstan-type Bucket array{label: string, short: string, tonnageKg: float, sessions: int, workingSets: int, tonnageLabel: string, tonnageHeightPct: int, sessionsHeightPct: int}
 * @phpstan-type TopLift array{name: string, weightKg: float, weightLabel: string, workingSets: int, sessions: int}
 * @phpstan-type NewRecord array{name: string, weightKg: float, weightLabel: string, previousKg: float, previousLabel: string, gainKg: float, gainLabel: string}
 * @phpstan-type EnduranceSegment array{activity: ActivityType, seconds: int, meters: int, durationLabel: string, distanceLabel: string|null}
 * @phpstan-type EnduranceBucket array{label: string, short: string, seconds: int, durationLabel: string, heightPct: int, segments: list<EnduranceSegment>}
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
        private readonly ExerciseNaming $naming,
        private readonly ImportedActivityRepository $importedActivities,
        private readonly EnduranceStats $enduranceStats,
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

        // Les définitions des exercices réellement travaillés, chargées UNE fois
        // et partagées : les zones s'y lisent (`regionShares`) et les libellés
        // vivants aussi (`records`). Leur nombre est borné par la pratique, pas
        // par l'historique — la fenêtre « depuis le début » ne coûte pas plus
        // qu'un mois.
        $lifted = $this->liftedExercises($gymByExercise);

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
            'regions' => $this->regionShares($gymByExercise, $lifted),
            'records' => $this->records($user, $period, $gymByExercise, $lifted),
            'progression' => $this->progression($gymByDate, $period),
            'plans' => $this->planAdherence($user, $period),
            'activityCounts' => $prescribed['activities'],
            'endurance' => $this->enduranceStats->over(
                $prescribed['rows'],
                $prescribed['pairs'],
                $prescribed['coverage'] + ['unattached' => $this->importedActivities->countUnattachedEnduranceForOwnerIn($user, $period->start, $period->end)],
            ) + ['progression' => $this->enduranceProgression($prescribed['byDate'], $period)],
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
            'tonnageLabel' => $this->units->tonnage($tonnage),
            'workingSets' => $sets,
            'sessions' => $sessions,
            'seconds' => $seconds,
            'durationLabel' => $this->units->duration($seconds),
            // Moyenne sur les séries qui portent un RPE, jamais sur toutes :
            // une série non notée n'est pas une série à zéro.
            'averageRpe' => $rpeCount > 0 ? round($rpeSum / $rpeCount, 1) : null,
            'perSessionSets' => $sessions > 0 ? round($sets / $sessions, 1) : null,
            'perSessionTonnageLabel' => $sessions > 0 ? $this->units->tonnage($tonnage / $sessions) : null,
        ];
    }

    /**
     * `meters` et `seconds` sont des totaux, part déduite de l'allure COMPRISE ;
     * `derivedMeters` / `derivedSeconds` disent combien de ce total est un calcul,
     * pour que l'écran le marque « ≈ ». Un total nul n'a pas de libellé : « 0 m »
     * pour une sortie prescrite en durée se lirait « rien couru ».
     *
     * @param EnduranceRaw $raw
     *
     * @return EnduranceVolume
     */
    private function endurance(array $raw): array
    {
        return [
            'meters' => $raw['meters'],
            'seconds' => $raw['seconds'],
            'sessions' => $raw['sessions'],
            'derivedMeters' => $raw['derivedMeters'],
            'derivedSeconds' => $raw['derivedSeconds'],
            'distanceLabel' => $raw['meters'] > 0
                ? ($raw['derivedMeters'] > 0 ? '≈ ' : '').$this->units->distance($raw['meters'])
                : null,
            'durationLabel' => $raw['seconds'] > 0
                ? ($raw['derivedSeconds'] > 0 ? '≈ ' : '').$this->units->duration($raw['seconds'])
                : null,
        ];
    }

    /**
     * L'UNIQUE passe hydratante : le prescrit des séances faites, dont on tire
     * le volume d'endurance et la répartition par activité — corrigés par le
     * réel importé quand il existe, et complétés par le réalisé logué pour les
     * séances sans prescrit. Les deux sortent du
     * même parcours — les calculer séparément voulait dire charger deux fois le
     * même historique, ce que faisait l'ancienne page.
     *
     * Le prescrit d'une sortie se décrit en distance OU en durée : sans activité
     * importée, la grandeur absente se déduit de l'autre et de l'allure quand il
     * y en a une (`WorkoutMetrics::volume()`), et elle est comptée à part pour
     * être dite estimée. Sans allure, elle reste à zéro : on n'invente pas une
     * allure. Le réel importé, lui, porte toujours les deux et ne déduit rien.
     *
     * Le même parcours sert aussi l'onglet Endurance, sans une requête de plus :
     * les lignes du réel importé (`rows`, d'où `EnduranceStats` tire allure, D+,
     * zones et records), les couples prévu/réel par séance et par sport
     * (`pairs`), le volume daté par sport pour la rampe (`byDate`) et la
     * couverture (combien de séances d'endurance faites, combien lues sur la
     * montre).
     *
     * @return array{endurance: array<string, EnduranceRaw>, activities: list<array{activity: ActivityType, sessions: int}>, rows: list<EnduranceRow>, pairs: list<EndurancePair>, byDate: list<array{date: \DateTimeImmutable, activity: ActivityType, meters: int, seconds: int}>, coverage: array{sessions: int, withActivity: int}}
     */
    private function prescribedPass(User $user, StatsPeriod $period): array
    {
        $empty = ['meters' => 0, 'seconds' => 0, 'sessions' => 0, 'derivedMeters' => 0, 'derivedSeconds' => 0];
        $endurance = ['running' => $empty, 'cycling' => $empty, 'swimming' => $empty];
        $activityCounts = [];

        // Le réel importé : une seule lecture scalaire, repliée ici par séance
        // puis par activité. Les mêmes lignes nourrissent EnduranceStats.
        $rows = $this->importedActivities->enduranceRowsForOwner($user, $period->start, $period->end);
        $imported = [];
        foreach ($rows as $row) {
            $cell = $imported[$row['scheduledId']][$row['activity']->value] ?? ['meters' => 0, 'seconds' => 0];
            $cell['meters'] += $row['meters'] ?? 0;
            $cell['seconds'] += $row['seconds'] ?? 0;
            $imported[$row['scheduledId']][$row['activity']->value] = $cell;
        }

        $pairs = [];
        $byDate = [];
        $coverage = ['sessions' => 0, 'withActivity' => 0];

        // Les activités du réalisé logué, par séance : un second agrégat. Elles ne
        // servent qu'aux séances sans prescrit — tout l'historique importé
        // (`TrainingHistoryImporter`, `workout = null`) et les séances dont la
        // source a été supprimée. Sans elles, ces séances de salle ne comptaient
        // nulle part. Même règle que `TrainingHistory::dominantActivities()` :
        // le réalisé complète le prescrit, il ne le corrige jamais.
        $logged = [];
        foreach ($this->scheduled->doneLoggedActivityCountsForOwner($user, $period->start, $period->end) as $row) {
            $logged[$row['scheduledId']][$row['activity']->value] = true;
        }

        foreach ($this->scheduled->findDoneWithContentForOwner($user, $period->start, $period->end) as $sw) {
            $workout = $sw->getWorkout();
            $id = (int) $sw->getId();
            $actual = $imported[$id] ?? [];

            $activities = [];
            foreach (null === $workout ? [] : $this->metrics->distinctActivities($workout) as $activity) {
                $activities[$activity->value] = true;
            }
            if ([] === $activities) {
                $activities = $logged[$id] ?? [];
            }
            foreach (array_keys($actual) as $key) {
                $activities[$key] = true;
            }
            foreach (array_keys($activities) as $value) {
                $activityCounts[$value] = ($activityCounts[$value] ?? 0) + 1;
            }

            // Séance libre ou source supprimée, sans activité rattachée : pas de
            // volume d'endurance à lire. Avec une activité, la séance libre
            // compte : une sortie courue n'a pas besoin d'avoir été prescrite
            // pour avoir eu lieu.
            if (null === $workout && [] === $actual) {
                continue;
            }

            $volume = null === $workout ? null : $this->metrics->volume($workout);
            $isEndurance = false;
            foreach ($endurance as $key => $_) {
                // Par activité, le réel remplace le prescrit en bloc (distance ET
                // durée) : mélanger la distance courue et la durée prévue ne
                // décrirait aucune séance.
                if (isset($actual[$key])) {
                    if (null !== $volume) {
                        $pairs[] = [
                            'activity' => ActivityType::from($key),
                            'planned' => [
                                'meters' => $volume[$key]['meters'],
                                'seconds' => $volume[$key]['seconds'],
                                'derivedMeters' => $volume[$key]['derivedMeters'],
                                'derivedSeconds' => $volume[$key]['derivedSeconds'],
                            ],
                            'actual' => $actual[$key],
                        ];
                    }
                    $meters = $actual[$key]['meters'];
                    $seconds = $actual[$key]['seconds'];
                    $derivedMeters = 0;
                    $derivedSeconds = 0;
                } else {
                    $derivedMeters = $volume[$key]['derivedMeters'] ?? 0;
                    $derivedSeconds = $volume[$key]['derivedSeconds'] ?? 0;
                    $meters = ($volume[$key]['meters'] ?? 0) + $derivedMeters;
                    $seconds = ($volume[$key]['seconds'] ?? 0) + $derivedSeconds;
                }

                if ($meters > 0 || $seconds > 0) {
                    ++$endurance[$key]['sessions'];
                    $isEndurance = true;
                    $byDate[] = ['date' => $sw->getScheduledDate(), 'activity' => ActivityType::from($key), 'meters' => $meters, 'seconds' => $seconds];
                }
                $endurance[$key]['meters'] += $meters;
                $endurance[$key]['seconds'] += $seconds;
                $endurance[$key]['derivedMeters'] += $derivedMeters;
                $endurance[$key]['derivedSeconds'] += $derivedSeconds;
            }

            if ($isEndurance || [] !== $actual) {
                ++$coverage['sessions'];
                if ([] !== $actual) {
                    ++$coverage['withActivity'];
                }
            }
        }

        arsort($activityCounts);
        $activities = [];
        foreach ($activityCounts as $value => $sessions) {
            $activities[] = ['activity' => ActivityType::from((string) $value), 'sessions' => $sessions];
        }

        return [
            'endurance' => $endurance,
            'activities' => $activities,
            'rows' => $rows,
            'pairs' => $pairs,
            'byDate' => $byDate,
            'coverage' => $coverage,
        ];
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
     * @param array<int, Exercise>                                                                                                        $lifted
     *
     * @return list<RegionShare>
     */
    private function regionShares(array $byExercise, array $lifted): array
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
        foreach ($setsByExercise as $id => $sets) {
            foreach (($lifted[$id] ?? null)?->getTargetAreas() ?? [] as $area) {
                $setsByArea[$area->value] = ($setsByArea[$area->value] ?? 0) + $sets;
            }
        }

        return $this->regions->shares($setsByArea);
    }

    /**
     * Les définitions des exercices travaillés dans la fenêtre, indexées par id.
     *
     * `findBy` sur les seuls exercices réellement chargés : leur nombre est
     * borné par la pratique, pas par l'historique. Un exercice supprimé depuis
     * (`exerciseId` null, FK en SET NULL) n'en fait pas partie — il porte du
     * tonnage bien réel mais n'a plus ni zone ni libellé vivant à donner.
     *
     * @param list<array{exerciseId: int|null, name: string, workingSets: int, tonnageKg: float, topWeightKg: float|null, sessions: int}> $byExercise
     *
     * @return array<int, Exercise>
     */
    private function liftedExercises(array $byExercise): array
    {
        $ids = array_values(array_unique(array_filter(
            array_column($byExercise, 'exerciseId'),
            static fn (?int $id): bool => null !== $id,
        )));

        if ([] === $ids) {
            return [];
        }

        $indexed = [];

        foreach ($this->exercises->findBy(['id' => $ids]) as $exercise) {
            $indexed[(int) $exercise->getId()] = $exercise;
        }

        return $indexed;
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
     * Le libellé affiché est celui de la DÉFINITION vivante quand elle existe,
     * pas le snapshot du réalisé sur lequel la requête groupe : sans ça, un
     * classement de records resterait en français au milieu d'un écran anglais.
     * Le snapshot reprend la main dès que l'exercice a été supprimé — c'est la
     * seule chose qu'il reste alors à afficher.
     *
     * @param list<array{exerciseId: int|null, name: string, workingSets: int, tonnageKg: float, topWeightKg: float|null, sessions: int}> $byExercise
     * @param array<int, Exercise>                                                                                                        $lifted
     *
     * @return array{top: list<TopLift>, new: list<NewRecord>, comparable: bool}
     */
    private function records(User $user, StatsPeriod $period, array $byExercise, array $lifted): array
    {
        $label = fn (array $row): string => $this->naming->label(
            null !== $row['exerciseId'] ? ($lifted[$row['exerciseId']] ?? null) : null,
            $row['name'],
        );

        // Nommé à part des définitions : `$lifted` porte les Exercise, `$ranked`
        // les lignes d'agrégat. Les confondre marcherait par accident (la
        // fonction fléchée capture par valeur) et casserait au premier
        // refactor.
        $ranked = array_values(array_filter(
            $byExercise,
            static fn (array $row): bool => null !== $row['topWeightKg'] && $row['topWeightKg'] > 0,
        ));

        usort($ranked, static fn (array $a, array $b): int => $b['topWeightKg'] <=> $a['topWeightKg']);

        $top = [];
        foreach (\array_slice($ranked, 0, self::TOP_LIFTS) as $row) {
            $top[] = [
                'name' => $label($row),
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
        foreach ($ranked as $row) {
            $id = $row['exerciseId'];
            // Un exercice jamais chargé avant la fenêtre n'a rien à battre :
            // sa première charge est une première, pas un record.
            if (null === $id || !isset($previous[$id]) || $row['topWeightKg'] <= $previous[$id]) {
                continue;
            }

            $new[] = [
                'name' => $label($row),
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

        $keys = $this->bucketKeys($byDate[0]['date'] ?? null, $period, $weekly);
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
                'tonnageLabel' => $bucket['tonnageKg'] > 0 ? $this->units->tonnage($bucket['tonnageKg']) : '—',
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
     * emprunte aux données (`$first`, la plus ancienne date de la série), faute
     * de borne basse. Sans données du tout, il n'y a pas de graphique à tracer.
     * Partagée par la rampe de salle et celle d'endurance : mêmes colonnes,
     * mêmes trous.
     *
     * @return list<string>
     */
    private function bucketKeys(?\DateTimeImmutable $first, StatsPeriod $period, bool $weekly): array
    {
        $start = $period->start ?? $first;
        if (null === $start) {
            return [];
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
     * La rampe d'endurance : le temps par semaine (ou par mois), empilé par
     * sport. **En durée, pas en distance** : en kilomètres, une sortie vélo
     * écraserait toute la natation de la fenêtre et la barre ne dirait plus que
     * « vélo ». Les kilomètres restent dans le détail de chaque segment.
     *
     * Même découpage que la rampe de salle (`bucketKeys`, `MAX_BUCKETS`) et même
     * provenance que le volume : réel importé quand il existe, prescrit sinon. Une
     * sortie prescrite en distance sans allure n'a pas de durée : elle compte au
     * volume, pas ici.
     *
     * @param list<array{date: \DateTimeImmutable, activity: ActivityType, meters: int, seconds: int}> $byDate
     *
     * @return array{points: list<EnduranceBucket>, granularity: string, truncated: bool, hasVolume: bool, sports: list<ActivityType>}
     */
    private function enduranceProgression(array $byDate, StatsPeriod $period): array
    {
        $weekly = \in_array($period->range, [StatsRange::FOUR_WEEKS, StatsRange::MONTH], true);

        $first = null;
        $totals = [];
        foreach ($byDate as $row) {
            $first = null === $first ? $row['date'] : min($first, $row['date']);
            $key = $weekly ? $row['date']->format('o-\WW') : $row['date']->format('Y-m');
            $sport = $row['activity']->value;
            $totals[$key][$sport] ??= ['meters' => 0, 'seconds' => 0];
            $totals[$key][$sport]['meters'] += $row['meters'];
            $totals[$key][$sport]['seconds'] += $row['seconds'];
        }

        $keys = $this->bucketKeys($first, $period, $weekly);
        $truncated = \count($keys) > self::MAX_BUCKETS;
        if ($truncated) {
            $keys = \array_slice($keys, -self::MAX_BUCKETS);
        }

        $maxSeconds = 0;
        foreach ($keys as $key) {
            $maxSeconds = max($maxSeconds, array_sum(array_column($totals[$key] ?? [], 'seconds')));
        }

        $points = [];
        $present = [];
        foreach ($keys as $key) {
            $segments = [];
            $seconds = 0;
            foreach (EnduranceStats::SPORTS as $sport) {
                $cell = $totals[$key][$sport->value] ?? null;
                if (null === $cell || ($cell['seconds'] <= 0 && $cell['meters'] <= 0)) {
                    continue;
                }

                $seconds += $cell['seconds'];
                if ($cell['seconds'] > 0) {
                    $present[$sport->value] = true;
                }
                $segments[] = [
                    'activity' => $sport,
                    'seconds' => $cell['seconds'],
                    'meters' => $cell['meters'],
                    'durationLabel' => $this->units->duration($cell['seconds']),
                    'distanceLabel' => $cell['meters'] > 0 ? $this->units->distance($cell['meters']) : null,
                ];
            }

            $points[] = [
                'label' => $weekly ? $this->weekLabel($key) : StatsPeriod::monthLabel(new \DateTimeImmutable($key.'-01')),
                'short' => $weekly ? 'S'.substr($key, 6) : substr($key, 5, 2).'/'.substr($key, 2, 2),
                'seconds' => $seconds,
                'durationLabel' => $seconds > 0 ? $this->units->duration($seconds) : '—',
                'heightPct' => $maxSeconds > 0 ? (int) round($seconds / $maxSeconds * 100) : 0,
                'segments' => $segments,
            ];
        }

        return [
            'points' => $points,
            'granularity' => $weekly ? 'week' : 'month',
            'truncated' => $truncated,
            'hasVolume' => $maxSeconds > 0,
            // La légende : les sports qui ont au moins un segment tracé, dans
            // l'ordre d'affichage.
            'sports' => array_values(array_filter(EnduranceStats::SPORTS, static fn (ActivityType $sport): bool => isset($present[$sport->value]))),
        ];
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
