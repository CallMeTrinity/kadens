<?php

namespace App\Entity;

use App\Repository\PlanTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: PlanTemplateRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'], message: 'Ce slug est déjà utilisé.')]
class PlanTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'planTemplates')]
    private ?User $owner = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Bloc-notes libre du PROPRIÉTAIRE, même règle que `Workout::$notes` : c'est
     * le fourre-tout où se construit le déroulé du plan avant d'exister en cases.
     * Visible du seul `owner`, jamais du coach, jamais du partage public.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private ?int $durationWeeks = null;

    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $slug = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, PlanItem>
     */
    #[ORM\OrderBy(['weekNumber' => 'ASC', 'dayOfWeek' => 'ASC'])]
    #[ORM\OneToMany(targetEntity: PlanItem::class, mappedBy: 'planTemplate', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $planItems;

    /**
     * @var Collection<int, ScheduledWorkout>
     */
    #[ORM\OneToMany(targetEntity: ScheduledWorkout::class, mappedBy: 'sourcePlanTemplate')]
    private Collection $scheduledWorkouts;

    /**
     * Objectifs que ce plan prépare. Relation N:N : une préparation se découpe
     * souvent en plusieurs blocs (base puis spécifique), et un même plan peut servir
     * deux échéances. Côté PROPRIÉTAIRE — c'est ici que vit la table de jointure,
     * donc c'est `addGoal`/`removeGoal` qui écrivent en base.
     *
     * Le rattachement est libre et réversible : il documente l'intention (« ce plan
     * sert cette échéance »), il ne contraint ni les dates ni le contenu.
     *
     * @var Collection<int, Goal>
     */
    #[ORM\ManyToMany(targetEntity: Goal::class, inversedBy: 'planTemplates')]
    #[ORM\JoinTable(name: 'plan_template_goal')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    #[ORM\OrderBy(['targetDate' => 'ASC'])]
    private Collection $goals;

    public function __construct()
    {
        $this->planItems = new ArrayCollection();
        $this->scheduledWorkouts = new ArrayCollection();
        $this->goals = new ArrayCollection();
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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getDurationWeeks(): ?int
    {
        return $this->durationWeeks;
    }

    public function setDurationWeeks(int $durationWeeks): static
    {
        $this->durationWeeks = $durationWeeks;

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
            $planItem->setPlanTemplate($this);
        }

        return $this;
    }

    public function removePlanItem(PlanItem $planItem): static
    {
        if ($this->planItems->removeElement($planItem)) {
            // set the owning side to null (unless already changed)
            if ($planItem->getPlanTemplate() === $this) {
                $planItem->setPlanTemplate(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Goal>
     */
    public function getGoals(): Collection
    {
        return $this->goals;
    }

    /**
     * Les deux côtés sont maintenus : sans ça, un fragment re-rendu par Turbo
     * Stream dans la même requête (la fiche objectif, l'en-tête de plan) montrerait
     * un état périmé — piège déjà rencontré ailleurs dans ce modèle.
     */
    public function addGoal(Goal $goal): static
    {
        if (!$this->goals->contains($goal)) {
            $this->goals->add($goal);
            $goal->getPlanTemplates()->add($this);
        }

        return $this;
    }

    public function removeGoal(Goal $goal): static
    {
        if ($this->goals->removeElement($goal)) {
            $goal->getPlanTemplates()->removeElement($this);
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
            $scheduledWorkout->setSourcePlanTemplate($this);
        }

        return $this;
    }

    public function removeScheduledWorkout(ScheduledWorkout $scheduledWorkout): static
    {
        if ($this->scheduledWorkouts->removeElement($scheduledWorkout)) {
            // set the owning side to null (unless already changed)
            if ($scheduledWorkout->getSourcePlanTemplate() === $this) {
                $scheduledWorkout->setSourcePlanTemplate(null);
            }
        }

        return $this;
    }
}
