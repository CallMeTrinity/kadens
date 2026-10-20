<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GoalBlockType;
use App\Repository\GoalBlockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un bloc du récit d'un objectif (texte, photo, activité importée, séance
 * faite). Modèle unique piloté par `type`, comme `PrescribedExercise` l'est par
 * `PrescriptionType` : seuls les champs pertinents pour le type sont remplis.
 *
 * - TEXT     : `body` est le texte.
 * - PHOTO    : `photoFile` est le nom du fichier stocké (GoalPhotoStorage),
 *              `body` la légende.
 * - ACTIVITY : `importedActivity`, `body` un commentaire.
 * - SESSION  : `scheduledWorkout`, `body` un commentaire.
 *
 * Les références sont en **SET NULL** : retirer une séance du calendrier ou
 * purger un import ne doit pas effacer ce que l'auteur a écrit autour. Un bloc
 * dont la source a disparu est « orphelin » (`isOrphan`) : il ne rend plus que
 * son commentaire, et l'éditeur propose de le retirer.
 */
#[ORM\Entity(repositoryClass: GoalBlockRepository::class)]
#[ORM\HasLifecycleCallbacks]
class GoalBlock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'blocks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Goal $goal = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 20, enumType: GoalBlockType::class)]
    private GoalBlockType $type = GoalBlockType::TEXT;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    // Nom du fichier dans le dossier de GoalPhotoStorage, jamais un chemin.
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $photoFile = null;

    // Dimensions gardées pour poser width/height sur l'<img> : la page ne saute
    // pas quand la photo arrive.
    #[ORM\Column(nullable: true)]
    private ?int $photoWidth = null;

    #[ORM\Column(nullable: true)]
    private ?int $photoHeight = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ImportedActivity $importedActivity = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ScheduledWorkout $scheduledWorkout = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGoal(): ?Goal
    {
        return $this->goal;
    }

    public function setGoal(?Goal $goal): static
    {
        $this->goal = $goal;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getType(): GoalBlockType
    {
        return $this->type;
    }

    public function setType(GoalBlockType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getPhotoFile(): ?string
    {
        return $this->photoFile;
    }

    public function setPhotoFile(?string $photoFile): static
    {
        $this->photoFile = $photoFile;

        return $this;
    }

    public function getPhotoWidth(): ?int
    {
        return $this->photoWidth;
    }

    public function getPhotoHeight(): ?int
    {
        return $this->photoHeight;
    }

    public function setPhotoSize(?int $width, ?int $height): static
    {
        $this->photoWidth = $width;
        $this->photoHeight = $height;

        return $this;
    }

    public function getImportedActivity(): ?ImportedActivity
    {
        return $this->importedActivity;
    }

    public function setImportedActivity(?ImportedActivity $importedActivity): static
    {
        $this->importedActivity = $importedActivity;

        return $this;
    }

    public function getScheduledWorkout(): ?ScheduledWorkout
    {
        return $this->scheduledWorkout;
    }

    public function setScheduledWorkout(?ScheduledWorkout $scheduledWorkout): static
    {
        $this->scheduledWorkout = $scheduledWorkout;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Une référence dont la source a été supprimée (SET NULL). */
    public function isOrphan(): bool
    {
        return match ($this->type) {
            GoalBlockType::ACTIVITY => null === $this->importedActivity,
            GoalBlockType::SESSION => null === $this->scheduledWorkout,
            GoalBlockType::PHOTO => null === $this->photoFile,
            GoalBlockType::TEXT => false,
        };
    }
}
