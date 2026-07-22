<?php

namespace App\Entity;

use App\Enum\BlockRole;
use App\Repository\BlockRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BlockRepository::class)]
class Block
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'blocks')]
    private ?Workout $workout = null;

    #[ORM\Column(enumType: BlockRole::class)]
    private ?BlockRole $role = null;

    #[ORM\Column(options: ['default' => 1])]
    private ?int $rounds = 1;

    #[ORM\Column]
    private ?int $position = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    /**
     * @var Collection<int, PrescribedExercise>
     */
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[ORM\OneToMany(targetEntity: PrescribedExercise::class, mappedBy: 'block', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $prescribedExercises;

    public function __construct()
    {
        $this->prescribedExercises = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getRole(): ?BlockRole
    {
        return $this->role;
    }

    public function setRole(BlockRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getRounds(): ?int
    {
        return $this->rounds;
    }

    public function setRounds(int $rounds): static
    {
        $this->rounds = $rounds;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

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
            $prescribedExercise->setBlock($this);
        }

        return $this;
    }

    public function removePrescribedExercise(PrescribedExercise $prescribedExercise): static
    {
        if ($this->prescribedExercises->removeElement($prescribedExercise)) {
            // set the owning side to null (unless already changed)
            if ($prescribedExercise->getBlock() === $this) {
                $prescribedExercise->setBlock(null);
            }
        }

        return $this;
    }
}
