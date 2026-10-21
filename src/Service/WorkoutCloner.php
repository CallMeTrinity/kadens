<?php

namespace App\Service;

use App\Entity\Block;
use App\Entity\PrescribedExercise;
use App\Entity\PrescribedSet;
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
 *
 * Une copie locale de plan hérite de la RACINE de lignée (`origin`) : la séance
 * de bibliothèque d'origine, jamais la copie dont on la tire. Dupliquer une
 * semaine ou un plan garde donc la parenté, et une duplication de bibliothèque
 * (`planLocal = false`) n'en a pas : c'est une nouvelle séance.
 *
 * Le bloc-notes privé (`notes`) n'est délibérément PAS copié : le fork à la pose
 * en dupliquerait un exemplaire dans chaque case de plan, alors que ce champ est un
 * brouillon attaché à un contexte précis. La description, elle, décrit bien la
 * séance elle-même et suit la copie.
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
     * @param bool                                                     $planLocal     true = copie privée à un plan (masquée de la biblio)
     * @param \SplObjectStorage<PrescribedExercise, PrescribedExercise>|null $prescribedMap rempli, si fourni, de la correspondance
     *                                                                                exercice prescrit source → copie (sert à
     *                                                                                reporter le réalisé sur la copie, cf. LineageSeed)
     */
    public function cloneWorkout(Workout $source, User $owner, ?string $title, bool $planLocal, ?\SplObjectStorage $prescribedMap = null): Workout
    {
        $title ??= (string) $source->getTitle();

        $copy = (new Workout())
            ->setOwner($owner)
            ->setTitle($title)
            ->setDescription($source->getDescription())
            ->setPlanLocal($planLocal)
            ->setOrigin($planLocal ? ($source->getOrigin() ?? $source) : null)
            ->setSlug($this->slugGenerator->generate($title, Workout::class));

        // Copie profonde blocs -> exercices prescrits. addBlock / addPrescribedExercise
        // maintiennent les deux côtés (indispensable pour un re-rendu immédiat).
        foreach ($source->getBlocks() as $block) {
            $blockCopy = (new Block())
                ->setRole($block->getRole() ?? throw new \LogicException('Bloc source sans rôle.'))
                ->setRounds($block->getRounds())
                ->setPosition((int) $block->getPosition())
                ->setLabel($block->getLabel());

            foreach ($block->getPrescribedExercises() as $prescribed) {
                $prescribedCopy = $this->clonePrescribed($prescribed);
                $blockCopy->addPrescribedExercise($prescribedCopy);
                $prescribedMap?->offsetSet($prescribed, $prescribedCopy);
            }

            $copy->addBlock($blockCopy);
        }

        // La durée estimée dérive du contenu : on la (re)calcule sur la copie.
        // Un appelant qui retouche le contenu ensuite (LineageSeed) la recalcule.
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
        $copy = (new PrescribedExercise())
            ->setExercise($source->getExercise())
            ->setPrescriptionType($source->getPrescriptionType() ?? throw new \LogicException('Exercice prescrit source sans type.'))
            ->setPosition((int) $source->getPosition())
            // Les liaisons de superset sont internes au bloc : le numéro reste
            // valable tel quel dans la copie.
            ->setSupersetGroup($source->getSupersetGroup())
            ->setSets($source->getSets())
            ->setReps($source->getReps())
            ->setWeightKg($source->getWeightKg())
            ->setDurationSeconds($source->getDurationSeconds())
            ->setDistanceMeters($source->getDistanceMeters())
            ->setPaceSecondsPerKm($source->getPaceSecondsPerKm())
            ->setTargetReps($source->getTargetReps())
            ->setCapSeconds($source->getCapSeconds())
            ->setIntensityZone($source->getIntensityZone())
            ->setElevationGainMeters($source->getElevationGainMeters())
            ->setRpe($source->getRpe())
            ->setRestSeconds($source->getRestSeconds())
            ->setNotes($source->getNotes());

        // Séries détaillées : copie profonde (addDetailedSet maintient les deux
        // côtés, la cascade persist de PrescribedExercise::detailedSets suit).
        foreach ($source->getDetailedSets() as $set) {
            $copy->addDetailedSet(
                (new PrescribedSet())
                    ->setPosition($set->getPosition() ?? 0)
                    ->setSetType($set->getSetType())
                    ->setReps($set->getReps())
                    ->setWeightKg($set->getWeightKg())
                    ->setDurationSeconds($set->getDurationSeconds())
            );
        }

        return $copy;
    }
}
