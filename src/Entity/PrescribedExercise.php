<?php

namespace App\Entity;

use App\Enum\PrescriptionType;
use App\Repository\PrescribedExerciseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PrescribedExerciseRepository::class)]
class PrescribedExercise
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'prescribedExercises')]
    private ?Block $block = null;

    #[ORM\ManyToOne(inversedBy: 'prescribedExercises')]
    private ?Exercise $exercise = null;

    #[ORM\Column]
    private ?int $position = null;

    #[ORM\Column(enumType: PrescriptionType::class)]
    private ?PrescriptionType $prescriptionType = null;

    #[ORM\Column(nullable: true)]
    private ?int $sets = null;

    #[ORM\Column(nullable: true)]
    private ?int $reps = null;

    #[ORM\Column(nullable: true)]
    private ?float $weightKg = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column(nullable: true)]
    private ?int $distanceMeters = null;

    #[ORM\Column(nullable: true)]
    private ?int $paceSecondsPerKm = null;

    #[ORM\Column(nullable: true)]
    private ?int $targetReps = null;

    #[ORM\Column(nullable: true)]
    private ?int $capSeconds = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $intensityZone = null;

    #[ORM\Column(nullable: true)]
    private ?int $restSeconds = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBlock(): ?Block
    {
        return $this->block;
    }

    public function setBlock(?Block $block): static
    {
        $this->block = $block;

        return $this;
    }

    public function getExercise(): ?Exercise
    {
        return $this->exercise;
    }

    public function setExercise(?Exercise $exercise): static
    {
        $this->exercise = $exercise;

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

    public function getPrescriptionType(): ?PrescriptionType
    {
        return $this->prescriptionType;
    }

    public function setPrescriptionType(PrescriptionType $prescriptionType): static
    {
        $this->prescriptionType = $prescriptionType;

        return $this;
    }

    public function getSets(): ?int
    {
        return $this->sets;
    }

    public function setSets(?int $sets): static
    {
        $this->sets = $sets;

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

    public function getDistanceMeters(): ?int
    {
        return $this->distanceMeters;
    }

    public function setDistanceMeters(?int $distanceMeters): static
    {
        $this->distanceMeters = $distanceMeters;

        return $this;
    }

    public function getPaceSecondsPerKm(): ?int
    {
        return $this->paceSecondsPerKm;
    }

    public function setPaceSecondsPerKm(?int $paceSecondsPerKm): static
    {
        $this->paceSecondsPerKm = $paceSecondsPerKm;

        return $this;
    }

    public function getTargetReps(): ?int
    {
        return $this->targetReps;
    }

    public function setTargetReps(?int $targetReps): static
    {
        $this->targetReps = $targetReps;

        return $this;
    }

    public function getCapSeconds(): ?int
    {
        return $this->capSeconds;
    }

    public function setCapSeconds(?int $capSeconds): static
    {
        $this->capSeconds = $capSeconds;

        return $this;
    }

    public function getIntensityZone(): ?string
    {
        return $this->intensityZone;
    }

    public function setIntensityZone(?string $intensityZone): static
    {
        $this->intensityZone = $intensityZone;

        return $this;
    }

    public function getRestSeconds(): ?int
    {
        return $this->restSeconds;
    }

    public function setRestSeconds(?int $restSeconds): static
    {
        $this->restSeconds = $restSeconds;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }
}
