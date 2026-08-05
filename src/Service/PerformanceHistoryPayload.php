<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\User;

/**
 * La forme JSON de l'historique d'un exercice — dernière performance, record, et
 * la trajectoire des dernières séances (KL-17).
 *
 * ## Pourquoi ce service existe alors que `PerformanceHistory` suffisait
 *
 * `PerformanceHistory` (KL-04) rend des `SetType`, des `DateTimeImmutable` et un
 * `detail` déjà formaté : c'est une structure PHP, pas une réponse. La traduire
 * en JSON était déjà faite dans `BootstrapPayload`, et l'endpoint de ce ticket
 * l'aurait refaite — deux écritures de « à quoi ressemble une dernière perf »
 * qui n'auraient divergé qu'un jour, silencieusement, sur un client qui n'a
 * qu'un désérialiseur. Même raison d'être que `ScheduledWorkoutPayload` pour la
 * séance datée : **un seul producteur par structure**.
 *
 * L'entrée de l'endpoint est donc, au champ `sessions` près, exactement une
 * entrée du tableau `history` du bootstrap.
 *
 * ## Ce que la charge utile ne porte pas
 *
 * **Aucun identifiant de séance.** Une séance datée s'adresse par son `uuid`
 * partout ailleurs dans l'API, et l'historique n'a pas vocation à ouvrir une
 * séance : c'est une trajectoire, une suite de points datés. Deux séances du
 * même jour (matin et soir) restent donc deux entrées distinctes, départagées
 * par leur rang dans la liste et non par une clé.
 *
 * **Aucune valeur formatée**, sauf ce qui l'était déjà : le téléphone peint ses
 * chiffres avec ses propres unités (§0.4 du cadrage). Les séries partent en
 * `reps` / `weightKg` / `durationSeconds`.
 *
 * @phpstan-import-type BestSet from PerformanceHistory
 * @phpstan-import-type LastPerformance from PerformanceHistory
 *
 * @phpstan-type ApiPerformance array{date: string, workingSets: int, tonnageKg: float, topWeightKg: float|null, sets: list<array{type: string, count: int, reps: int|null, weightKg: float|null, durationSeconds: int|null, firstIndex: int, lastIndex: int}>}
 * @phpstan-type ApiBestSet array{date: string, type: string, reps: int|null, weightKg: float, durationSeconds: int|null}
 * @phpstan-type ApiHistoryEntry array{exerciseId: int, last: ApiPerformance|null, best: ApiBestSet|null}
 * @phpstan-type ApiExerciseHistory array{exerciseId: int, last: ApiPerformance|null, best: ApiBestSet|null, sessions: list<ApiPerformance>}
 */
final class PerformanceHistoryPayload
{
    /**
     * Combien de séances remontent avec la fiche d'un exercice. Dix, parce que
     * c'est ce qu'on lit en séance sans faire défiler : la question entre deux
     * séries est « je progresse ? », pas « qu'ai-je fait en janvier ? ».
     */
    public const int RECENT_SESSIONS = 10;

    public function __construct(
        private readonly PerformanceHistory $history,
    ) {
    }

    /**
     * L'historique complet d'un exercice pour un utilisateur.
     *
     * `last` est **dérivé de `sessions`**, pas relu : c'est la même chose lue par
     * la même requête, et le déduire supprime à la fois une lecture et la
     * possibilité qu'ils se contredisent. Le champ reste exposé parce que le
     * client l'a déjà dans son bootstrap : le retirer l'obligerait à traiter la
     * fiche d'exercice autrement que le reste.
     *
     * @return ApiExerciseHistory
     */
    public function build(User $user, Exercise $exercise, int $limit = self::RECENT_SESSIONS): array
    {
        $sessions = $this->history->recentSessions($user, $exercise, $limit);

        return [
            'exerciseId' => (int) $exercise->getId(),
            'last' => isset($sessions[0]) ? self::performance($sessions[0]) : null,
            'best' => self::best($this->history->bestSet($user, $exercise)),
            'sessions' => array_map(self::performance(...), $sessions),
        ];
    }

    /**
     * Une entrée du tableau `history` du bootstrap (KL-14) : le dernier point et
     * le record, sans la trajectoire.
     *
     * **Une liste, jamais un objet indexé par identifiant d'exercice** — d'où le
     * champ `exerciseId` porté par l'entrée elle-même. `json_encode` rend un
     * tableau PHP en objet ou en liste selon ses clés, et la bascule serait
     * silencieuse.
     *
     * @param LastPerformance|null $last
     * @param BestSet|null         $best
     *
     * @return ApiHistoryEntry
     */
    public static function entry(int $exerciseId, ?array $last, ?array $best): array
    {
        return [
            'exerciseId' => $exerciseId,
            'last' => null === $last ? null : self::performance($last),
            'best' => self::best($best),
        ];
    }

    /**
     * Une séance résumée : sa date, ses agrégats, et ses séries de travail
     * condensées — séries consécutives identiques fusionnées, rang réel conservé
     * (`firstIndex`/`lastIndex`), exactement comme le prescrit.
     *
     * @param LastPerformance $performance
     *
     * @return ApiPerformance
     */
    private static function performance(array $performance): array
    {
        return [
            'date' => $performance['date']->format('Y-m-d'),
            'workingSets' => $performance['workingSets'],
            'tonnageKg' => $performance['tonnageKg'],
            'topWeightKg' => $performance['topWeightKg'],
            'sets' => array_map(static fn (array $group): array => [
                'type' => $group['type']->value,
                'count' => $group['count'],
                'reps' => $group['reps'],
                'weightKg' => $group['weightKg'],
                'durationSeconds' => $group['durationSeconds'],
                'firstIndex' => $group['firstIndex'],
                'lastIndex' => $group['lastIndex'],
            ], $performance['sets']),
        ];
    }

    /**
     * @param BestSet|null $best
     *
     * @return ApiBestSet|null
     */
    private static function best(?array $best): ?array
    {
        return null === $best ? null : [
            'date' => $best['date']->format('Y-m-d'),
            'type' => $best['type']->value,
            'reps' => $best['reps'],
            'weightKg' => $best['weightKg'],
            'durationSeconds' => $best['durationSeconds'],
        ];
    }
}
