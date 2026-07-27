<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Block;
use App\Entity\PrescribedExercise;

/**
 * Seule autorité sur les liaisons de superset (`PrescribedExercise.supersetGroup`).
 *
 * Un superset n'est PAS un bloc de deux exercices : c'est, à l'intérieur d'un
 * bloc, un sous-ensemble d'exercices contigus qui s'enchaînent en alternance. Un
 * bloc peut donc mélanger des exercices isolés et plusieurs groupes liés.
 *
 * Deux invariants, tenus ici et nulle part ailleurs :
 *   1. les membres d'un groupe sont CONTIGUS en position ;
 *   2. un groupe compte au moins deux membres (un singleton est dissous).
 *
 * `normalize()` les rétablit après n'importe quelle mutation (ajout, retrait,
 * déplacement) : les numéros sont réattribués 1..n dans l'ordre d'apparition, si
 * bien qu'ils ne sont jamais à interpréter, seulement à comparer.
 *
 * Le groupe ne porte ni tours ni repos propres : c'est une pure liaison
 * d'exécution. Le nombre de tours d'un superset est déjà décrit par le nombre de
 * séries de chacun de ses exercices, et `Block.rounds` reste au bloc.
 *
 * @phpstan-type Segment array{group: int|null, label: string|null, kind: 'single'|'superset'|'circuit', exercises: list<PrescribedExercise>}
 */
final class SupersetGrouper
{
    /**
     * Découpe un bloc en segments dans l'ordre de lecture : soit un exercice
     * isolé, soit un groupe lié. Source unique consommée par PlanFlattener, le
     * compositeur et WorkoutMetrics — ne pas re-dériver ce découpage ailleurs.
     *
     * @return list<Segment>
     */
    public function segments(Block $block): array
    {
        $segments = [];
        $letter = 0;

        foreach ($this->runs($block) as $run) {
            $group = $run[0]->getSupersetGroup();

            if (null === $group || count($run) < 2) {
                foreach ($run as $exercise) {
                    $segments[] = [
                        'group' => null,
                        'label' => null,
                        'kind' => 'single',
                        'exercises' => [$exercise],
                    ];
                }

                continue;
            }

            $segments[] = [
                'group' => $group,
                'label' => $this->letter($letter++),
                'kind' => 2 === count($run) ? 'superset' : 'circuit',
                'exercises' => $run,
            ];
        }

        return $segments;
    }

    /**
     * Lie un exercice à son voisin du dessus : il rejoint le groupe de celui-ci,
     * ou un nouveau groupe est ouvert avec les deux. Sans voisin au-dessus (tête
     * de bloc), l'appel ne fait rien — c'est au voisin du dessous de se lier.
     */
    public function linkToPrevious(PrescribedExercise $exercise): void
    {
        $block = $exercise->getBlock();
        if (null === $block) {
            return;
        }

        $ordered = $this->ordered($block);
        $index = $this->indexOf($ordered, $exercise);
        if (null === $index || 0 === $index) {
            return;
        }

        $previous = $ordered[$index - 1];
        $mine = $exercise->getSupersetGroup();
        $theirs = $previous->getSupersetGroup();

        if (null !== $mine && null !== $theirs) {
            // Deux groupes qui se touchent : ils fusionnent (tout mon groupe
            // rejoint le sien), plutôt que d'abandonner mes propres liens.
            foreach ($ordered as $item) {
                if ($mine === $item->getSupersetGroup()) {
                    $item->setSupersetGroup($theirs);
                }
            }
        } else {
            $group = $theirs ?? $mine ?? $this->nextGroup($block);
            $exercise->setSupersetGroup($group);
            $previous->setSupersetGroup($group);
        }

        $this->normalize($block);
    }

    /**
     * Détache un exercice de son groupe. Il est d'abord déplacé juste après le
     * dernier membre du groupe : détacher le milieu d'un tri-set laisse les deux
     * autres liés au lieu de dissoudre l'ensemble.
     */
    public function detach(PrescribedExercise $exercise): void
    {
        $block = $exercise->getBlock();
        if (null === $block || !$exercise->isSuperset()) {
            return;
        }

        $group = $exercise->getSupersetGroup();
        $exercise->setSupersetGroup(null);

        // Réinsertion après le dernier membre restant du groupe.
        $ordered = [];
        $tail = [];
        $seenGroup = false;
        foreach ($this->ordered($block) as $item) {
            if ($item === $exercise) {
                continue;
            }
            if ($group === $item->getSupersetGroup()) {
                $seenGroup = true;
                $ordered[] = $item;
                continue;
            }
            if ($seenGroup) {
                $tail[] = $item;
                continue;
            }
            $ordered[] = $item;
        }

        $ordered[] = $exercise;
        foreach (array_merge($ordered, $tail) as $position => $item) {
            $item->setPosition($position);
        }

        $this->normalize($block);
    }

    /**
     * Règle d'appartenance après un déplacement (glisser-déposer ou flèches) :
     *   - déposé STRICTEMENT à l'intérieur d'un groupe, l'exercice le rejoint ;
     *   - sinon il garde son groupe s'il touche encore l'un de ses membres ;
     *   - sinon il en sort.
     *
     * À appeler après avoir réécrit les positions, avant le flush.
     */
    public function settleAfterMove(PrescribedExercise $moved): void
    {
        $block = $moved->getBlock();
        if (null === $block) {
            return;
        }

        $ordered = $this->ordered($block);
        $index = $this->indexOf($ordered, $moved);
        if (null === $index) {
            return;
        }

        $before = $ordered[$index - 1] ?? null;
        $after = $ordered[$index + 1] ?? null;
        $groupBefore = $before?->getSupersetGroup();
        $groupAfter = $after?->getSupersetGroup();

        if (null !== $groupBefore && $groupBefore === $groupAfter) {
            $moved->setSupersetGroup($groupBefore);
        } elseif ($moved->isSuperset()
            && $moved->getSupersetGroup() !== $groupBefore
            && $moved->getSupersetGroup() !== $groupAfter) {
            $moved->setSupersetGroup(null);
        }

        $this->normalize($block);
    }

    /**
     * Rétablit les invariants du bloc : les suites d'exercices partageant un
     * groupe sont renumérotées 1..n dans l'ordre, et tout groupe réduit à un seul
     * membre est dissous. Idempotent, sans effet sur un bloc déjà sain.
     */
    public function normalize(?Block $block): void
    {
        if (null === $block) {
            return;
        }

        $group = 0;
        foreach ($this->runs($block) as $run) {
            if (null === $run[0]->getSupersetGroup()) {
                continue;
            }

            if (count($run) < 2) {
                $run[0]->setSupersetGroup(null);
                continue;
            }

            ++$group;
            foreach ($run as $exercise) {
                $exercise->setSupersetGroup($group);
            }
        }
    }

    /**
     * Suites d'exercices consécutifs partageant le même groupe (les exercices
     * isolés forment des suites d'un seul élément). C'est ce découpage qui rend
     * la contiguïté structurelle : deux occurrences séparées d'un même numéro
     * donnent deux suites distinctes, que normalize() renumérote.
     *
     * @return list<non-empty-list<PrescribedExercise>>
     */
    private function runs(Block $block): array
    {
        $runs = [];
        $current = [];
        $currentGroup = null;

        foreach ($this->ordered($block) as $exercise) {
            $group = $exercise->getSupersetGroup();

            if ([] !== $current && null !== $group && $group === $currentGroup) {
                $current[] = $exercise;
                continue;
            }

            if ([] !== $current) {
                $runs[] = $current;
            }
            $current = [$exercise];
            $currentGroup = $group;
        }

        if ([] !== $current) {
            $runs[] = $current;
        }

        return $runs;
    }

    /**
     * Exercices du bloc triés par position. Le tri se fait en mémoire : le
     * #[ORM\OrderBy] de la collection ne joue qu'au chargement DB, pas après une
     * mutation dans la même requête.
     *
     * @return list<PrescribedExercise>
     */
    private function ordered(Block $block): array
    {
        $exercises = $block->getPrescribedExercises()->toArray();
        usort($exercises, static fn (PrescribedExercise $a, PrescribedExercise $b) => $a->getPosition() <=> $b->getPosition());

        return array_values($exercises);
    }

    /**
     * @param list<PrescribedExercise> $ordered
     */
    private function indexOf(array $ordered, PrescribedExercise $exercise): ?int
    {
        $index = array_search($exercise, $ordered, true);

        return false === $index ? null : $index;
    }

    private function nextGroup(Block $block): int
    {
        $max = 0;
        foreach ($block->getPrescribedExercises() as $exercise) {
            $max = max($max, $exercise->getSupersetGroup() ?? 0);
        }

        return $max + 1;
    }

    /**
     * A, B, … Z, puis AA, AB… (un bloc à plus de 26 groupes n'existera pas, mais
     * on ne veut pas d'étiquette vide si ça arrive).
     */
    private function letter(int $index): string
    {
        $label = '';
        do {
            $label = chr(65 + $index % 26).$label;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $label;
    }
}
