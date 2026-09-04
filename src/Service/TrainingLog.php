<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\LoggedExerciseRepository;
use App\Repository\LoggedSetRepository;
use App\Repository\ScheduledWorkoutRepository;

/**
 * Le JOURNAL du réalisé : la liste des séances qu'un utilisateur a réellement
 * consignées, chacune résumée en une ligne (date, titre, statut, séries,
 * tonnage, durée, RPE, sauts).
 *
 * Trois décisions le tiennent :
 *
 * - **Il ne calcule rien que `LogMetrics` ne calculerait pas.** Ce sont les
 *   mêmes chiffres, lus en SQL au lieu de l'être sur des entités hydratées :
 *   même périmètre de volume (échauffement exclu, exercice sauté exclu, série
 *   non chiffrée exclue — `LoggedSet::countsAsWorking()` et son pendant SQL),
 *   même durée (les bornes d'exécution écrites par le mobile, null dès qu'il en
 *   manque une), même RPE pondéré par série. `LogMetrics` reste le service de
 *   la séance qu'on OUVRE ; celui-ci est celui de la liste qu'on PARCOURT.
 * - **Trois requêtes d'agrégat, quelle que soit la fenêtre.** C'est la
 *   contrainte de coût du projet : « depuis le début » ne doit pas remonter
 *   l'historique entier. Une liste de 300 séances coûte ici ce que coûtaient
 *   dix séances fetch-jointes.
 * - **Une séance sans volume mesuré reste une ligne.** Une séance de mobilité
 *   consignée en séries non chiffrées a bien eu lieu ; elle affiche 0 série de
 *   travail et pas de tonnage, elle ne disparaît pas. C'est la même frontière
 *   qu'à l'écran de clôture du mobile : hors du chiffre, jamais escamotée.
 *
 * @phpstan-type LogEntry array{id: int, date: \DateTimeImmutable, title: string, status: \App\Enum\ScheduledStatus, workingSets: int, tonnageKg: float, durationSeconds: int|null, averageRpe: float|null, exercises: int, skipped: int}
 */
final readonly class TrainingLog
{
    public function __construct(
        private ScheduledWorkoutRepository $scheduledWorkouts,
        private LoggedSetRepository $loggedSets,
        private LoggedExerciseRepository $loggedExercises,
    ) {
    }

    /**
     * Les `limit` dernières séances consignées, sans borne de temps : la fiche
     * athlète du coach et tout aperçu qui montre « les dernières ».
     *
     * @return list<LogEntry>
     */
    public function recent(User $owner, int $limit): array
    {
        return $this->compose($owner, null, null, $limit);
    }

    /**
     * Toutes les séances consignées d'une fenêtre, la plus récente d'abord. La
     * fenêtre est un `StatsPeriod`, le même objet que les statistiques : une
     * seule façon de dire « quatre semaines » dans l'app, et le sélecteur se
     * partage entre les deux pages.
     *
     * @return list<LogEntry>
     */
    public function over(User $owner, StatsPeriod $period): array
    {
        return $this->compose($owner, $period->start, $period->end, null);
    }

    /**
     * La même fenêtre, groupée par mois et sous-totalisée : ce que le journal
     * affiche réellement.
     *
     * Le groupement est ici et pas dans le template, comme partout ailleurs
     * (l'historique reçoit déjà ses mois tout faits) — et les sous-totaux ne
     * coûtent rien de plus : ils se somment sur les lignes déjà chargées, sans
     * une requête supplémentaire. C'est ce qui donne la vue d'ensemble qu'une
     * liste plate ne donnait pas : combien de séances ce mois-là, pour combien
     * de tonnage.
     *
     * @return list<array{key: string, label: string, sessions: int, workingSets: int, tonnageKg: float, entries: list<LogEntry>}>
     */
    public function monthly(User $owner, StatsPeriod $period): array
    {
        $groups = [];

        foreach ($this->over($owner, $period) as $entry) {
            $key = $entry['date']->format('Y-m');

            $groups[$key] ??= [
                'key' => $key,
                'label' => StatsPeriod::monthLabel($entry['date']),
                'sessions' => 0,
                'workingSets' => 0,
                'tonnageKg' => 0.0,
                'entries' => [],
            ];

            ++$groups[$key]['sessions'];
            $groups[$key]['workingSets'] += $entry['workingSets'];
            $groups[$key]['tonnageKg'] += $entry['tonnageKg'];
            $groups[$key]['entries'][] = $entry;
        }

        return array_values($groups);
    }

    /** Le nombre total de séances consignées, toutes fenêtres confondues. */
    public function total(User $owner): int
    {
        return $this->scheduledWorkouts->countLoggedForOwner($owner);
    }

    /**
     * @return list<LogEntry>
     */
    private function compose(User $owner, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end, ?int $limit): array
    {
        $rows = $this->scheduledWorkouts->findLoggedRowsForOwner($owner, $start, $end, $limit);

        if ([] === $rows) {
            return [];
        }

        // Les deux agrégats se lisent sur la MÊME fenêtre que les lignes, pas sur
        // leurs seuls identifiants : une clause `IN` de 300 entiers ne gagnerait
        // rien et casserait sur une fenêtre plus large. Les séances hors liste
        // sont simplement ignorées à la fusion.
        $volume = $this->loggedSets->gymTotalsByScheduledForOwner($owner, $start, $end);
        $counts = $this->loggedExercises->countsByScheduledForOwner($owner, $start, $end);

        $entries = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            $vol = $volume[$id] ?? ['workingSets' => 0, 'tonnageKg' => 0.0, 'rpeSum' => 0, 'rpeCount' => 0];
            $count = $counts[$id] ?? ['exercises' => 0, 'skipped' => 0];

            $entries[] = [
                'id' => $id,
                'date' => $row['date'],
                // La règle de titrage appartient à l'entité : on la rejoue à
                // l'identique plutôt que d'en écrire une seconde en SQL.
                'title' => $row['workoutTitle'] ?? $row['ownTitle'] ?? 'Séance libre',
                'status' => $row['status'],
                'workingSets' => $vol['workingSets'],
                'tonnageKg' => $vol['tonnageKg'],
                'durationSeconds' => $this->duration($row['startedAt'], $row['endedAt']),
                'averageRpe' => $vol['rpeCount'] > 0 ? round($vol['rpeSum'] / $vol['rpeCount'], 1) : null,
                'exercises' => $count['exercises'],
                'skipped' => $count['skipped'],
            ];
        }

        return $entries;
    }

    /**
     * Même règle que `LogMetrics::durationSeconds()` : null dès qu'une borne
     * manque (une séance synchronisée en cours d'exécution n'a pas de fin), et
     * une fin antérieure au début ramenée à 0 plutôt que rendue négative.
     */
    private function duration(?\DateTimeImmutable $startedAt, ?\DateTimeImmutable $endedAt): ?int
    {
        if (null === $startedAt || null === $endedAt) {
            return null;
        }

        return max(0, $endedAt->getTimestamp() - $startedAt->getTimestamp());
    }
}
