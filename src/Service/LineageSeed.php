<?php

namespace App\Service;

use App\Entity\LoggedExercise;
use App\Entity\LoggedSet;
use App\Entity\PrescribedExercise;
use App\Entity\PrescribedSet;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\PrescriptionType;
use App\Enum\SetType;
use App\Repository\ScheduledWorkoutRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Décide de quoi part une séance qu'on repose dans un plan : la seule autorité
 * sur « reprogrammer Upper A ».
 *
 * Sans réalisation dans sa lignée, la copie part de la séance de bibliothèque
 * (le fork à la pose d'avant). Avec, elle part de **la dernière séance faite** :
 * sa structure d'alors (exercices ajoutés ou retirés en cours de plan compris),
 * puis le réalisé logué reporté en prescrit, exercice par exercice. On reprend
 * donc là où on s'est arrêté, pas là où la bibliothèque s'est figée.
 *
 * Ce qui est reporté, et seulement ça :
 * - les exercices de force (`SETS_REPS` / `SETS_TIME`) non passés, rattachés à
 *   un exercice prescrit de la séance faite. Un exercice logué hors prescrit n'a
 *   pas de case où atterrir, le cardio ne se logue pas (il s'importe) ;
 * - les séries qui ont une valeur (au moins une rep ou une seconde). Une série
 *   cochée « ? » a eu lieu mais ne dit rien à reprendre. Sans aucune série
 *   chiffrée, le prescrit de la séance faite reste tel quel ;
 * - l'échauffement est GARDÉ, contrairement au volume : il fait partie de ce
 *   qu'on refera, et il reste hors décompte de travail côté prescrit aussi.
 *
 * Forme du report : des séries de travail toutes identiques reviennent en mode
 * simple (3 × 8 à 80 kg reste lisible comme tel) ; dès que les séries diffèrent
 * ou qu'il y a un échauffement, mode détaillé, une ligne par série. Repos, RPE
 * et notes prescrits ne bougent pas : le réalisé ne les décrit pas.
 */
final class LineageSeed
{
    private const LOGGABLE = [PrescriptionType::SETS_REPS, PrescriptionType::SETS_TIME];

    public function __construct(
        private readonly ScheduledWorkoutRepository $scheduledRepository,
        private readonly WorkoutCloner $cloner,
        private readonly SetSynchronizer $setSynchronizer,
        private readonly WorkoutEstimator $estimator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * La dernière séance faite de la lignée, ou null si elle n'a jamais été faite.
     */
    public function latestDone(Workout $origin): ?ScheduledWorkout
    {
        return $this->scheduledRepository->findDoneInLineage($origin, 1)[0] ?? null;
    }

    /**
     * Crée la copie locale de plan de `$origin` (persistée, pas flushée, comme
     * `WorkoutCloner`). Elle garde le titre de la bibliothèque : on repose
     * « Upper A », même si une copie a été renommée en route.
     */
    public function seed(Workout $origin, User $owner): Workout
    {
        $latest = $this->latestDone($origin);
        $base = $latest?->getWorkout();

        if (null === $latest || null === $base) {
            return $this->cloner->cloneWorkout($origin, $owner, $origin->getTitle(), true);
        }

        /** @var \SplObjectStorage<PrescribedExercise, PrescribedExercise> $map */
        $map = new \SplObjectStorage();
        $copy = $this->cloner->cloneWorkout($base, $owner, $origin->getTitle(), true, $map);

        foreach ($latest->getLoggedExercises() as $logged) {
            $source = $logged->getSourcePrescribedExercise();
            if (null !== $source && $map->offsetExists($source)) {
                $this->applyLogged($map[$source], $logged);
            }
        }

        $copy->setEstimatedDurationMinutes($this->estimator->estimateMinutes($copy));

        return $copy;
    }

    private function applyLogged(PrescribedExercise $target, LoggedExercise $logged): void
    {
        if ($logged->isSkipped() || !\in_array($target->getPrescriptionType(), self::LOGGABLE, true)) {
            return;
        }

        $sets = array_values(array_filter(
            $logged->getLoggedSets()->toArray(),
            static fn (LoggedSet $set) => ($set->getReps() ?? 0) > 0 || ($set->getDurationSeconds() ?? 0) > 0,
        ));
        if ([] === $sets) {
            return;
        }
        usort($sets, static fn (LoggedSet $a, LoggedSet $b) => $a->getPosition() <=> $b->getPosition());

        // La copie est déjà persistée : une série retirée doit être désinscrite
        // explicitement, l'orphanRemoval ne vaut que pour une entité déjà en base.
        foreach ($target->getDetailedSets()->toArray() as $old) {
            $target->removeDetailedSet($old);
            $this->entityManager->remove($old);
        }

        if ($this->isUniform($sets)) {
            $target
                ->setSets(\count($sets))
                ->setReps($sets[0]->getReps())
                ->setWeightKg($sets[0]->getWeightKg())
                ->setDurationSeconds($sets[0]->getDurationSeconds());

            return;
        }

        foreach ($sets as $position => $set) {
            $prescribed = (new PrescribedSet())
                ->setPosition($position)
                ->setSetType($set->getSetType())
                ->setReps($set->getReps())
                ->setWeightKg($set->getWeightKg())
                ->setDurationSeconds($set->getDurationSeconds());
            $target->addDetailedSet($prescribed);
            $this->entityManager->persist($prescribed);
        }

        $this->setSynchronizer->syncScalarFromDetailed($target);
    }

    /**
     * Mode simple possible : uniquement des séries NORMAL, toutes aux mêmes
     * valeurs. Un échauffement ou un type particulier (drop set, échec) ne se
     * dit qu'en mode détaillé.
     *
     * @param non-empty-list<LoggedSet> $sets
     */
    private function isUniform(array $sets): bool
    {
        $first = $sets[0];
        foreach ($sets as $set) {
            if (SetType::NORMAL !== $set->getSetType()
                || $set->getReps() !== $first->getReps()
                || $set->getWeightKg() !== $first->getWeightKg()
                || $set->getDurationSeconds() !== $first->getDurationSeconds()) {
                return false;
            }
        }

        return true;
    }
}
