<?php

namespace App\Entity;

use App\Enum\Sex;
use App\Enum\TrainingGoal;
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

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    // Jeton secret d'abonnement calendrier (ICS). Nullable tant que l'utilisateur
    // n'a pas activé l'abonnement ; régénérer = révoquer l'ancien lien. Sert
    // d'autorisation à lui seul (route /feed hors access_control, comme le partage
    // public par slug), d'où l'entropie (32 octets → 64 hex) et l'unicité.
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $calendarFeedToken = null;

    // --- Fiche athlète : identité -------------------------------------------

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $birthDate = null;

    #[ORM\Column(nullable: true, enumType: Sex::class)]
    private ?Sex $sex = null;

    #[ORM\Column(nullable: true)]
    private ?int $heightCm = null;

    #[ORM\Column(nullable: true)]
    private ?float $weightKg = null;

    #[ORM\Column(nullable: true)]
    private ?int $trainingYears = null;

    #[ORM\Column(nullable: true, enumType: TrainingGoal::class)]
    private ?TrainingGoal $mainGoal = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bio = null;

    // --- Fiche athlète : records de force (1RM, en kg) ----------------------

    #[ORM\Column(nullable: true)]
    private ?float $squat1rmKg = null;

    #[ORM\Column(nullable: true)]
    private ?float $bench1rmKg = null;

    #[ORM\Column(nullable: true)]
    private ?float $deadlift1rmKg = null;

    #[ORM\Column(nullable: true)]
    private ?float $ohp1rmKg = null;

    #[ORM\Column(nullable: true)]
    private ?float $weightedPullupKg = null;

    // --- Fiche athlète : records d'endurance (temps en secondes) ------------

    #[ORM\Column(nullable: true)]
    private ?int $run5kSeconds = null;

    #[ORM\Column(nullable: true)]
    private ?int $run10kSeconds = null;

    #[ORM\Column(nullable: true)]
    private ?int $halfMarathonSeconds = null;

    #[ORM\Column(nullable: true)]
    private ?int $marathonSeconds = null;

    #[ORM\Column(nullable: true)]
    private ?int $cyclingFtpWatts = null;

    #[ORM\Column(nullable: true)]
    private ?int $swim100mSeconds = null;

    // --- Fiche athlète : zones cardio (BPM) ---------------------------------
    // FC max/repos alimentent la dérivation Karvonen (service HeartRateZones) ;
    // les hrZoneNMax surchargent la borne haute d'une zone (null = dérivée).

    #[ORM\Column(nullable: true)]
    private ?int $maxHeartRate = null;

    #[ORM\Column(nullable: true)]
    private ?int $restingHeartRate = null;

    #[ORM\Column(nullable: true)]
    private ?int $hrZone1Max = null;

    #[ORM\Column(nullable: true)]
    private ?int $hrZone2Max = null;

    #[ORM\Column(nullable: true)]
    private ?int $hrZone3Max = null;

    #[ORM\Column(nullable: true)]
    private ?int $hrZone4Max = null;

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

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCalendarFeedToken(): ?string
    {
        return $this->calendarFeedToken;
    }

    public function setCalendarFeedToken(?string $calendarFeedToken): static
    {
        $this->calendarFeedToken = $calendarFeedToken;

        return $this;
    }

    public function getBirthDate(): ?\DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function setBirthDate(?\DateTimeImmutable $birthDate): static
    {
        $this->birthDate = $birthDate;

        return $this;
    }

    /**
     * Âge dérivé de la date de naissance (jamais stocké, ne vieillit pas).
     */
    public function getAge(): ?int
    {
        return $this->birthDate?->diff(new \DateTimeImmutable())->y;
    }

    public function getSex(): ?Sex
    {
        return $this->sex;
    }

    public function setSex(?Sex $sex): static
    {
        $this->sex = $sex;

        return $this;
    }

    public function getHeightCm(): ?int
    {
        return $this->heightCm;
    }

    public function setHeightCm(?int $heightCm): static
    {
        $this->heightCm = $heightCm;

        return $this;
    }

    public function getWeightKg(): ?float
    {
        return $this->weightKg;
    }

    public function setWeightKg(?float $weightKg): static
    {
        $this->weightKg = $weightKg;

        return $this;
    }

    public function getTrainingYears(): ?int
    {
        return $this->trainingYears;
    }

    public function setTrainingYears(?int $trainingYears): static
    {
        $this->trainingYears = $trainingYears;

        return $this;
    }

    public function getMainGoal(): ?TrainingGoal
    {
        return $this->mainGoal;
    }

    public function setMainGoal(?TrainingGoal $mainGoal): static
    {
        $this->mainGoal = $mainGoal;

        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): static
    {
        $this->bio = $bio;

        return $this;
    }

    public function getSquat1rmKg(): ?float
    {
        return $this->squat1rmKg;
    }

    public function setSquat1rmKg(?float $squat1rmKg): static
    {
        $this->squat1rmKg = $squat1rmKg;

        return $this;
    }

    public function getBench1rmKg(): ?float
    {
        return $this->bench1rmKg;
    }

    public function setBench1rmKg(?float $bench1rmKg): static
    {
        $this->bench1rmKg = $bench1rmKg;

        return $this;
    }

    public function getDeadlift1rmKg(): ?float
    {
        return $this->deadlift1rmKg;
    }

    public function setDeadlift1rmKg(?float $deadlift1rmKg): static
    {
        $this->deadlift1rmKg = $deadlift1rmKg;

        return $this;
    }

    public function getOhp1rmKg(): ?float
    {
        return $this->ohp1rmKg;
    }

    public function setOhp1rmKg(?float $ohp1rmKg): static
    {
        $this->ohp1rmKg = $ohp1rmKg;

        return $this;
    }

    public function getWeightedPullupKg(): ?float
    {
        return $this->weightedPullupKg;
    }

    public function setWeightedPullupKg(?float $weightedPullupKg): static
    {
        $this->weightedPullupKg = $weightedPullupKg;

        return $this;
    }

    /**
     * Total SBD (squat + bench + deadlift) dérivé, si les trois lifts sont
     * renseignés. Base du score de force normalisé.
     */
    public function getSbdTotalKg(): ?float
    {
        if (null === $this->squat1rmKg || null === $this->bench1rmKg || null === $this->deadlift1rmKg) {
            return null;
        }

        return $this->squat1rmKg + $this->bench1rmKg + $this->deadlift1rmKg;
    }

    public function getRun5kSeconds(): ?int
    {
        return $this->run5kSeconds;
    }

    public function setRun5kSeconds(?int $run5kSeconds): static
    {
        $this->run5kSeconds = $run5kSeconds;

        return $this;
    }

    public function getRun10kSeconds(): ?int
    {
        return $this->run10kSeconds;
    }

    public function setRun10kSeconds(?int $run10kSeconds): static
    {
        $this->run10kSeconds = $run10kSeconds;

        return $this;
    }

    public function getHalfMarathonSeconds(): ?int
    {
        return $this->halfMarathonSeconds;
    }

    public function setHalfMarathonSeconds(?int $halfMarathonSeconds): static
    {
        $this->halfMarathonSeconds = $halfMarathonSeconds;

        return $this;
    }

    public function getMarathonSeconds(): ?int
    {
        return $this->marathonSeconds;
    }

    public function setMarathonSeconds(?int $marathonSeconds): static
    {
        $this->marathonSeconds = $marathonSeconds;

        return $this;
    }

    public function getCyclingFtpWatts(): ?int
    {
        return $this->cyclingFtpWatts;
    }

    public function setCyclingFtpWatts(?int $cyclingFtpWatts): static
    {
        $this->cyclingFtpWatts = $cyclingFtpWatts;

        return $this;
    }

    public function getSwim100mSeconds(): ?int
    {
        return $this->swim100mSeconds;
    }

    public function setSwim100mSeconds(?int $swim100mSeconds): static
    {
        $this->swim100mSeconds = $swim100mSeconds;

        return $this;
    }

    public function getMaxHeartRate(): ?int
    {
        return $this->maxHeartRate;
    }

    public function setMaxHeartRate(?int $maxHeartRate): static
    {
        $this->maxHeartRate = $maxHeartRate;

        return $this;
    }

    public function getRestingHeartRate(): ?int
    {
        return $this->restingHeartRate;
    }

    public function setRestingHeartRate(?int $restingHeartRate): static
    {
        $this->restingHeartRate = $restingHeartRate;

        return $this;
    }

    public function getHrZone1Max(): ?int
    {
        return $this->hrZone1Max;
    }

    public function setHrZone1Max(?int $hrZone1Max): static
    {
        $this->hrZone1Max = $hrZone1Max;

        return $this;
    }

    public function getHrZone2Max(): ?int
    {
        return $this->hrZone2Max;
    }

    public function setHrZone2Max(?int $hrZone2Max): static
    {
        $this->hrZone2Max = $hrZone2Max;

        return $this;
    }

    public function getHrZone3Max(): ?int
    {
        return $this->hrZone3Max;
    }

    public function setHrZone3Max(?int $hrZone3Max): static
    {
        $this->hrZone3Max = $hrZone3Max;

        return $this;
    }

    public function getHrZone4Max(): ?int
    {
        return $this->hrZone4Max;
    }

    public function setHrZone4Max(?int $hrZone4Max): static
    {
        $this->hrZone4Max = $hrZone4Max;

        return $this;
    }

    /**
     * IMC dérivé (poids / taille²), arrondi à une décimale, si taille et poids
     * sont renseignés.
     */
    public function getBmi(): ?float
    {
        if (null === $this->weightKg || null === $this->heightCm || $this->heightCm <= 0) {
            return null;
        }

        $meters = $this->heightCm / 100;

        return round($this->weightKg / ($meters * $meters), 1);
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
