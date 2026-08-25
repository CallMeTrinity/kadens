<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\RecordMetric;
use App\Enum\StrengthRecord;
use App\Repository\ExerciseRepository;

/**
 * Les records de force de la fiche athlète, réconciliés avec le réalisé.
 *
 * Le problème qu'il résout : un record saisi une fois vieillit. On annonce
 * 140 kg au squat, on en passe 145 en séance, et la fiche continue d'afficher
 * 140 — la page qui sert justement à dire où on en est devient la dernière à
 * le savoir. Chaque case est donc reliée à un exercice de la bibliothèque
 * (`StrengthRecord::refKeys()`), et le réalisé la met à jour tout seul.
 *
 * **La valeur saisie est un plancher, jamais un plafond.** Elle porte ce qui
 * s'est passé avant l'app ou hors séance loguée, et reste éditable pour ça. Le
 * record affiché est le maximum des deux, et la ligne dit d'où il vient — un
 * chiffre mesuré porte sa date et son effort, un chiffre déclaré ne porte rien.
 * À égalité, le mesuré gagne : même chiffre, mais daté.
 *
 * **Aucune estimation.** Un 5 × 120 ne devient pas un 1RM de 140 : le service
 * ne compare que des charges réellement soulevées, des répétitions réellement
 * enchaînées et des temps réellement tenus. Conséquence assumée : un maximal jamais tenté en séance reste au
 * chiffre déclaré, et une série lourde en reps peut faire le record avec ses
 * répétitions écrites à côté — c'est ce que la note sert à dire.
 *
 * Périmètre hérité de `PerformanceHistory`, et c'est le même « record » qu'à la
 * fiche d'un exercice ou dans le compositeur : réalisé seulement, échauffement
 * exclu, exercice sauté exclu, série non chiffrée exclue.
 *
 * **Quatre requêtes, quel que soit le nombre de cases** : la résolution des
 * clés, puis un record par métrique (charge, répétitions, durée).
 *
 * @phpstan-type RecordRow array{label: string, value: string|null, note: string|null, exerciseId: int|null, derived: bool}
 */
final class AthleteRecords
{
    public function __construct(
        private readonly ExerciseRepository $exercises,
        private readonly PerformanceHistory $history,
        private readonly UnitFormatter $units,
    ) {
    }

    /**
     * Le bloc « Force » de la fiche, prêt à l'affichage, et le total SBD qui en
     * découle.
     *
     * Le total est rendu à part parce qu'il ne sert pas qu'à s'afficher : le
     * score DOTS s'en déduit, et il doit se calculer sur les records
     * **effectifs**. Un squat battu en séance change le total, donc le DOTS —
     * sinon la fiche afficherait un total que ses propres lignes contredisent.
     *
     * @return array{rows: list<RecordRow>, sbdTotalKg: float|null}
     */
    public function strengthFor(User $user): array
    {
        $effective = $this->effective($user);

        $rows = [];
        foreach (StrengthRecord::cases() as $record) {
            $rows[] = $this->row($record, $effective[$record->value]);

            // Le total se lit juste sous les trois lifts qui le composent : il
            // les résume, il ne se cherche pas en bas de carte.
            if (StrengthRecord::DEADLIFT === $record) {
                $rows[] = $this->totalRow($effective);
            }
        }

        return [
            'rows' => $rows,
            'sbdTotalKg' => $this->sbdTotalKg($effective),
        ];
    }

    /**
     * Le record effectif de chaque case : la valeur retenue, sa provenance, et
     * l'exercice qui l'a produite quand elle vient du réalisé.
     *
     * Une case n'a **jamais** deux valeurs : `value` est le maximum du déclaré
     * et du mesuré, et `exerciseId` n'est renseigné que si c'est le mesuré qui
     * l'emporte. Un `exerciseId` nul ne veut donc pas dire « exercice
     * introuvable » mais « rien de logué ne bat ce qui est déclaré ».
     *
     * @return array<string, array{value: float|null, exerciseId: int|null, date: \DateTimeImmutable|null, detail: string|null}>
     */
    private function effective(User $user): array
    {
        $ids = $this->exercises->idsByRefKey($this->allRefKeys());

        // Les trois lectures sont bornées aux exercices réellement reliés : sans
        // clé résolue (base non importée), aucune requête n'est envoyée.
        $linked = array_values(array_unique(array_values($ids)));
        $best = [
            RecordMetric::LOAD->value => $this->history->bestSetsForIds($user, $linked),
            RecordMetric::REPS->value => $this->history->bestRepMaxesForIds($user, $linked),
            RecordMetric::HOLD->value => $this->history->bestHoldsForIds($user, $linked),
        ];

        $effective = [];
        foreach (StrengthRecord::cases() as $record) {
            $declared = $this->declared($user, $record);
            $measured = null;

            // Une case à plusieurs clés garde la meilleure, et retient l'exercice
            // qui l'a produite : c'est lui que la ligne ouvrira, pas le premier
            // de la liste.
            foreach ($record->refKeys() as $key) {
                $exerciseId = $ids[$key] ?? null;
                if (null === $exerciseId) {
                    continue;
                }

                $candidate = $this->candidate($record, $exerciseId, $best[$record->metric()->value][$exerciseId] ?? null);

                if (null !== $candidate && (null === $measured || $candidate['value'] > $measured['value'])) {
                    $measured = $candidate;
                }
            }

            // À valeur égale, c'est le MESURÉ qui l'emporte. Le chiffre ne bouge
            // pas, mais il gagne sa date et son lien vers la séance — c'est
            // strictement plus d'information, et c'est le cas courant chez qui
            // tenait sa fiche à jour à la main avant que l'app le fasse.
            $effective[$record->value] = null !== $measured && (null === $declared || $measured['value'] >= $declared)
                ? $measured
                : ['value' => $declared, 'exerciseId' => null, 'date' => null, 'detail' => null];
        }

        return $effective;
    }

    /**
     * Le record d'un exercice, ramené à la forme commune (une valeur, une date,
     * un détail) quelle que soit la métrique. C'est ce qui permet ensuite de
     * comparer au déclaré avec un seul `>=`.
     *
     * Le `detail` n'est écrit que quand il ajoute quelque chose : sur une
     * charge, les répétitions disent ce que le chiffre vaut ; sur un compte ou
     * un temps, la valeur EST déjà l'effort, le répéter serait du bruit.
     *
     * @param array<string, mixed>|null $best BestSet, BestRepMax ou BestHold
     *
     * @return array{value: float, exerciseId: int, date: \DateTimeImmutable, detail: string|null}|null
     */
    private function candidate(StrengthRecord $record, int $exerciseId, ?array $best): ?array
    {
        if (null === $best) {
            return null;
        }

        [$value, $detail] = match ($record->metric()) {
            RecordMetric::LOAD => [(float) $best['weightKg'], $this->reps(isset($best['reps']) ? (int) $best['reps'] : null)],
            RecordMetric::REPS => [(float) $best['reps'], null],
            RecordMetric::HOLD => [(float) $best['durationSeconds'], null],
        };

        /** @var \DateTimeImmutable $date */
        $date = $best['date'];

        return [
            'value' => $value,
            'exerciseId' => $exerciseId,
            'date' => $date,
            'detail' => $detail,
        ];
    }

    /**
     * @param array{value: float|null, exerciseId: int|null, date: \DateTimeImmutable|null, detail: string|null} $effective
     *
     * @return RecordRow
     */
    private function row(StrengthRecord $record, array $effective): array
    {
        $value = $effective['value'];

        return [
            'label' => $record->getLabel(),
            'value' => null === $value ? null : match ($record->metric()) {
                RecordMetric::LOAD => $this->units->weight($value),
                RecordMetric::REPS => $this->reps((int) $value),
                RecordMetric::HOLD => $this->units->duration((int) $value),
            },
            'note' => $this->note($effective),
            'exerciseId' => $effective['exerciseId'],
            'derived' => false,
        ];
    }

    /**
     * La note d'une ligne : ce qui a été fait, et quand. Rendue **seulement**
     * pour un record mesuré — son absence est ce qui dit « valeur déclarée »,
     * et l'écrire partout ferait du bruit sur une fiche qui se lit en diagonale.
     *
     * @param array{value: float|null, exerciseId: int|null, date: \DateTimeImmutable|null, detail: string|null} $effective
     */
    private function note(array $effective): ?string
    {
        $date = $effective['date'];
        if (null === $date) {
            return null;
        }

        $detail = $effective['detail'];

        return null === $detail
            ? $date->format('d/m/y')
            : $detail.' · '.$date->format('d/m/y');
    }

    private function reps(?int $reps): string
    {
        return null === $reps ? '? rep' : sprintf('%d rep%s', $reps, $reps > 1 ? 's' : '');
    }

    /**
     * @param array<string, array{value: float|null, exerciseId: int|null, date: \DateTimeImmutable|null, detail: string|null}> $effective
     *
     * @return RecordRow
     */
    private function totalRow(array $effective): array
    {
        $total = $this->sbdTotalKg($effective);

        return [
            'label' => 'Total SBD',
            'value' => null === $total ? null : $this->units->weight($total),
            'note' => null,
            'exerciseId' => null,
            'derived' => true,
        ];
    }

    /**
     * Squat + développé couché + soulevé de terre, sur les records effectifs.
     * null dès qu'un des trois manque : une somme partielle se comparerait à un
     * total complet, et le score DOTS qui en découle serait faux sans le dire.
     *
     * @param array<string, array{value: float|null, exerciseId: int|null, date: \DateTimeImmutable|null, detail: string|null}> $effective
     */
    private function sbdTotalKg(array $effective): ?float
    {
        $total = 0.0;
        foreach (StrengthRecord::sbd() as $record) {
            $value = $effective[$record->value]['value'] ?? null;
            if (null === $value) {
                return null;
            }

            $total += $value;
        }

        return $total;
    }

    private function declared(User $user, StrengthRecord $record): ?float
    {
        $value = match ($record) {
            StrengthRecord::SQUAT => $user->getSquat1rmKg(),
            StrengthRecord::BENCH => $user->getBench1rmKg(),
            StrengthRecord::DEADLIFT => $user->getDeadlift1rmKg(),
            StrengthRecord::OHP => $user->getOhp1rmKg(),
            StrengthRecord::WEIGHTED_PULLUP => $user->getWeightedPullupKg(),
            StrengthRecord::PULLUPS => $user->getMaxPullups(),
            StrengthRecord::PUSHUPS => $user->getMaxPushups(),
            StrengthRecord::DIPS => $user->getMaxDips(),
            StrengthRecord::DEADHANG => $user->getDeadhangSeconds(),
        };

        return null === $value ? null : (float) $value;
    }

    /**
     * @return list<string>
     */
    private function allRefKeys(): array
    {
        $keys = [];
        foreach (StrengthRecord::cases() as $record) {
            foreach ($record->refKeys() as $key) {
                $keys[$key] = $key;
            }
        }

        return array_values($keys);
    }
}
