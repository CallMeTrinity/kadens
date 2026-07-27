<?php

namespace App\Entity;

use App\Enum\SetType;
use App\Repository\PrescribedSetRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une série individuelle d'un exercice de force prescrit (mode « séries
 * détaillées »). Chaque série porte son propre type (échauffement, dégressive,
 * drop set…) et ses propres valeurs, ce qui permet une prescription hétérogène là
 * où le compteur scalaire `sets`/`reps`/`weightKg` de PrescribedExercise n'exprime
 * que N séries identiques.
 *
 * Ne concerne que les types d'effort de force (SETS_REPS -> reps ; SETS_TIME ->
 * durée). Le mode détaillé est optionnel : tant que la collection est vide,
 * PrescribedExercise reste en mode scalaire (voir PrescribedExercise::hasDetailedSets).
 */
#[ORM\Entity(repositoryClass: PrescribedSetRepository::class)]
class PrescribedSet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'detailedSets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PrescribedExercise $prescribedExercise = null;

    #[ORM\Column]
    private ?int $position = null;

    #[ORM\Column(enumType: SetType::class, options: ['default' => 'normal'])]
    private SetType $setType = SetType::NORMAL;

    #[ORM\Column(nullable: true)]
    private ?int $reps = null;

    #[ORM\Column(nullable: true)]
    private ?float $weightKg = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationSeconds = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPrescribedExercise(): ?PrescribedExercise
    {
        return $this->prescribedExercise;
    }

    public function setPrescribedExercise(?PrescribedExercise $prescribedExercise): static
    {
        $this->prescribedExercise = $prescribedExercise;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getSetType(): SetType
    {
        return $this->setType;
    }

    public function setSetType(SetType $setType): static
    {
        $this->setType = $setType;

        return $this;
    }

    public function getReps(): ?int
    {
        return $this->reps;
    }

    public function setReps(?int $reps): static
    {
        $this->reps = $reps;

        return $this;
    }

    public function getWeightKg(): ?float
    {
        return $this->weightKg;
    }

    public function setWeightKg(?float $weightKg): static
    {
        $this->weightKg = $weightKg;

        return $this;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(?int $durationSeconds): static
    {
        $this->durationSeconds = $durationSeconds;

        return $this;
    }
}
