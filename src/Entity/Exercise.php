<?php

namespace App\Entity;

use App\Enum\ActivityType;
use App\Enum\TargetArea;
use App\Repository\ExerciseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ExerciseRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Exercise
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'exercises')]
    private ?User $owner = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(enumType: ActivityType::class)]
    #[Assert\NotNull(message: 'Choisis une activité.')]
    private ?ActivityType $activity = null;

    /**
     * @var TargetArea[]|null
     */
    #[ORM\Column(type: Types::SIMPLE_ARRAY, nullable: true, enumType: TargetArea::class)]
    private ?array $targetAreas = [];

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(message: 'Le lien média doit être une URL valide.')]
    #[Assert\Length(max: 255)]
    private ?string $mediaUrl = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, PrescribedExercise>
     */
    #[ORM\OneToMany(targetEntity: PrescribedExercise::class, mappedBy: 'exercise')]
    private Collection $prescribedExercises;

    public function __construct()
    {
        $this->prescribedExercises = new ArrayCollection();
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

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

    public function getActivity(): ?ActivityType
    {
        return $this->activity;
    }

    public function setActivity(ActivityType $activity): static
    {
        $this->activity = $activity;

        return $this;
    }


    /**
     * @return TargetArea[]|null
     */
    public function getTargetAreas(): ?array
    {
        return $this->targetAreas;
    }

    /**
     * @param TargetArea[]|null $targetAreas
     */
    public function setTargetAreas(?array $targetAreas): static
    {
        $this->targetAreas = $targetAreas;

        return $this;
    }

    public function getMediaUrl(): ?string
    {
        return $this->mediaUrl;
    }

    public function setMediaUrl(?string $mediaUrl): static
    {
        $this->mediaUrl = $mediaUrl;

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
     * @return Collection<int, PrescribedExercise>
     */
    public function getPrescribedExercises(): Collection
    {
        return $this->prescribedExercises;
    }

    public function addPrescribedExercise(PrescribedExercise $prescribedExercise): static
    {
        if (!$this->prescribedExercises->contains($prescribedExercise)) {
            $this->prescribedExercises->add($prescribedExercise);
            $prescribedExercise->setExercise($this);
        }

        return $this;
    }

    public function removePrescribedExercise(PrescribedExercise $prescribedExercise): static
    {
        if ($this->prescribedExercises->removeElement($prescribedExercise)) {
            // set the owning side to null (unless already changed)
            if ($prescribedExercise->getExercise() === $this) {
                $prescribedExercise->setExercise(null);
            }
        }

        return $this;
    }
}
