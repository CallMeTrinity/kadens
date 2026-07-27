<?php

namespace App\Service;

use App\Entity\LoggedSet;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Repository\LoggedSetRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seule autorité sur les mutations du réalisé (`LoggedSet`).
 *
 * Règle qui tient tout : **on n'écrit jamais dans la prescription**. Valider une
 * série, corriger une charge, dévalider, tout valider — aucune de ces opérations
 * ne touche `PrescribedExercise` ni `PrescribedSet`. C'est ce qui rend la page
 * d'exécution sûre alors que la séance peut être partagée entre la bibliothèque,
 * un plan et dix dates.
 *
 * Le service ne flushe pas de lui-même sauf mention explicite : l'appelant
 * maîtrise la transaction, comme WorkoutCloner.
 */
final class WorkoutLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggedSetRepository $loggedSets,
        private readonly SessionSheet $sheet,
    ) {
    }

    /**
     * Valide une série (ou met à jour ses valeurs réelles si elle l'était déjà).
     * Idempotent : rejouer la même validation ne crée pas de doublon, ce dont
     * dépend la file d'écriture hors ligne, qui peut renvoyer deux fois le même
     * geste après une reconnexion.
     */
    public function log(
        ScheduledWorkout $scheduled,
        PrescribedExercise $prescribed,
        int $setIndex,
        ?int $reps = null,
        ?float $weightKg = null,
        ?int $durationSeconds = null,
    ): LoggedSet {
        $this->assertBelongs($scheduled, $prescribed);
        $setIndex = max(1, $setIndex);

        $log = $this->find($scheduled, $prescribed, $setIndex);

        if (null === $log) {
            $log = (new LoggedSet())
                ->setPrescribedExercise($prescribed)
                ->setSetIndex($setIndex);
            // addLoggedSet maintient les DEUX côtés : sans ça la collection en
            // mémoire reste périmée et le fragment re-rendu dans la foulée montre
            // la série encore à cocher.
            $scheduled->addLoggedSet($log);
            $this->entityManager->persist($log);
        }

        $log->setReps($reps);
        $log->setWeightKg($weightKg);
        $log->setDurationSeconds($durationSeconds);

        return $log;
    }

    /**
     * Dévalide une série. Sans effet si elle ne l'était pas — même raison
     * d'idempotence que `log`.
     */
    public function unlog(ScheduledWorkout $scheduled, PrescribedExercise $prescribed, int $setIndex): void
    {
        $this->assertBelongs($scheduled, $prescribed);

        $log = $this->find($scheduled, $prescribed, $setIndex);
        if (null !== $log) {
            // orphanRemoval sur la collection : le retrait des deux côtés suffit
            // à supprimer la ligne au flush.
            $scheduled->removeLoggedSet($log);
        }
    }

    /**
     * Valide d'un coup toutes les séries prévues encore non validées, avec les
     * valeurs prescrites. C'est le « j'ai tout fait comme prévu » proposé à la
     * clôture d'une séance incomplète.
     *
     * Ne touche pas aux séries déjà validées : une charge corrigée à la main
     * pendant la séance ne doit pas être réécrite par le prévu.
     *
     * @return int nombre de séries ajoutées
     */
    public function completeAll(ScheduledWorkout $scheduled): int
    {
        $added = 0;

        foreach ($this->sheet->build($scheduled)['blocks'] as $block) {
            foreach ($block['segments'] as $segment) {
                foreach ($segment['exercises'] as $exercise) {
                    foreach ($exercise['lines'] as $line) {
                        if ($line['done']) {
                            continue;
                        }

                        $this->log(
                            $scheduled,
                            $exercise['prescribed'],
                            $line['index'],
                            $line['plannedReps'],
                            $line['plannedWeightKg'],
                            $line['plannedDurationSeconds'],
                        );
                        ++$added;
                    }
                }
            }
        }

        return $added;
    }

    /**
     * Efface tout le réalisé d'une séance datée (remise à zéro du pointage).
     *
     * @return int nombre de séries retirées
     */
    public function reset(ScheduledWorkout $scheduled): int
    {
        $logs = $scheduled->getLoggedSets()->toArray();
        foreach ($logs as $log) {
            $scheduled->removeLoggedSet($log);
        }

        return \count($logs);
    }

    private function find(ScheduledWorkout $scheduled, PrescribedExercise $prescribed, int $setIndex): ?LoggedSet
    {
        // La collection en mémoire fait foi : elle porte les validations déjà
        // faites dans cette requête (une file hors ligne rejoue plusieurs gestes
        // d'affilée), que le repository ne verrait pas avant le flush.
        foreach ($scheduled->getLoggedSets() as $log) {
            if ($log->getPrescribedExercise() === $prescribed && $log->getSetIndex() === $setIndex) {
                return $log;
            }
        }

        return $this->loggedSets->findOneBy([
            'scheduledWorkout' => $scheduled,
            'prescribedExercise' => $prescribed,
            'setIndex' => $setIndex,
        ]);
    }

    /**
     * Un log ne peut valider qu'un exercice de SA séance. La page d'exécution est
     * le seul chemin normal, mais l'id de l'exercice transite par le formulaire :
     * sans cette garde, on pourrait pointer l'exercice d'une autre séance.
     */
    private function assertBelongs(ScheduledWorkout $scheduled, PrescribedExercise $prescribed): void
    {
        $workout = $prescribed->getBlock()?->getWorkout();

        if (null === $workout || $workout !== $scheduled->getWorkout()) {
            throw new \InvalidArgumentException('Cet exercice n\'appartient pas à la séance planifiée.');
        }
    }
}
