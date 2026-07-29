<?php

namespace App\Entity;

use App\Enum\ScheduledStatus;
use App\Repository\ScheduledWorkoutRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Une séance posée sur une DATE. C'est le point unique où le prévu et le réalisé
 * se rencontrent : elle référence la séance prescrite (`workout`) et porte les
 * exercices réellement faits (`loggedExercises`). Il n'y a pas d'entité conteneur
 * du réalisé — celle-ci avait déjà l'owner, la date, le statut et la note d'écart.
 *
 * Une **séance libre** n'est rien d'autre qu'une séance datée sans source
 * (`workout = null`) : elle ne porte alors que son `title` et son réalisé.
 */
#[ORM\Entity(repositoryClass: ScheduledWorkoutRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_scheduled_workout_uuid', columns: ['uuid'])]
#[ORM\HasLifecycleCallbacks]
class ScheduledWorkout
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Identifiant stable de la séance datée, posé par le CLIENT quand c'est lui
     * qui la crée hors réseau. C'est ce qui rend `PUT /api/schedule/{uuid}`
     * idempotent : une écriture rejouée retombe sur la même ligne. Les séances
     * créées par le web reçoivent le leur au prePersist.
     */
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $uuid = null;

    #[ORM\ManyToOne(inversedBy: 'scheduledWorkouts')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?User $owner = null;

    // SET NULL, pas CASCADE : depuis qu'elle porte le réalisé, la séance datée a
    // du sens sans sa source. Cascader effacerait une séance réellement faite le
    // jour où on nettoie la bibliothèque — l'inverse exact de la décision
    // « préserver le réalisé ». Le snapshot `title` prend le relais à l'affichage.
    #[ORM\ManyToOne(inversedBy: 'scheduledWorkouts')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Workout $workout = null;

    /**
     * Titre au moment de la pose (ou titre propre d'une séance libre). Dupliqué
     * volontairement : c'est ce qui reste quand `workout` retombe à null.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

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

    // Bornes réelles de l'exécution, écrites par le mobile. Distinctes de la date
    // planifiée : on peut faire à 19h une séance prévue pour la journée.
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    /**
     * Le réalisé. Ne touche jamais au prescrit (Workout / PrescribedExercise /
     * PrescribedSet), qui reste la trace de l'intention.
     *
     * @var Collection<int, LoggedExercise>
     */
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[ORM\OneToMany(targetEntity: LoggedExercise::class, mappedBy: 'scheduledWorkout', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $loggedExercises;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(?Uuid $uuid = null)
    {
        // Créée côté web : on pose l'identifiant nous-mêmes. Créée par le mobile :
        // il fournit le sien, c'est lui la source (cf. feature-live-tracking §2.3).
        $this->uuid = $uuid ?? Uuid::v7();
        $this->loggedExercises = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();

        // Snapshot du titre à la pose, pour que la séance datée reste lisible si
        // sa source disparaît. L'affichage préfère le titre vivant tant qu'il
        // existe (getDisplayTitle) : le snapshot n'est qu'un filet.
        $this->title ??= $this->workout?->getTitle();
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

    public function getUuid(): ?Uuid
    {
        return $this->uuid;
    }

    public function setUuid(Uuid $uuid): static
    {
        $this->uuid = $uuid;

        return $this;
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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Ce qu'il faut afficher pour cette séance datée : le titre vivant de la
     * séance source tant qu'elle existe, puis le snapshot, puis un repli. Toute
     * vue d'une séance datée passe par ici plutôt que par `workout.title`, qui
     * peut être null (source supprimée, ou séance libre).
     */
    public function getDisplayTitle(): string
    {
        return $this->workout?->getTitle() ?? $this->title ?? 'Séance libre';
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

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): static
    {
        $this->endedAt = $endedAt;

        return $this;
    }

    /**
     * @return Collection<int, LoggedExercise>
     */
    public function getLoggedExercises(): Collection
    {
        return $this->loggedExercises;
    }

    public function addLoggedExercise(LoggedExercise $loggedExercise): static
    {
        // Maintient les DEUX côtés : sans ça la collection en mémoire reste
        // périmée et un fragment re-rendu dans la même requête ne voit rien.
        if (!$this->loggedExercises->contains($loggedExercise)) {
            $this->loggedExercises->add($loggedExercise);
            $loggedExercise->setScheduledWorkout($this);
        }

        return $this;
    }

    public function removeLoggedExercise(LoggedExercise $loggedExercise): static
    {
        if ($this->loggedExercises->removeElement($loggedExercise)) {
            if ($loggedExercise->getScheduledWorkout() === $this) {
                $loggedExercise->setScheduledWorkout(null);
            }
        }

        return $this;
    }

    /** La séance datée porte-t-elle un réalisé ? */
    public function hasLog(): bool
    {
        return !$this->loggedExercises->isEmpty();
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
