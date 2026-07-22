<?php

namespace App\Entity;

use App\Enum\ScheduledStatus;
use App\Repository\ScheduledWorkoutRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ScheduledWorkoutRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ScheduledWorkout
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'scheduledWorkouts')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?User $owner = null;

    // La séance datée n'a pas de sens sans sa séance source : on cascade.
    #[ORM\ManyToOne(inversedBy: 'scheduledWorkouts')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Workout $workout = null;

    // Le plan source n'est qu'une provenance : le supprimer ne doit pas effacer
    // un planning déjà matérialisé, seulement en oublier l'origine.
    #[ORM\ManyToOne(inversedBy: 'scheduledWorkouts')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?PlanTemplate $sourcePlanTemplate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $scheduledDate = null;

    #[ORM\Column(enumType: ScheduledStatus::class, options: ['default' => ScheduledStatus::PLANNED->value])]
    private ?ScheduledStatus $status = ScheduledStatus::PLANNED;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $completionNotes = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getWorkout(): ?Workout
    {
        return $this->workout;
    }

    public function setWorkout(?Workout $workout): static
    {
        $this->workout = $workout;

        return $this;
    }

    public function getSourcePlanTemplate(): ?PlanTemplate
    {
        return $this->sourcePlanTemplate;
    }

    public function setSourcePlanTemplate(?PlanTemplate $sourcePlanTemplate): static
    {
        $this->sourcePlanTemplate = $sourcePlanTemplate;

        return $this;
    }

    public function getScheduledDate(): ?\DateTimeImmutable
    {
        return $this->scheduledDate;
    }

    public function setScheduledDate(\DateTimeImmutable $scheduledDate): static
    {
        $this->scheduledDate = $scheduledDate;

        return $this;
    }

    public function getStatus(): ?ScheduledStatus
    {
        return $this->status;
    }

    public function setStatus(ScheduledStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCompletionNotes(): ?string
    {
        return $this->completionNotes;
    }

    public function setCompletionNotes(?string $completionNotes): static
    {
        $this->completionNotes = $completionNotes;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
