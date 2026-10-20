<?php

namespace App\Entity;

use App\Enum\ActivityType;
use App\Enum\GoalBlockType;
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
 *
 * Un objectif porte aussi un **récit** (#42) : une suite ordonnée de `GoalBlock`
 * (texte, photo, activité importée, séance faite) qui raconte la préparation et
 * le jour J. Privé par défaut ; `publishedAt` non nul le sert en lecture publique
 * sous `/s/goal/{slug}`. Le slug est posé à la première publication et ne bouge
 * plus ensuite, même après dépublication : republier redonne le même lien.
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
    private GoalPriority $priority = GoalPriority::A;

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

    /**
     * Le récit. `orphanRemoval` + `remove` en cascade : c'est l'ORM, et non la
     * clé étrangère, qui supprime les blocs avec l'objectif — sans quoi aucun
     * événement Doctrine ne partirait et les photos resteraient sur le disque
     * (GoalPhotoCleanupListener).
     *
     * @var Collection<int, GoalBlock>
     */
    #[ORM\OneToMany(targetEntity: GoalBlock::class, mappedBy: 'goal', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $blocks;

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $slug = null;

    // Null = privé. Seul le propriétaire publie (GoalController::publish).
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    public function __construct()
    {
        $this->planTemplates = new ArrayCollection();
        $this->blocks = new ArrayCollection();
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

    public function getPriority(): GoalPriority
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

    /**
     * @return Collection<int, GoalBlock>
     */
    public function getBlocks(): Collection
    {
        return $this->blocks;
    }

    /**
     * Les blocs dans l'ordre du récit. La collection est triée au chargement
     * (`OrderBy`), mais pas après un ajout ou un déplacement dans la même
     * requête : on retrie sur `position`, qui fait foi.
     *
     * @return list<GoalBlock>
     */
    public function getOrderedBlocks(): array
    {
        $blocks = $this->blocks->toArray();
        usort($blocks, static fn (GoalBlock $a, GoalBlock $b): int => $a->getPosition() <=> $b->getPosition());

        return $blocks;
    }

    /** Ajoute un bloc en fin de récit. */
    public function appendBlock(GoalBlock $block): static
    {
        if (!$this->blocks->contains($block)) {
            $block->setGoal($this)->setPosition(\count($this->blocks));
            $this->blocks->add($block);
        }

        return $this;
    }

    public function removeBlock(GoalBlock $block): static
    {
        if ($this->blocks->removeElement($block)) {
            $this->renumberBlocks();
        }

        return $this;
    }

    /**
     * Échange un bloc avec son voisin (`-1` vers le haut, `+1` vers le bas). Sans
     * effet en bout de récit. Les positions restent contiguës 0..n-1.
     */
    public function moveBlock(GoalBlock $block, int $direction): static
    {
        $ordered = $this->getOrderedBlocks();
        $index = array_search($block, $ordered, true);
        if (false === $index) {
            return $this;
        }

        $target = $index + ($direction < 0 ? -1 : 1);
        if ($target < 0 || $target >= \count($ordered)) {
            return $this;
        }

        [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];
        foreach ($ordered as $position => $item) {
            $item->setPosition($position);
        }

        return $this;
    }

    /** Le récit contient-il déjà cette référence ? (on ne pose pas deux fois la même sortie) */
    public function hasReferenceTo(ImportedActivity|ScheduledWorkout $source): bool
    {
        foreach ($this->blocks as $block) {
            $current = $source instanceof ImportedActivity ? $block->getImportedActivity() : $block->getScheduledWorkout();
            if ($current === $source) {
                return true;
            }
        }

        return false;
    }

    /** Première photo du récit : l'aperçu de la page publique (og:image). */
    public function getCoverBlock(): ?GoalBlock
    {
        foreach ($this->getOrderedBlocks() as $block) {
            if (GoalBlockType::PHOTO === $block->getType() && null !== $block->getPhotoFile()) {
                return $block;
            }
        }

        return null;
    }

    private function renumberBlocks(): void
    {
        foreach ($this->getOrderedBlocks() as $position => $block) {
            $block->setPosition($position);
        }
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function isPublished(): bool
    {
        return null !== $this->publishedAt && null !== $this->slug;
    }
}
