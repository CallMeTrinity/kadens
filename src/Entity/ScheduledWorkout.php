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

    // Case précise de la trame dont cette séance datée est issue. Sert au resync
    // « plan vivant » (retrouver/ajouter la séance datée d'un item). SET NULL :
    // retirer une case du plan n'efface pas une séance datée déjà réalisée, elle
    // en oublie juste l'origine (décision « préserver le réalisé »).
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?PlanItem $sourcePlanItem = null;

    // Ancre de l'instanciation (lundi ISO de la semaine 1). Conservée pour pouvoir
    // dater les cases ajoutées au plan APRÈS coup, sans redemander la date de départ.
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $planAnchorDate = null;

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

    public function getSourcePlanItem(): ?PlanItem
    {
        return $this->sourcePlanItem;
    }

    public function setSourcePlanItem(?PlanItem $sourcePlanItem): static
    {
        $this->sourcePlanItem = $sourcePlanItem;

        return $this;
    }

    public function getPlanAnchorDate(): ?\DateTimeImmutable
    {
        return $this->planAnchorDate;
    }

    public function setPlanAnchorDate(?\DateTimeImmutable $planAnchorDate): static
    {
        $this->planAnchorDate = $planAnchorDate;

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
