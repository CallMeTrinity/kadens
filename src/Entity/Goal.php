<?php

namespace App\Entity;

use App\Enum\ActivityType;
use App\Enum\GoalOutcome;
use App\Enum\GoalPriority;
use App\Repository\GoalRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Objectif daté : l'échéance vers laquelle on s'entraîne (course, compétition,
 * test de force, but personnel). Contrairement au reste du modèle, la « cible »
 * est un texte libre assumé (targetValue) : les objectifs sont trop hétérogènes
 * pour la normalisation en unités appliquée aux prescriptions.
 *
 * Événement journée entière (pas d'heure, pas de fuseau), comme ScheduledWorkout :
 * le calendrier et le flux ICS s'y branchent sans VTIMEZONE.
 */
#[ORM\Entity(repositoryClass: GoalRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Goal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'goals')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    // Activité cible : porte le code couleur/icône (course, salle…). Nullable pour
    // un objectif transverse (ex. « perdre 3 kg », « tenir le rythme »).
    #[ORM\Column(nullable: true, enumType: ActivityType::class)]
    private ?ActivityType $activity = null;

    #[ORM\Column(length: 20, enumType: GoalPriority::class, options: ['default' => GoalPriority::A->value])]
    private ?GoalPriority $priority = GoalPriority::A;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $targetDate = null;

    // Cible visée, texte libre (« sub 4h », « 180 kg au squat »).
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $targetValue = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    // Résultat renseigné après l'échéance (null tant que non débriefé).
    #[ORM\Column(length: 20, nullable: true, enumType: GoalOutcome::class)]
    private ?GoalOutcome $outcome = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $resultNote = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * Plans qui préparent cet objectif. Relation N:N : une préparation se découpe
     * souvent en plusieurs blocs (base puis spécifique), et un même plan peut servir
     * deux échéances. Côté inverse — la table de jointure est portée par
     * PlanTemplate.
     *
     * @var Collection<int, PlanTemplate>
     */
    #[ORM\ManyToMany(targetEntity: PlanTemplate::class, mappedBy: 'goals')]
    #[ORM\OrderBy(['title' => 'ASC'])]
    private Collection $planTemplates;

    public function __construct()
    {
        $this->planTemplates = new ArrayCollection();
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

    public function getActivity(): ?ActivityType
    {
        return $this->activity;
    }

    public function setActivity(?ActivityType $activity): static
    {
        $this->activity = $activity;

        return $this;
    }

    public function getPriority(): ?GoalPriority
    {
        return $this->priority;
    }

    public function setPriority(GoalPriority $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getTargetDate(): ?\DateTimeImmutable
    {
        return $this->targetDate;
    }

    public function setTargetDate(\DateTimeImmutable $targetDate): static
    {
        $this->targetDate = $targetDate;

        return $this;
    }

    public function getTargetValue(): ?string
    {
        return $this->targetValue;
    }

    public function setTargetValue(?string $targetValue): static
    {
        $this->targetValue = $targetValue;

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

    public function getOutcome(): ?GoalOutcome
    {
        return $this->outcome;
    }

    public function setOutcome(?GoalOutcome $outcome): static
    {
        $this->outcome = $outcome;

        return $this;
    }

    public function getResultNote(): ?string
    {
        return $this->resultNote;
    }

    public function setResultNote(?string $resultNote): static
    {
        $this->resultNote = $resultNote;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, PlanTemplate>
     */
    public function getPlanTemplates(): Collection
    {
        return $this->planTemplates;
    }

    /**
     * Nombre de jours entiers d'ici l'échéance (0 = aujourd'hui, négatif si passée).
     * Comparaison journée entière : « aujourd'hui » à minuit vs la date cible.
     */
    public function getDaysUntil(): ?int
    {
        if (null === $this->targetDate) {
            return null;
        }

        $today = new \DateTimeImmutable('today');

        return (int) $today->diff($this->targetDate->setTime(0, 0))->format('%r%a');
    }

    /** Échéance dépassée (strictement avant aujourd'hui). */
    public function isPast(): bool
    {
        $days = $this->getDaysUntil();

        return null !== $days && $days < 0;
    }
}
