<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Enum\LogDeviation;

/**
 * La boucle prévu vs réalisé, alignée série par série (KL-05).
 *
 * Le prescrit arrive **par PlanFlattener** — jamais remis à plat ici : c'est la
 * même mise à plat qui rend la page, l'export et l'API, et deux lectures du
 * programme finiraient par diverger. Le réalisé arrive de la séance datée. Le
 * service ne fait que les apparier et nommer l'écart ; il n'écrit rien.
 *
 * Trois décisions qui tiennent le reste :
 *
 * 1. **L'appariement des exercices est en deux passes.**
 *    `sourcePrescribedExercise` d'abord (le lien écrit par le mobile, la seule
 *    vérité quand il existe), l'`Exercise` ensuite, « hors programme » en
 *    dernier recours. Les deux passes sont séparées parce qu'un log apparié par
 *    son exercice ne doit pas voler la ligne qu'un autre revendique par sa
 *    source — l'ordre de la collection déciderait alors du résultat.
 * 2. **Les séries s'apparient par rang, échauffement et travail séparément.**
 *    Un échauffement prescrit mais non logué (le cas courant) décalerait sinon
 *    toutes les séries de travail d'un cran, et la séance entière se lirait
 *    « allégée » alors qu'elle a été tenue.
 * 3. **L'écart se lit sur le premier axe où les deux côtés parlent et
 *    divergent** : tonnage, puis charge, puis répétitions, puis durée, puis
 *    nombre de séries. Un axe muet d'un côté ne tranche jamais — comparer une
 *    charge à une absence de charge dirait « allégé » d'une série au poids du
 *    corps. Le tonnage passe en premier parce que c'est la grandeur du projet :
 *    6 × 82,5 kg là où 8 × 80 kg étaient prévus, c'est plus lourd mais moins de
 *    travail, et c'est « allégé ».
 *
 * **Ce service est la frontière de la règle « série non chiffrée ».** Ailleurs,
 * une série cochée sans aucune valeur (ni répétition ni durée) est écartée du
 * volume — elle ne mesure rien (`LoggedSet::countsAsWorking`). Ici elle compte
 * : le comparateur ne dit pas ce qui a été soulevé, il dit ce qui a été fait
 * face à ce qui était prévu, et une série cochée A eu lieu. L'écarter du
 * décompte ferait passer une séance tenue pour « allégée d'une série ». Le
 * filtre est donc, à dessein, sur le seul `SetType`.
 *
 * @phpstan-import-type FlatPrescribed from PlanFlattener
 * @phpstan-import-type FlatSetLine from PlanFlattener
 *
 * @phpstan-type LoggedLine array{set: LoggedSet, type: \App\Enum\SetType, typeLabel: string|null, effort: string, weightKg: float|null, reps: int|null, durationSeconds: int|null, rpe: int|null}
 * @phpstan-type ComparedLine array{index: int, planned: FlatSetLine|null, logged: LoggedLine|null, status: LogDeviation}
 * @phpstan-type ComparedExercise array{name: string, exercise: Exercise|null, prescribedId: int|null, planned: FlatPrescribed|null, logged: LoggedExercise|null, status: LogDeviation, lines: list<ComparedLine>}
 * @phpstan-type Axes array{tonnageKg: float|null, weightKg: float|null, reps: int|null, durationSeconds: int|null, workingSets: int|null}
 */
final class LogComparator
{
    public function __construct(
        private readonly PlanFlattener $flattener,
        private readonly UnitFormatter $units,
    ) {
    }

    /**
     * Le prévu et le réalisé côte à côte, dans l'ordre du programme, les
     * exercices hors programme à la suite.
     *
     * Rend un tableau **vide** quand la séance ne porte aucun réalisé : il n'y a
     * alors rien à comparer, et l'affichage n'a pas à distinguer « aucun écart »
     * de « rien à dire » (même parti pris que `LogMetrics::summary()`, qui rend
     * null). Une séance sans `workout` (séance libre) est le cas symétrique :
     * tout son réalisé est hors programme.
     *
     * @return list<ComparedExercise>
     */
    public function compare(ScheduledWorkout $scheduled): array
    {
        if (!$scheduled->hasLog()) {
            return [];
        }

        $planned = $this->plannedExercises($scheduled);
        $logs = array_values($scheduled->getLoggedExercises()->toArray());

        [$matched, $unplanned] = $this->pair($planned, $logs);

        $comparison = [];
        foreach ($planned as $index => $flat) {
            $comparison[] = $this->entry($flat, $matched[$index] ?? null);
        }
        foreach ($unplanned as $logged) {
            $comparison[] = $this->entry(null, $logged);
        }

        return $comparison;
    }

    /**
     * Le programme de la séance, à plat et dans l'ordre de lecture. Les blocs
     * n'ont pas de sens ici : le réalisé est plat (cf. LogMetrics), un superset
     * est une intention qu'on ne peut pas observer après coup.
     *
     * @return list<FlatPrescribed>
     */
    private function plannedExercises(ScheduledWorkout $scheduled): array
    {
        $workout = $scheduled->getWorkout();
        if (null === $workout) {
            return [];
        }

        $planned = [];
        foreach ($this->flattener->flattenWorkout($workout)['blocks'] as $block) {
            foreach ($block['exercises'] as $flat) {
                $planned[] = $flat;
            }
        }

        return $planned;
    }

    /**
     * Appariement en deux passes (cf. l'en-tête de classe).
     *
     * @param list<FlatPrescribed>  $planned
     * @param list<LoggedExercise>  $logs
     *
     * @return array{array<int, LoggedExercise>, list<LoggedExercise>} appariés par rang du prescrit, puis les hors programme
     */
    private function pair(array $planned, array $logs): array
    {
        $matched = [];
        $deferred = [];

        // Passe 1 — le lien explicite. Un `sourcePrescribedExercise` qui ne
        // désigne rien dans CETTE séance (ligne déplacée dans un autre bloc,
        // séance source remplacée, SET NULL après édition) retombe
        // naturellement sur la passe 2.
        foreach ($logs as $logged) {
            $index = $this->firstFree(
                $planned,
                $matched,
                fn (array $flat): bool => $this->same($flat['prescribed'], $logged->getSourcePrescribedExercise()),
            );

            if (null !== $index) {
                $matched[$index] = $logged;
                continue;
            }

            $deferred[] = $logged;
        }

        // Passe 2 — le même exercice de bibliothèque, première ligne libre. Un
        // exercice supprimé de la bibliothèque (SET NULL) n'a plus rien à
        // apparier : il ne reste que son nom en snapshot, qui ne prouve rien.
        $unplanned = [];
        foreach ($deferred as $logged) {
            $index = $this->firstFree(
                $planned,
                $matched,
                fn (array $flat): bool => $this->same($flat['exercise'], $logged->getExercise()),
            );

            if (null !== $index) {
                $matched[$index] = $logged;
                continue;
            }

            $unplanned[] = $logged;
        }

        return [$matched, $unplanned];
    }

    /**
     * Rang de la première ligne du programme encore libre qui satisfait le
     * critère. « Encore libre » compte : deux séries du même exercice dans une
     * séance sont deux lignes distinctes, la seconde exécution va sur la seconde.
     *
     * @param list<FlatPrescribed>          $planned
     * @param array<int, LoggedExercise>    $matched
     * @param callable(FlatPrescribed): bool $matches
     */
    private function firstFree(array $planned, array $matched, callable $matches): ?int
    {
        foreach ($planned as $index => $flat) {
            if (!isset($matched[$index]) && $matches($flat)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Deux références désignent-elles la même ligne ? L'identité d'objet suffit
     * dans la quasi-totalité des cas — Doctrine ne rend qu'une instance par
     * entité, proxy compris. L'identifiant n'est qu'un repli, et son absence
     * (entité pas encore persistée) ne rapproche jamais deux instances
     * distinctes.
     */
    private function same(PrescribedExercise|Exercise|null $left, PrescribedExercise|Exercise|null $right): bool
    {
        if (null === $left || null === $right) {
            return false;
        }

        if ($left === $right) {
            return true;
        }

        $id = $left->getId();

        return null !== $id && $id === $right->getId();
    }

    /**
     * @param FlatPrescribed|null $flat
     *
     * @return ComparedExercise
     */
    private function entry(?array $flat, ?LoggedExercise $logged): array
    {
        $lines = $this->compareLines($flat['setLines'] ?? null, $logged);

        return [
            // Le snapshot du nom, qui survit à une suppression en bibliothèque.
            // Ce n'est PLUS ce qui s'affiche par défaut : la vue le passe en
            // repli à `exercise_name()`, qui préfère le nom vivant tant que la
            // référence existe — sans quoi une séance faite avant la bascule de
            // langue resterait écrite en français au milieu d'un écran anglais.
            'name' => $logged?->getExerciseName()
                ?? (null !== $flat ? $flat['exercise']?->getName() : null)
                ?? 'Exercice',
            // La référence vivante, quand il en reste une (FK en SET NULL).
            'exercise' => $logged?->getExercise()
                ?? (null !== $flat ? $flat['exercise'] : null),
            'prescribedId' => null !== $flat ? $flat['prescribed']->getId() : null,
            'planned' => $flat,
            'logged' => $logged,
            'status' => $this->exerciseStatus($flat, $logged, $lines),
            'lines' => $lines,
        ];
    }

    /**
     * @param FlatPrescribed|null  $flat
     * @param list<ComparedLine>   $lines
     */
    private function exerciseStatus(?array $flat, ?LoggedExercise $logged, array $lines): LogDeviation
    {
        if (null === $flat) {
            return LogDeviation::UNPLANNED;
        }

        if (null === $logged) {
            return LogDeviation::NOT_LOGGED;
        }

        // Sauté volontairement : l'état est déclaré, il ne se déduit pas de ce
        // qui reste dans les séries (elles peuvent avoir été saisies puis
        // abandonnées, cf. LogMetrics).
        if ($logged->isSkipped()) {
            return LogDeviation::SKIPPED;
        }

        // Prescrit sans séries à apparier (cardio, AMRAP, for time) : l'exercice
        // a été fait, l'écart n'est pas mesurable. On ne l'invente pas.
        if (null === ($flat['setLines'] ?? null)) {
            return LogDeviation::HELD;
        }

        return $this->deviation($this->plannedTotals($lines), $this->loggedTotals($lines));
    }

    /**
     * Alignement des séries. Le prescrit donne l'ordre d'affichage ; ce que le
     * réalisé porte en plus vient à la suite.
     *
     * @param list<FlatSetLine>|null $plannedLines
     *
     * @return list<ComparedLine>
     */
    private function compareLines(?array $plannedLines, ?LoggedExercise $logged): array
    {
        $loggedSets = null !== $logged ? array_values($logged->getLoggedSets()->toArray()) : [];

        // Rien à apparier côté prescrit : le réalisé se déroule seul, sans être
        // peint en écart — il n'y avait pas de série à tenir.
        if (null === $plannedLines) {
            $lines = [];
            foreach ($loggedSets as $index => $set) {
                $lines[] = [
                    'index' => $index + 1,
                    'planned' => null,
                    'logged' => $this->loggedLine($set),
                    'status' => LogDeviation::HELD,
                ];
            }

            return $lines;
        }

        // Deux files distinctes : un échauffement prévu et non logué ne doit pas
        // décaler les séries de travail (cf. l'en-tête de classe).
        $queues = ['work' => [], 'warmup' => []];
        foreach ($loggedSets as $set) {
            $queues[$set->getSetType()->countsAsWorking() ? 'work' : 'warmup'][] = $set;
        }

        $cursors = ['work' => 0, 'warmup' => 0];
        $pairedIds = [];
        $lines = [];
        $index = 0;

        foreach ($plannedLines as $planned) {
            $queue = $planned['type']->countsAsWorking() ? 'work' : 'warmup';
            $set = $queues[$queue][$cursors[$queue]++] ?? null;

            if (null !== $set) {
                $pairedIds[spl_object_id($set)] = true;
            }

            $lines[] = [
                'index' => ++$index,
                'planned' => $planned,
                'logged' => null !== $set ? $this->loggedLine($set) : null,
                'status' => null !== $set
                    ? $this->deviation($this->plannedAxes($planned), $this->loggedAxes($set))
                    : LogDeviation::NOT_LOGGED,
            ];
        }

        // Le surplus reprend l'ordre d'exécution, pas celui des files.
        foreach ($loggedSets as $set) {
            if (isset($pairedIds[spl_object_id($set)])) {
                continue;
            }

            $lines[] = [
                'index' => ++$index,
                'planned' => null,
                'logged' => $this->loggedLine($set),
                'status' => LogDeviation::UNPLANNED,
            ];
        }

        return $lines;
    }

    /**
     * Une série réalisée sous la même forme qu'une série prescrite (`FlatSetLine`),
     * plus son RPE et son entité. C'est ce qui permet à la colonne « Réalisé » de
     * se rendre avec le même fragment que la colonne « Prévu » : le composant se
     * paramètre, il ne se duplique pas.
     *
     * @return LoggedLine
     */
    private function loggedLine(LoggedSet $set): array
    {
        $short = $set->getSetType()->shortLabel();

        return [
            'set' => $set,
            'type' => $set->getSetType(),
            'typeLabel' => '' !== $short ? $short : null,
            'effort' => $this->effort($set),
            'weightKg' => $set->getWeightKg(),
            'reps' => $set->getReps(),
            'durationSeconds' => $set->getDurationSeconds(),
            'rpe' => $set->getRpe(),
        ];
    }

    /**
     * L'effort d'une série réalisée, sans sa charge. Le réalisé n'a pas de
     * `PrescriptionType` pour trancher entre répétitions et durée : il porte ses
     * valeurs, on lit celle qui est renseignée. Même règle que
     * PerformanceHistory, appliquée à l'entité au lieu de la projection scalaire.
     */
    private function effort(LoggedSet $set): string
    {
        if (null === $set->getReps() && null !== $set->getDurationSeconds()) {
            return $this->units->duration($set->getDurationSeconds());
        }

        return sprintf('%s reps', $set->getReps() ?? '?');
    }

    /**
     * Nomme l'écart entre deux jeux de valeurs, du plus significatif au plus
     * accessoire. Un axe muet d'un côté ne tranche pas — l'absence de valeur
     * n'est pas un zéro.
     *
     * @param Axes $planned
     * @param Axes $logged
     */
    private function deviation(array $planned, array $logged): LogDeviation
    {
        foreach (['tonnageKg', 'weightKg', 'reps', 'durationSeconds', 'workingSets'] as $axis) {
            $left = $planned[$axis];
            $right = $logged[$axis];

            if (null === $left || null === $right) {
                continue;
            }

            // Comparaison numérique (et non stricte) : un axe vaut tantôt un int,
            // tantôt un float selon qu'il vient d'un compteur ou d'un tonnage.
            $order = $right <=> $left;
            if (0 === $order) {
                continue;
            }

            return $order > 0 ? LogDeviation::EXCEEDED : LogDeviation::LIGHTENED;
        }

        return LogDeviation::HELD;
    }

    /**
     * Les axes d'une série prescrite. L'échauffement n'a pas de tonnage, ici
     * comme partout ailleurs dans le projet.
     *
     * @param FlatSetLine $line
     *
     * @return Axes
     */
    private function plannedAxes(array $line): array
    {
        return [
            'tonnageKg' => $this->tonnage($line['type']->countsAsWorking(), $line['reps'], $line['weightKg']),
            'weightKg' => $line['weightKg'],
            'reps' => $line['reps'],
            'durationSeconds' => $line['durationSeconds'],
            // Muet à l'échelle d'une série : c'est un total, il ne compare rien ici.
            'workingSets' => null,
        ];
    }

    /**
     * @return Axes
     */
    private function loggedAxes(LoggedSet $set): array
    {
        return [
            'tonnageKg' => $this->tonnage($set->getSetType()->countsAsWorking(), $set->getReps(), $set->getWeightKg()),
            'weightKg' => $set->getWeightKg(),
            'reps' => $set->getReps(),
            'durationSeconds' => $set->getDurationSeconds(),
            'workingSets' => null,
        ];
    }

    /**
     * Totaux du prescrit d'un exercice, sommés sur les séries de travail : c'est
     * à cette échelle que se décide l'état de l'exercice, une série à une série
     * ne dirait rien d'une séance où l'on a permuté du volume.
     *
     * @param list<ComparedLine> $lines
     *
     * @return Axes
     */
    private function plannedTotals(array $lines): array
    {
        $axes = [];
        foreach ($lines as $line) {
            if (null !== $line['planned'] && $line['planned']['type']->countsAsWorking()) {
                $axes[] = $this->plannedAxes($line['planned']);
            }
        }

        return $this->sum($axes);
    }

    /**
     * @param list<ComparedLine> $lines
     *
     * @return Axes
     */
    private function loggedTotals(array $lines): array
    {
        $axes = [];
        foreach ($lines as $line) {
            if (null !== $line['logged'] && $line['logged']['type']->countsAsWorking()) {
                $axes[] = $this->loggedAxes($line['logged']['set']);
            }
        }

        return $this->sum($axes);
    }

    /**
     * Agrège des séries en un jeu d'axes comparable. Les efforts se somment
     * (tonnage, répétitions, durée), la charge se prend au plus lourd — c'est la
     * métrique de progression du projet (cf. `getTopWeightKg`), l'additionner
     * n'aurait aucun sens.
     *
     * Un axe reste null quand aucune série ne le renseigne : il restera muet
     * dans la comparaison au lieu d'y entrer comme un zéro.
     *
     * @param list<Axes> $axes
     *
     * @return Axes
     */
    private function sum(array $axes): array
    {
        $total = [
            'tonnageKg' => null,
            'weightKg' => null,
            'reps' => null,
            'durationSeconds' => null,
            'workingSets' => \count($axes),
        ];

        foreach ($axes as $one) {
            foreach (['tonnageKg', 'reps', 'durationSeconds'] as $axis) {
                if (null !== $one[$axis]) {
                    $total[$axis] = ($total[$axis] ?? 0) + $one[$axis];
                }
            }

            if (null !== $one['weightKg']) {
                $total['weightKg'] = max($total['weightKg'] ?? $one['weightKg'], $one['weightKg']);
            }
        }

        return $total;
    }

    /**
     * Tonnage d'une série (répétitions × charge), 0 si elle n'est pas chiffrée
     * ainsi — une série au poids du corps ou en durée n'a pas de tonnage, et
     * l'échauffement n'entre jamais dans le volume. Même règle que
     * `LoggedSet::getTonnageKg()`, appliquée aussi au prescrit.
     */
    private function tonnage(bool $working, ?int $reps, ?float $weightKg): float
    {
        if (!$working || null === $reps || null === $weightKg) {
            return 0.0;
        }

        return $reps * $weightKg;
    }
}
