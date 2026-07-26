<?php

namespace App\Service;

use App\Entity\PrescribedExercise;
use App\Entity\PrescribedSet;
use App\Enum\SetType;

/**
 * Tient le compteur scalaire `sets` et la collection `PrescribedSet` d'accord.
 *
 * Les deux modes décrivent la même chose (« combien de séries de travail ? ») et
 * doivent donner le même nombre, quel que soit le mode où on le change. Avant, la
 * collection primait en lecture mais n'écrivait jamais dans le scalaire : revenir
 * au mode simple après avoir ajouté deux séries reperdait les deux.
 *
 * Sémantique : le scalaire compte les séries **de travail**, l'échauffement exclu
 * (`SetType::countsAsWorking()`), pour rester aligné sur `getWorkingSetCount()` et
 * sur le volume affiché partout ailleurs dans l'app.
 */
final class SetSynchronizer
{
    /**
     * Écrit le décompte de travail dans le scalaire. À appeler après toute mutation
     * de la collection (ajout, suppression, changement de type de série : passer une
     * ligne en échauffement la sort du décompte).
     */
    public function syncScalarFromDetailed(PrescribedExercise $prescribed): void
    {
        if ($prescribed->hasDetailedSets()) {
            $prescribed->setSets($prescribed->getWorkingSetCount());
        }
    }

    /**
     * Amène la collection à `$target` séries de travail : ajoute des lignes NORMAL
     * en fin (recopiées de la dernière ligne de travail, comme le fait le bouton
     * « Ajouter une série ») ou retire les dernières lignes de travail.
     *
     * L'échauffement n'est jamais touché : il ne compte pas, donc descendre le
     * compteur ne doit pas le supprimer.
     *
     * @return list<PrescribedSet> les séries créées, à persister par l'appelant
     */
    public function applyScalarToDetailed(PrescribedExercise $prescribed, int $target): array
    {
        if (!$prescribed->hasDetailedSets()) {
            return [];
        }

        $target = max(0, $target);
        $working = $this->workingSets($prescribed);
        $created = [];

        for ($i = count($working); $i < $target; ++$i) {
            $set = $this->newWorkingSetFrom($prescribed);
            $prescribed->addDetailedSet($set);
            $created[] = $set;
        }

        // Retrait par la fin, séries de travail uniquement.
        for ($i = count($working) - 1; $i >= $target; --$i) {
            $prescribed->removeDetailedSet($working[$i]);
        }

        $this->renumber($prescribed);

        return $created;
    }

    /**
     * Renumérote les positions de 0 à n-1 en conservant l'ordre courant. Les
     * suppressions et insertions laissent sinon des trous, et l'éditeur trie sur
     * `position`.
     */
    public function renumber(PrescribedExercise $prescribed): void
    {
        $sets = $this->sorted($prescribed);
        foreach ($sets as $index => $set) {
            $set->setPosition($index);
        }
    }

    /**
     * Nouvelle série de travail calquée sur la dernière (valeurs reprises), sinon
     * sur les scalaires de l'exercice. Toujours NORMAL : ajouter une série, c'est
     * ajouter du travail — recopier un échauffement ne ferait pas monter le
     * décompte et rendrait le champ incohérent.
     */
    private function newWorkingSetFrom(PrescribedExercise $prescribed): PrescribedSet
    {
        $working = $this->workingSets($prescribed);
        $model = end($working) ?: null;

        return (new PrescribedSet())
            ->setSetType(SetType::NORMAL)
            ->setPosition($this->nextPosition($prescribed))
            ->setReps($model?->getReps() ?? $prescribed->getReps())
            ->setWeightKg($model?->getWeightKg() ?? $prescribed->getWeightKg())
            ->setDurationSeconds($model?->getDurationSeconds() ?? $prescribed->getDurationSeconds());
    }

    /** @return list<PrescribedSet> séries triées par position */
    private function sorted(PrescribedExercise $prescribed): array
    {
        $sets = $prescribed->getDetailedSets()->toArray();
        usort($sets, static fn (PrescribedSet $a, PrescribedSet $b) => $a->getPosition() <=> $b->getPosition());

        return array_values($sets);
    }

    /** @return list<PrescribedSet> séries de travail triées (échauffement exclu) */
    private function workingSets(PrescribedExercise $prescribed): array
    {
        return array_values(array_filter(
            $this->sorted($prescribed),
            static fn (PrescribedSet $set) => $set->getSetType()->countsAsWorking(),
        ));
    }

    private function nextPosition(PrescribedExercise $prescribed): int
    {
        $max = -1;
        foreach ($prescribed->getDetailedSets() as $set) {
            $max = max($max, $set->getPosition());
        }

        return $max + 1;
    }
}
