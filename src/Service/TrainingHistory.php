<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\MuscleGroup;
use App\Repository\ExerciseRepository;
use App\Repository\LoggedSetRepository;
use App\Repository\ScheduledWorkoutRepository;

/**
 * L'historique complet en calendrier : tous les mois d'un athlète, du plus
 * récent au plus ancien, chaque jour listant les séances qui s'y sont tenues et
 * ce qui y est passé.
 *
 * **Pourquoi un service à part et pas une méthode de TrainingStats.** Ce
 * dernier est le moteur d'une *fenêtre de temps* : tout ce qu'il expose est
 * borné par un `StatsPeriod`, et ses chiffres se comparent entre fenêtres. Un
 * historique n'est pas une fenêtre — il n'a pas de borne à choisir, et il ne
 * répond pas à « combien » mais à « quand, et quoi ». Le glisser dans
 * `TrainingStats::over()` obligerait à lui passer une période dont il ne ferait
 * rien, et ferait payer la lecture de tout l'historique à chaque affichage des
 * statistiques.
 *
 * **Une case est une liste de séances, pas un compteur.** Deux séances le même
 * jour — une salle le matin, une course le soir — sont deux lignes distinctes,
 * chacune ouvrable. C'est ce qui distingue cette page d'une carte de chaleur :
 * on ne vient pas y lire une densité, on vient y retrouver une séance.
 *
 * **La source de ce qui s'affiche, et la frontière assumée.** Les groupes
 * musculaires se lisent sur le RÉALISÉ (`LoggedSet`), jamais sur le prescrit —
 * la règle du projet (cf. CLAUDE.md §3). La NATURE d'une séance, elle, se lit
 * sur son prescrit, et ce n'est pas une contradiction : une sortie course ne se
 * logue jamais, son prescrit est la seule chose qui dise que c'était une course.
 * Dire « c'était de la course » n'est pas prétendre savoir ce qui a été fait
 * dedans — c'est la seule chose que le projet accepte de lire là, et elle suffit
 * à la rendre visible plutôt que de la laisser en case creuse.
 *
 * **Coût.** Cinq lectures, aucune hydratation de séance : les bornes, les
 * séances faites, leurs activités prescrites, le volume par (séance × exercice),
 * et les définitions des seuls exercices travaillés. Les quatre premières sont
 * des projections scalaires ; la dernière est bornée par la pratique (le nombre
 * d'exercices distincts qu'on pratique), pas par la profondeur de l'historique.
 * Le nombre de requêtes est **constant**, il ne dépend pas du nombre de mois
 * affichés — un test le vérifie et fige le chiffre.
 *
 * @phpstan-type HistorySession array{id: int, title: string, activity: ActivityType|null, endurance: bool, groups: list<MuscleGroup>, workingSets: int}
 * @phpstan-type HistoryDay array{date: \DateTimeImmutable, inMonth: bool, isToday: bool, sessions: list<HistorySession>}
 * @phpstan-type HistoryMonth array{year: int, month: int, label: string, sessions: int, activeDays: int, weeks: list<list<HistoryDay>>}
 * @phpstan-type HistoryYear array{year: int, sessions: int, months: list<HistoryMonth>}
 * @phpstan-type ActivityTally array{activity: ActivityType|null, sessions: int}
 */
final class TrainingHistory
{
    /**
     * Les activités dont le réalisé ne se logue jamais (règle du projet). Une
     * séance qu'elles dominent n'a donc rien à colorer, et se dit par son icône
     * plutôt que par des pastilles vides.
     */
    private const ENDURANCE = [
        ActivityType::RUNNING,
        ActivityType::CYCLING,
        ActivityType::SWIMMING,
    ];

    public function __construct(
        private readonly ScheduledWorkoutRepository $scheduled,
        private readonly LoggedSetRepository $loggedSets,
        private readonly ExerciseRepository $exercises,
    ) {
    }

    /**
     * Tout l'historique, prêt à boucler. Le template ne calcule rien : il reçoit
     * des années qui contiennent des mois qui contiennent des semaines de sept
     * cases, et chaque case porte la liste de ses séances.
     *
     * Sans historique du tout, `years` est vide et `bounds` vaut null : c'est
     * l'état vide, à distinguer d'un historique qui existe mais ne contient que
     * des mois creux.
     *
     * @return array{years: list<HistoryYear>, totals: array{sessions: int, activeDays: int, byActivity: list<ActivityTally>}, bounds: array{first: \DateTimeImmutable, last: \DateTimeImmutable}|null}
     */
    public function calendar(User $user, ?\DateTimeImmutable $now = null): array
    {
        $today = ($now ?? new \DateTimeImmutable())->setTime(0, 0);
        $bounds = $this->scheduled->dateBoundsForOwner($user);

        if (null === $bounds) {
            return [
                'years' => [],
                'totals' => ['sessions' => 0, 'activeDays' => 0, 'byActivity' => []],
                'bounds' => null,
            ];
        }

        $sessionsByDay = $this->sessionsByDay($user);

        // La grille court du premier mois d'historique au mois courant inclus,
        // même si l'un et l'autre sont vides. Un compte qui n'a rien fait depuis
        // trois mois doit voir ses trois mois vides : c'est la réponse.
        $first = $bounds['first']->modify('first day of this month')->setTime(0, 0);
        $last = max($bounds['last'], $today)->modify('first day of this month')->setTime(0, 0);

        $months = [];
        for ($cursor = $last; $cursor >= $first; $cursor = $cursor->modify('-1 month')) {
            $months[] = $this->buildMonth($cursor, $today, $sessionsByDay);
        }

        $total = 0;
        foreach ($sessionsByDay as $day) {
            $total += \count($day);
        }

        return [
            'years' => $this->groupByYear($months),
            'totals' => [
                'sessions' => $total,
                'activeDays' => \count($sessionsByDay),
                'byActivity' => $this->tally($sessionsByDay),
            ],
            'bounds' => $bounds,
        ];
    }

    /**
     * Les séances faites, décrites et indexées par jour.
     *
     * Chaque séance est composée de trois lectures qui ne se recouvrent pas :
     * son identité (pour le lien), son activité dominante (pour sa nature) et
     * son réalisé (pour ses groupes). Une séance peut n'avoir que la première —
     * une séance libre sans réalisé, par exemple : elle s'affiche quand même,
     * parce qu'elle a eu lieu.
     *
     * @return array<string, list<HistorySession>> indexé par `Y-m-d`
     */
    private function sessionsByDay(User $user): array
    {
        $activities = $this->dominantActivities($user);
        $volume = $this->volumeByScheduled($user);

        $byDay = [];
        foreach ($this->scheduled->doneSessionsForOwner($user, null, null) as $row) {
            $activity = $activities[$row['id']] ?? null;

            $byDay[$row['date']->format('Y-m-d')][] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'activity' => $activity,
                'endurance' => null !== $activity && \in_array($activity, self::ENDURANCE, true),
                'groups' => $volume[$row['id']]['groups'] ?? [],
                'workingSets' => $volume[$row['id']]['workingSets'] ?? 0,
            ];
        }

        return $byDay;
    }

    /**
     * L'activité dominante de chaque séance faite : celle que portent le plus
     * d'exercices.
     *
     * **Deux sources, dans cet ordre : le prescrit, puis le réalisé.** Le
     * prescrit dit ce que la séance était censée être, et c'est la bonne réponse
     * dès qu'il existe — y compris pour une sortie course, qui ne logue rien.
     * Mais une séance peut n'avoir aucun prescrit : c'est le cas de tout
     * historique importé (`TrainingHistoryImporter` crée ses séances avec
     * `workout = null`, délibérément, pour ne pas inventer une intention). Son
     * réalisé prend alors le relais — sans quoi des centaines de séances de
     * salle se rangeraient sous « activité inconnue », ce qui serait faux : on
     * sait très bien ce qu'elles étaient.
     *
     * Le réalisé ne fait que **compléter**, il ne corrige jamais : une séance
     * prescrite en course dont on aurait logué du gainage reste une course. Le
     * jour où l'on voudrait le contraire, ce serait une décision à prendre, pas
     * un effet de bord à laisser arriver.
     *
     * **Une séance ne relève que d'une nature ici, et c'est un choix.** Le
     * `activityCounts` de `TrainingStats` compte au contraire une séance dans
     * CHACUNE de ses activités — il répond à « combien de mes séances ont touché
     * à la course ». Cette page répond à l'autre question : « combien de mes
     * séances SONT des séances de course », dont la somme doit valoir le nombre
     * de séances. Les deux chiffres peuvent donc différer sans se contredire, et
     * l'écran nomme le sien pour qu'on ne les confonde pas.
     *
     * Départage : à égalité d'exercices, l'ordre de déclaration de l'enum
     * tranche (la salle d'abord). Arbitraire mais stable — deux affichages de la
     * même séance ne doivent pas la ranger ailleurs.
     *
     * @return array<int, ActivityType>
     */
    private function dominantActivities(User $user): array
    {
        $prescribed = $this->pickDominant(
            $this->scheduled->doneActivityCountsForOwner($user, null, null)
        );

        $logged = $this->pickDominant(
            $this->scheduled->doneLoggedActivityCountsForOwner($user, null, null)
        );

        // `+` sur des tableaux garde la valeur de GAUCHE en cas de clé commune :
        // le prescrit prime, le réalisé ne comble que les séances qu'il ignore.
        return $prescribed + $logged;
    }

    /**
     * L'activité la plus portée de chaque séance, à partir de lignes
     * (séance, activité, nombre d'exercices). Écrit une fois pour les deux
     * sources : deux départages différents rangeraient la même séance à deux
     * endroits selon qu'elle a un prescrit ou non.
     *
     * @param list<array{scheduledId: int, activity: ActivityType, exercises: int}> $rows
     *
     * @return array<int, ActivityType>
     */
    private function pickDominant(array $rows): array
    {
        $order = [];
        foreach (ActivityType::cases() as $index => $case) {
            $order[$case->value] = $index;
        }

        $best = [];
        foreach ($rows as $row) {
            $current = $best[$row['scheduledId']] ?? null;

            if (
                null === $current
                || $row['exercises'] > $current['exercises']
                || ($row['exercises'] === $current['exercises']
                    && $order[$row['activity']->value] < $order[$current['activity']->value])
            ) {
                $best[$row['scheduledId']] = ['activity' => $row['activity'], 'exercises' => $row['exercises']];
            }
        }

        return array_map(static fn (array $row): ActivityType => $row['activity'], $best);
    }

    /**
     * Les groupes musculaires touchés par chaque séance, triés par volume
     * décroissant, et son nombre de séries de travail.
     *
     * Une série compte pour CHAQUE zone de son exercice, donc pour chaque groupe
     * que ces zones recouvrent — la même règle que `RegionBreakdown`. Un total
     * par groupe dépasse donc le nombre de séries réelles, ce qui est sans
     * conséquence ici : on ne s'en sert que pour ordonner, jamais pour afficher
     * une part. Le compteur `workingSets`, lui, est le vrai nombre de séries.
     *
     * Un exercice supprimé (`exerciseId` null) ou absent de la bibliothèque
     * compte dans `workingSets` mais ne colore rien : il n'a plus de zones à
     * donner. Son volume a bien eu lieu, on ne sait juste plus où il est allé.
     *
     * @return array<int, array{groups: list<MuscleGroup>, workingSets: int}>
     */
    private function volumeByScheduled(User $user): array
    {
        $rows = $this->loggedSets->gymTotalsByScheduledAndExerciseForOwner($user, null, null);
        $lifted = $this->liftedExercises($rows);

        /** @var array<int, array{sets: array<string, int>, workingSets: int}> $accumulated */
        $accumulated = [];

        foreach ($rows as $row) {
            $id = $row['scheduledId'];
            $accumulated[$id] ??= ['sets' => [], 'workingSets' => 0];
            $accumulated[$id]['workingSets'] += $row['workingSets'];

            $exercise = null !== $row['exerciseId'] ? ($lifted[$row['exerciseId']] ?? null) : null;

            // Un même exercice peut relever de plusieurs groupes (un soulevé de
            // terre touche dos ET jambes) : chacun est crédité une fois, sans
            // quoi deux zones du même groupe le compteraient double et
            // fausseraient l'ordre des pastilles.
            $groups = [];
            foreach ($exercise?->getTargetAreas() ?? [] as $area) {
                $groups[MuscleGroup::of($area)->value] = true;
            }

            foreach (array_keys($groups) as $group) {
                $accumulated[$id]['sets'][$group] = ($accumulated[$id]['sets'][$group] ?? 0) + $row['workingSets'];
            }
        }

        $byScheduled = [];
        foreach ($accumulated as $id => $session) {
            arsort($session['sets']);

            $byScheduled[$id] = [
                'groups' => array_values(array_map(
                    static fn(string $value): MuscleGroup => MuscleGroup::from($value),
                    array_keys($session['sets']),
                )),
                'workingSets' => $session['workingSets'],
            ];
        }

        return $byScheduled;
    }

    /**
     * La répartition des séances par nature, la plus pratiquée d'abord. Chaque
     * séance compte pour une, et une seule : la somme vaut `totals.sessions`,
     * ce qui est précisément ce qu'on attend d'un décompte.
     *
     * Restent sous une entrée à `activity` null les séances dont NI le prescrit
     * NI le réalisé ne dit la nature : une séance cochée faite et restée vide.
     * Les ranger d'office en salle ferait mentir le décompte, les taire aussi.
     * Si cette entrée pèse lourd, c'est un signal — pas une fatalité.
     *
     * @param array<string, list<HistorySession>> $sessionsByDay
     *
     * @return list<ActivityTally>
     */
    private function tally(array $sessionsByDay): array
    {
        $counts = [];
        $unknown = 0;

        foreach ($sessionsByDay as $day) {
            foreach ($day as $session) {
                if (null === $session['activity']) {
                    ++$unknown;
                    continue;
                }

                $counts[$session['activity']->value] = ($counts[$session['activity']->value] ?? 0) + 1;
            }
        }

        arsort($counts);

        $tally = [];
        foreach ($counts as $value => $sessions) {
            $tally[] = ['activity' => ActivityType::from((string) $value), 'sessions' => $sessions];
        }

        if ($unknown > 0) {
            $tally[] = ['activity' => null, 'sessions' => $unknown];
        }

        return $tally;
    }

    /**
     * Les définitions des exercices travaillés, indexées par id. Même argument
     * que `TrainingStats::liftedExercises()` : leur nombre est borné par la
     * pratique, pas par l'historique, donc « tout » ne coûte pas plus qu'un mois.
     *
     * @param list<array{scheduledId: int, exerciseId: int|null, workingSets: int}> $rows
     *
     * @return array<int, Exercise>
     */
    private function liftedExercises(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(
            array_column($rows, 'exerciseId'),
            static fn(?int $id): bool => null !== $id,
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
     * Un mois en grille dense : semaines complètes du lundi au dimanche, débords
     * du mois précédent et suivant compris, pour que les colonnes restent
     * alignées sur les jours de la semaine.
     *
     * Même calcul que `CalendarController::buildWeeks()`, mais sans hydrater
     * quoi que ce soit — cette méthode-là remonte des `ScheduledWorkout` parce
     * qu'elle sert à les manipuler, ce qui serait ici hors de prix.
     *
     * @param array<string, list<HistorySession>> $sessionsByDay
     *
     * @return HistoryMonth
     */
    private function buildMonth(\DateTimeImmutable $first, \DateTimeImmutable $today, array $sessionsByDay): array
    {
        $month = (int) $first->format('n');
        $last = $first->modify('last day of this month');

        $cursor = $first->modify(sprintf('-%d days', (int) $first->format('N') - 1));
        $gridEnd = $last->modify(sprintf('+%d days', 7 - (int) $last->format('N')));

        $todayKey = $today->format('Y-m-d');
        $sessions = 0;
        $activeDays = 0;
        $weeks = [];

        while ($cursor <= $gridEnd) {
            $week = [];
            for ($i = 0; $i < 7; ++$i) {
                $key = $cursor->format('Y-m-d');
                $inMonth = (int) $cursor->format('n') === $month;
                $daySessions = $sessionsByDay[$key] ?? [];

                // Les compteurs du mois ne comptent que ses propres jours : une
                // case de débord appartient au mois voisin, la compter deux fois
                // ferait un total annuel faux.
                if ($inMonth && [] !== $daySessions) {
                    $sessions += \count($daySessions);
                    ++$activeDays;
                }

                $week[] = [
                    'date' => $cursor,
                    'inMonth' => $inMonth,
                    'isToday' => $key === $todayKey,
                    'sessions' => $daySessions,
                ];

                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $week;
        }

        return [
            'year' => (int) $first->format('Y'),
            'month' => $month,
            'label' => StatsPeriod::monthLabel($first),
            'sessions' => $sessions,
            'activeDays' => $activeDays,
            'weeks' => $weeks,
        ];
    }

    /**
     * Regroupe les mois (déjà triés du plus récent au plus ancien) sous leur
     * année, en préservant cet ordre. L'année sert de repère de lecture quand on
     * défile : sans elle, « janvier » et « janvier » se ressemblent trop.
     *
     * @param list<HistoryMonth> $months
     *
     * @return list<HistoryYear>
     */
    private function groupByYear(array $months): array
    {
        $years = [];
        foreach ($months as $month) {
            $years[$month['year']] ??= ['year' => $month['year'], 'sessions' => 0, 'months' => []];
            $years[$month['year']]['sessions'] += $month['sessions'];
            $years[$month['year']]['months'][] = $month;
        }

        return array_values($years);
    }
}
