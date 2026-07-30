<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\User;
use App\Enum\SetType;
use App\Repository\LoggedSetRepository;

/**
 * « La dernière fois, j'avais fait quoi ? » et « c'est quoi mon record ? » — les
 * deux questions qu'on se pose entre deux séries, et la raison d'être de l'app
 * en séance (KL-04).
 *
 * Le service ne lit QUE le réalisé (LoggedSet), jamais le prescrit : un record
 * est un fait, pas une intention. Et il ne lit que le réalisé de l'utilisateur
 * demandé — un exercice de la bibliothèque globale est pratiqué par tout le
 * monde, son historique n'appartient qu'à celui qui l'a fait.
 *
 * Deux règles portées par les requêtes elles-mêmes (LoggedSetRepository) :
 * l'échauffement n'entre jamais dans un record ni dans une dernière
 * performance, et un exercice sauté n'apporte rien même s'il porte des séries
 * abandonnées. Même périmètre que LogMetrics, à dessein.
 *
 * @phpstan-import-type PerfRow from LoggedSetRepository
 *
 * @phpstan-type PerfSetGroup array{type: SetType, typeLabel: string|null, count: int, detail: string, effort: string, weightKg: float|null, reps: int|null, durationSeconds: int|null, firstIndex: int, lastIndex: int}
 * @phpstan-type LastPerformance array{scheduledWorkoutId: int, date: \DateTimeImmutable, sets: list<PerfSetGroup>, workingSets: int, tonnageKg: float, topWeightKg: float|null}
 * @phpstan-type BestSet array{scheduledWorkoutId: int, date: \DateTimeImmutable, weightKg: float, reps: int|null, durationSeconds: int|null, type: SetType, detail: string}
 * @phpstan-type ExerciseHistory array{last: LastPerformance|null, best: BestSet|null}
 */
final class PerformanceHistory
{
    public function __construct(
        private readonly LoggedSetRepository $sets,
        private readonly UnitFormatter $units,
    ) {
    }

    /**
     * La dernière fois que cet exercice a été fait : sa date, la séance datée
     * d'où il vient, et ses séries de travail condensées à la manière de
     * `detailedSetGroups` (les séries consécutives identiques fusionnent, le
     * rang réel est conservé).
     *
     * null quand l'exercice n'a jamais été fait : un exercice sans historique
     * n'affiche rien, pas un cadre vide.
     *
     * @return LastPerformance|null
     */
    public function lastPerformance(User $user, Exercise $exercise): ?array
    {
        $id = $exercise->getId();
        if (null === $id) {
            return null;
        }

        return $this->lastByExercise($user, [$id])[$id] ?? null;
    }

    /**
     * Le record : la série de travail la plus lourde jamais faite sur cet
     * exercice. null quand il n'a jamais été chargé (poids du corps, série en
     * durée) — il n'y a pas de record sans kilos.
     *
     * @return BestSet|null
     */
    public function bestSet(User $user, Exercise $exercise): ?array
    {
        $id = $exercise->getId();
        if (null === $id) {
            return null;
        }

        return $this->bestByExercise($user, [$id])[$id] ?? null;
    }

    /**
     * Dernière performance et record pour tout un jeu d'exercices, indexés par
     * identifiant d'exercice. **Deux requêtes, quel que soit le nombre
     * d'exercices** : le bootstrap mobile (KL-14) l'appelle sur la bibliothèque
     * entière, un N+1 le rendrait inutilisable.
     *
     * Les exercices sans aucun historique sont **absents** du tableau : rien à
     * dire n'est pas la même chose qu'un zéro, et le transporter jusqu'au
     * téléphone n'apporterait que du volume.
     *
     * @param iterable<Exercise> $exercises
     *
     * @return array<int, ExerciseHistory>
     */
    public function bulkFor(User $user, iterable $exercises): array
    {
        $ids = [];
        foreach ($exercises as $exercise) {
            $id = $exercise->getId();
            if (null !== $id) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);

        $last = $this->lastByExercise($user, $ids);
        $best = $this->bestByExercise($user, $ids);

        $history = [];
        foreach ($ids as $id) {
            if (!isset($last[$id]) && !isset($best[$id])) {
                continue;
            }

            $history[$id] = [
                'last' => $last[$id] ?? null,
                'best' => $best[$id] ?? null,
            ];
        }

        return $history;
    }

    /**
     * Une requête, puis regroupement en mémoire par exercice.
     *
     * Les lignes arrivent déjà triées séance la plus récente d'abord : on garde
     * la première séance rencontrée pour chaque exercice et on ignore les
     * suivantes. C'est ce qui départage deux séances portées par la MÊME date
     * (matin et soir) — la sous-requête ne borne que la date.
     *
     * @param list<int> $ids
     *
     * @return array<int, LastPerformance>
     */
    private function lastByExercise(User $user, array $ids): array
    {
        $byExercise = [];

        foreach ($this->sets->findLastWorkingSetsForExercises($user, $ids) as $row) {
            $exerciseId = $row['exerciseId'];

            if (!isset($byExercise[$exerciseId])) {
                $byExercise[$exerciseId] = [
                    'scheduledWorkoutId' => $row['scheduledWorkoutId'],
                    'date' => $row['date'],
                    'rows' => [],
                ];
            }

            if ($byExercise[$exerciseId]['scheduledWorkoutId'] !== $row['scheduledWorkoutId']) {
                continue;
            }

            $byExercise[$exerciseId]['rows'][] = $row;
        }

        $performances = [];
        foreach ($byExercise as $exerciseId => $performance) {
            $performances[$exerciseId] = [
                'scheduledWorkoutId' => $performance['scheduledWorkoutId'],
                'date' => $performance['date'],
                'sets' => $this->condense($performance['rows']),
                'workingSets' => \count($performance['rows']),
                'tonnageKg' => $this->tonnage($performance['rows']),
                'topWeightKg' => $this->topWeight($performance['rows']),
            ];
        }

        return $performances;
    }

    /**
     * Une requête, puis choix de la meilleure ligne par exercice.
     *
     * La requête a déjà ramené les seules séries portant la charge maximale :
     * il reste à départager les ex æquo, et une charge égale se départage aux
     * répétitions (5 × 100 kg vaut mieux que 3 × 100 kg), puis à la date la
     * plus récente. Un record battu de justesse reste le record le plus récent.
     *
     * @param list<int> $ids
     *
     * @return array<int, BestSet>
     */
    private function bestByExercise(User $user, array $ids): array
    {
        $best = [];

        foreach ($this->sets->findBestWorkingSetsForExercises($user, $ids) as $row) {
            $exerciseId = $row['exerciseId'];
            $current = $best[$exerciseId] ?? null;

            if (null !== $current && !$this->beats($row, $current)) {
                continue;
            }

            $best[$exerciseId] = $row;
        }

        return array_map(fn (array $row): array => [
            'scheduledWorkoutId' => $row['scheduledWorkoutId'],
            'date' => $row['date'],
            // Garanti non nul par la requête (weightKg IS NOT NULL) ; l'affirmer
            // ici évite de rendre le type de retour ambigu pour l'appelant.
            'weightKg' => (float) $row['weightKg'],
            'reps' => $row['reps'],
            'durationSeconds' => $row['durationSeconds'],
            'type' => $row['setType'],
            'detail' => $this->detail($row),
        ], $best);
    }

    /**
     * @param PerfRow $candidate
     * @param PerfRow $current
     */
    private function beats(array $candidate, array $current): bool
    {
        if (($candidate['reps'] ?? 0) !== ($current['reps'] ?? 0)) {
            return ($candidate['reps'] ?? 0) > ($current['reps'] ?? 0);
        }

        return $candidate['date'] > $current['date'];
    }

    /**
     * Condense les séries consécutives identiques (même type et mêmes valeurs),
     * en conservant leur rang réel — `firstIndex`/`lastIndex` permettent
     * d'afficher « 02 — 04 » sans perdre la numérotation, exactement comme
     * `PlanFlattener::detailedSetGroups` côté prescrit.
     *
     * Les rangs sont ceux des séries **de travail** : l'échauffement n'est
     * jamais remonté, il ne peut donc pas décaler la numérotation d'une lecture
     * à l'autre.
     *
     * @param list<PerfRow> $rows
     *
     * @return list<PerfSetGroup>
     */
    private function condense(array $rows): array
    {
        $groups = [];
        $position = 0;

        foreach ($rows as $row) {
            ++$position;
            $effort = $this->effort($row);
            $detail = $this->detail($row);
            $key = $row['setType']->value.'|'.$detail;

            $lastIndex = array_key_last($groups);
            if (null !== $lastIndex && $groups[$lastIndex]['key'] === $key) {
                ++$groups[$lastIndex]['count'];
                $groups[$lastIndex]['lastIndex'] = $position;
                continue;
            }

            $short = $row['setType']->shortLabel();
            $groups[] = [
                'key' => $key,
                'type' => $row['setType'],
                'typeLabel' => '' !== $short ? $short : null,
                'count' => 1,
                'detail' => $detail,
                'effort' => $effort,
                'weightKg' => $row['weightKg'],
                'reps' => $row['reps'],
                'durationSeconds' => $row['durationSeconds'],
                'firstIndex' => $position,
                'lastIndex' => $position,
            ];
        }

        // On retire la clé technique de regroupement.
        return array_map(
            static fn (array $g): array => [
                'type' => $g['type'],
                'typeLabel' => $g['typeLabel'],
                'count' => $g['count'],
                'detail' => $g['detail'],
                'effort' => $g['effort'],
                'weightKg' => $g['weightKg'],
                'reps' => $g['reps'],
                'durationSeconds' => $g['durationSeconds'],
                'firstIndex' => $g['firstIndex'],
                'lastIndex' => $g['lastIndex'],
            ],
            $groups,
        );
    }

    /**
     * L'effort d'une série sans sa charge. Le réalisé n'a pas de
     * `PrescriptionType` pour trancher entre reps et durée — il porte ses
     * valeurs, on lit celle qui est renseignée. Une série en durée pure
     * (gainage) n'a pas de répétitions.
     *
     * @param PerfRow $row
     */
    private function effort(array $row): string
    {
        if (null === $row['reps'] && null !== $row['durationSeconds']) {
            return $this->units->duration($row['durationSeconds']);
        }

        return sprintf('%s reps', $row['reps'] ?? '?');
    }

    /**
     * @param PerfRow $row
     */
    private function detail(array $row): string
    {
        $detail = $this->effort($row);

        if (null !== $row['weightKg']) {
            $detail .= ' @ '.$this->units->weight($row['weightKg']);
        }

        return $detail;
    }

    /**
     * @param list<PerfRow> $rows
     */
    private function tonnage(array $rows): float
    {
        $tonnage = 0.0;
        foreach ($rows as $row) {
            if (null !== $row['reps'] && null !== $row['weightKg']) {
                $tonnage += $row['reps'] * $row['weightKg'];
            }
        }

        return $tonnage;
    }

    /**
     * @param list<PerfRow> $rows
     */
    private function topWeight(array $rows): ?float
    {
        $top = null;
        foreach ($rows as $row) {
            $weight = $row['weightKg'];
            if (null !== $weight && (null === $top || $weight > $top)) {
                $top = $weight;
            }
        }

        return $top;
    }
}
