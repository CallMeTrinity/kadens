<?php

namespace App\Entity;

use App\Enum\CoachingStatus;
use App\Repository\CoachingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Relation coach ↔ athlète : la seule entité du modèle qui relie deux `User`.
 *
 * Le contenu (séances, plans, séances datées) reste **possédé par l'athlète**,
 * y compris quand c'est le coach qui le crée : cette table ne dit pas « qui
 * possède » mais « qui a le droit d'aider ». Une relation ACCEPTED fait du coach
 * un co-éditeur du contenu de l'athlète (cf. `CoachingResolver` + voters).
 *
 * Un seul enregistrement par couple (coach, athlète) — UniqueConstraint : une
 * demande refusée se ré-ouvre en repassant la même ligne à PENDING, on ne crée
 * pas de doublon.
 */
#[ORM\Entity(repositoryClass: CoachingRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_COACHING_PAIR', columns: ['coach_id', 'athlete_id'])]
#[ORM\HasLifecycleCallbacks]
class Coaching
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'coachingAsCoach')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $coach = null;

    #[ORM\ManyToOne(inversedBy: 'coachingAsAthlete')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $athlete = null;

    #[ORM\Column(length: 20, enumType: CoachingStatus::class, options: ['default' => CoachingStatus::PENDING->value])]
    private CoachingStatus $status = CoachingStatus::PENDING;

    // Qui a initié la demande : distingue « demande reçue » de « demande envoyée »
    // à l'affichage, et interdit à l'émetteur de répondre à sa propre demande.
    // Nullable (SET NULL) : la relation survit à la suppression de l'initiateur.
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $requestedBy = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    // Date de la réponse (acceptation ou refus), null tant que PENDING.
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $respondedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
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

    public function getCoach(): ?User
    {
        return $this->coach;
    }

    public function setCoach(?User $coach): static
    {
        $this->coach = $coach;

        return $this;
    }

    public function getAthlete(): ?User
    {
        return $this->athlete;
    }

    public function setAthlete(?User $athlete): static
    {
        $this->athlete = $athlete;

        return $this;
    }

    public function getStatus(): CoachingStatus
    {
        return $this->status;
    }

    public function setStatus(CoachingStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getRequestedBy(): ?User
    {
        return $this->requestedBy;
    }

    public function setRequestedBy(?User $requestedBy): static
    {
        $this->requestedBy = $requestedBy;

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

    public function getRespondedAt(): ?\DateTimeImmutable
    {
        return $this->respondedAt;
    }

    public function setRespondedAt(?\DateTimeImmutable $respondedAt): static
    {
        $this->respondedAt = $respondedAt;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /** L'autre partie vue depuis `$user` (le coach si on est l'athlète, et inversement). */
    public function otherParty(User $user): ?User
    {
        return $this->coach === $user ? $this->athlete : $this->coach;
    }

    /** `$user` est-il le destinataire de la demande (donc seul habilité à répondre) ? */
    public function isRecipient(User $user): bool
    {
        return ($this->coach === $user || $this->athlete === $user)
            && $this->requestedBy !== $user;
    }
}
