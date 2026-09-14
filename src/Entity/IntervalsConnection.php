<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IntervalsConnectionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le lien entre un compte Kadens et son compte Intervals.icu : une clé d'API
 * personnelle, collée par l'utilisateur dans `/profile/settings`.
 *
 * **La clé est chiffrée, pas hachée** (`SecretBox`) : Kadens doit la présenter à
 * Intervals à chaque synchronisation, une empreinte ne le permettrait pas. Elle
 * n'est jamais réaffichée. Elle donne accès en lecture ET en écriture au compte
 * Intervals, d'où le chiffrement même en base privée.
 *
 * Pas de colonne d'identifiant athlète : l'API accepte `0` pour « le titulaire de
 * la clé », ce qui évite de demander un identifiant de plus à coller.
 */
#[ORM\Entity(repositoryClass: IntervalsConnectionRepository::class)]
class IntervalsConnection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /** Clé d'API scellée par `SecretBox`. Jamais la clé en clair. */
    #[ORM\Column(type: 'text')]
    private string $sealedApiKey;

    /** Nom du compte Intervals au moment de la connexion, pour dire « connecté à … ». */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $athleteLabel = null;

    /**
     * Date locale de la dernière activité **traitée**, pas l'heure du clic : un
     * lot interrompu reprend là où il s'est arrêté, sans rien sauter.
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $syncedThrough = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $owner, string $sealedApiKey, ?string $athleteLabel)
    {
        $this->owner = $owner;
        $this->sealedApiKey = $sealedApiKey;
        $this->athleteLabel = $athleteLabel;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getSealedApiKey(): string
    {
        return $this->sealedApiKey;
    }

    /** Remplacer la clé repart de la même fenêtre : c'est le même compte, ou pas, mais l'idempotence tient. */
    public function replaceApiKey(string $sealedApiKey, ?string $athleteLabel): static
    {
        $this->sealedApiKey = $sealedApiKey;
        $this->athleteLabel = $athleteLabel;

        return $this;
    }

    public function getAthleteLabel(): ?string
    {
        return $this->athleteLabel;
    }

    public function getSyncedThrough(): ?\DateTimeImmutable
    {
        return $this->syncedThrough;
    }

    public function setSyncedThrough(?\DateTimeImmutable $syncedThrough): static
    {
        $this->syncedThrough = $syncedThrough;

        return $this;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?\DateTimeImmutable $lastSyncedAt): static
    {
        $this->lastSyncedAt = $lastSyncedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
