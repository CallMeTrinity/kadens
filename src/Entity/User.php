<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * @var Collection<int, Exercise>
     */
    #[ORM\OneToMany(targetEntity: Exercise::class, mappedBy: 'owner')]
    private Collection $exercises;

    /**
     * @var Collection<int, Workout>
     */
    #[ORM\OneToMany(targetEntity: Workout::class, mappedBy: 'owner')]
    private Collection $workouts;

    /**
     * @var Collection<int, PlanTemplate>
     */
    #[ORM\OneToMany(targetEntity: PlanTemplate::class, mappedBy: 'owner')]
    private Collection $planTemplates;

    /**
     * @var Collection<int, ScheduledWorkout>
     */
    #[ORM\OneToMany(targetEntity: ScheduledWorkout::class, mappedBy: 'owner')]
    private Collection $scheduledWorkouts;

    public function __construct()
    {
        $this->exercises = new ArrayCollection();
        $this->workouts = new ArrayCollection();
        $this->planTemplates = new ArrayCollection();
        $this->scheduledWorkouts = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
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

    /**
     * @return Collection<int, Exercise>
     */
    public function getExercises(): Collection
    {
        return $this->exercises;
    }

    public function addExercise(Exercise $exercise): static
    {
        if (!$this->exercises->contains($exercise)) {
            $this->exercises->add($exercise);
            $exercise->setOwner($this);
        }

        return $this;
    }

    public function removeExercise(Exercise $exercise): static
    {
        if ($this->exercises->removeElement($exercise)) {
            // set the owning side to null (unless already changed)
            if ($exercise->getOwner() === $this) {
                $exercise->setOwner(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Workout>
     */
    public function getWorkouts(): Collection
    {
        return $this->workouts;
    }

    public function addWorkout(Workout $workout): static
    {
        if (!$this->workouts->contains($workout)) {
            $this->workouts->add($workout);
            $workout->setOwner($this);
        }

        return $this;
    }

    public function removeWorkout(Workout $workout): static
    {
        if ($this->workouts->removeElement($workout)) {
            // set the owning side to null (unless already changed)
            if ($workout->getOwner() === $this) {
                $workout->setOwner(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PlanTemplate>
     */
    public function getPlanTemplates(): Collection
    {
        return $this->planTemplates;
    }

    public function addPlanTemplate(PlanTemplate $planTemplate): static
    {
        if (!$this->planTemplates->contains($planTemplate)) {
            $this->planTemplates->add($planTemplate);
            $planTemplate->setOwner($this);
        }

        return $this;
    }

    public function removePlanTemplate(PlanTemplate $planTemplate): static
    {
        if ($this->planTemplates->removeElement($planTemplate)) {
            // set the owning side to null (unless already changed)
            if ($planTemplate->getOwner() === $this) {
                $planTemplate->setOwner(null);
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
            $scheduledWorkout->setOwner($this);
        }

        return $this;
    }

    public function removeScheduledWorkout(ScheduledWorkout $scheduledWorkout): static
    {
        if ($this->scheduledWorkouts->removeElement($scheduledWorkout)) {
            // set the owning side to null (unless already changed)
            if ($scheduledWorkout->getOwner() === $this) {
                $scheduledWorkout->setOwner(null);
            }
        }

        return $this;
    }
}
