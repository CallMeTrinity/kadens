<?php

namespace App\Service;

use App\Entity\Block;
use App\Entity\PrescribedExercise;
use App\Entity\User;
use App\Entity\Workout;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Copie profonde d'une séance (blocs -> exercices prescrits, avec tous leurs
 * paramètres). Source unique du clonage, réutilisée par :
 * - la duplication de séance de bibliothèque (WorkoutController::duplicate) ;
 * - la pose d'une séance dans un plan (fork à la pose : la case reçoit sa propre
 *   copie, éditable/progressable sans toucher les autres) ;
 * - la duplication d'une semaine de plan.
 *
 * La copie est persistée (l'arbre suit par cascade persist de Workout::blocks /
 * Block::prescribedExercises) mais PAS flushée : l'appelant maîtrise la
 * transaction.
 */
final class WorkoutCloner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SlugGenerator $slugGenerator,
        private readonly WorkoutEstimator $estimator,
    ) {
    }

    /**
     * @param bool $planLocal true = copie privée à un plan (masquée de la biblio)
     */
    public function cloneWorkout(Workout $source, User $owner, ?string $title, bool $planLocal): Workout
    {
        $title ??= $source->getTitle();

        $copy = (new Workout())
            ->setOwner($owner)
            ->setTitle($title)
            ->setDescription($source->getDescription())
            ->setPlanLocal($planLocal)
            ->setSlug($this->slugGenerator->generate($title, Workout::class));

        // Copie profonde blocs -> exercices prescrits. addBlock / addPrescribedExercise
        // maintiennent les deux côtés (indispensable pour un re-rendu immédiat).
        foreach ($source->getBlocks() as $block) {
            $blockCopy = (new Block())
                ->setRole($block->getRole())
                ->setRounds($block->getRounds() ?? 1)
                ->setPosition($block->getPosition())
                ->setLabel($block->getLabel());

            foreach ($block->getPrescribedExercises() as $prescribed) {
                $blockCopy->addPrescribedExercise($this->clonePrescribed($prescribed));
            }

            $copy->addBlock($blockCopy);
        }

        // La durée estimée dérive du contenu : on la (re)calcule sur la copie.
        $copy->setEstimatedDurationMinutes($this->estimator->estimateMinutes($copy));

        $this->entityManager->persist($copy);

        return $copy;
    }

    /**
     * Duplique un exercice prescrit avec tous ses paramètres (hors identité et
     * bloc, posés par l'appelant via addPrescribedExercise).
     */
    private function clonePrescribed(PrescribedExercise $source): PrescribedExercise
    {
        return (new PrescribedExercise())
            ->setExercise($source->getExercise())
            ->setPrescriptionType($source->getPrescriptionType())
            ->setPosition($source->getPosition())
            ->setSets($source->getSets())
            ->setReps($source->getReps())
            ->setWeightKg($source->getWeightKg())
            ->setDurationSeconds($source->getDurationSeconds())
            ->setDistanceMeters($source->getDistanceMeters())
            ->setPaceSecondsPerKm($source->getPaceSecondsPerKm())
            ->setTargetReps($source->getTargetReps())
            ->setCapSeconds($source->getCapSeconds())
            ->setIntensityZone($source->getIntensityZone())
            ->setRestSeconds($source->getRestSeconds())
            ->setNotes($source->getNotes());
    }
}
