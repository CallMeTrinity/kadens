<?php

namespace App\Entity;

use App\Repository\WorkoutRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: WorkoutRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'], message: 'Ce slug est déjà utilisé.')]
class Workout
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'workouts')]
    private ?User $owner = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(nullable: true)]
    private ?int $estimatedDurationMinutes = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, Block>
     */
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[ORM\OneToMany(targetEntity: Block::class, mappedBy: 'workout', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $blocks;

    /**
     * @var Collection<int, PlanItem>
     */
    #[ORM\OneToMany(targetEntity: PlanItem::class, mappedBy: 'workout')]
    private Collection $planItems;

    /**
     * @var Collection<int, ScheduledWorkout>
     */
    #[ORM\OneToMany(targetEntity: ScheduledWorkout::class, mappedBy: 'workout')]
    private Collection $scheduledWorkouts;

    public function __construct()
    {
        $this->blocks = new ArrayCollection();
        $this->planItems = new ArrayCollection();
        $this->scheduledWorkouts = new ArrayCollection();
    }

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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getEstimatedDurationMinutes(): ?int
    {
        return $this->estimatedDurationMinutes;
    }

    public function setEstimatedDurationMinutes(?int $estimatedDurationMinutes): static
    {
        $this->estimatedDurationMinutes = $estimatedDurationMinutes;

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

    /**
     * @return Collection<int, Block>
     */
    public function getBlocks(): Collection
    {
        return $this->blocks;
    }

    public function addBlock(Block $block): static
    {
        if (!$this->blocks->contains($block)) {
            $this->blocks->add($block);
            $block->setWorkout($this);
        }

        return $this;
    }

    public function removeBlock(Block $block): static
    {
        if ($this->blocks->removeElement($block)) {
            // set the owning side to null (unless already changed)
            if ($block->getWorkout() === $this) {
                $block->setWorkout(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PlanItem>
     */
    public function getPlanItems(): Collection
    {
        return $this->planItems;
    }

    public function addPlanItem(PlanItem $planItem): static
    {
        if (!$this->planItems->contains($planItem)) {
            $this->planItems->add($planItem);
            $planItem->setWorkout($this);
        }

        return $this;
    }

    public function removePlanItem(PlanItem $planItem): static
    {
        if ($this->planItems->removeElement($planItem)) {
            // set the owning side to null (unless already changed)
            if ($planItem->getWorkout() === $this) {
                $planItem->setWorkout(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ScheduledWorkout>
     */
    public function getScheduledWorkouts(): Collection
    {
        return $this->scheduledWorkouts;
    }

    public function addScheduledWorkout(ScheduledWorkout $scheduledWorkout): static
    {
        if (!$this->scheduledWorkouts->contains($scheduledWorkout)) {
            $this->scheduledWorkouts->add($scheduledWorkout);
            $scheduledWorkout->setWorkout($this);
        }

        return $this;
    }

    public function removeScheduledWorkout(ScheduledWorkout $scheduledWorkout): static
    {
        if ($this->scheduledWorkouts->removeElement($scheduledWorkout)) {
            // set the owning side to null (unless already changed)
            if ($scheduledWorkout->getWorkout() === $this) {
                $scheduledWorkout->setWorkout(null);
            }
        }

        return $this;
    }
}
