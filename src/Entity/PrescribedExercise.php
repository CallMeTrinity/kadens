<?php

namespace App\Entity;

use App\Enum\PrescriptionType;
use App\Repository\PrescribedExerciseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PrescribedExerciseRepository::class)]
class PrescribedExercise
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'prescribedExercises')]
    private ?Block $block = null;

    #[ORM\ManyToOne(inversedBy: 'prescribedExercises')]
    private ?Exercise $exercise = null;

    #[ORM\Column]
    private ?int $position = null;

    /**
     * Liaison de superset : les exercices d'un MÊME bloc qui partagent ce numéro
     * s'enchaînent en alternance (A1 → A2 → repos → A1…). null = exercice isolé.
     *
     * Le numéro n'a de sens que dans son bloc et n'est qu'une clé de
     * regroupement : les libellés affichés (A, B, C…) se dérivent de l'ordre
     * d'apparition. Les membres d'un groupe sont tenus CONTIGUS en position par
     * SupersetGrouper, seule autorité sur ce champ.
     */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $supersetGroup = null;

    #[ORM\Column(enumType: PrescriptionType::class)]
    private ?PrescriptionType $prescriptionType = null;

    #[ORM\Column(nullable: true)]
    private ?int $sets = null;

    #[ORM\Column(nullable: true)]
    private ?int $reps = null;

    #[ORM\Column(nullable: true)]
    private ?float $weightKg = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column(nullable: true)]
    private ?int $distanceMeters = null;

    #[ORM\Column(nullable: true)]
    private ?int $paceSecondsPerKm = null;

    #[ORM\Column(nullable: true)]
    private ?int $targetReps = null;

    #[ORM\Column(nullable: true)]
    private ?int $capSeconds = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $intensityZone = null;

    #[ORM\Column(nullable: true)]
    private ?int $elevationGainMeters = null;

    #[ORM\Column(nullable: true)]
    private ?int $rpe = null;

    #[ORM\Column(nullable: true)]
    private ?int $restSeconds = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * Séries détaillées (mode optionnel des types de force). Vide = mode scalaire
     * (le compteur `sets`/`reps`/`weightKg` fait foi). Non vide = ces lignes
     * priment sur le compteur (voir hasDetailedSets et les helpers dérivés).
     *
     * @var Collection<int, PrescribedSet>
     */
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[ORM\OneToMany(targetEntity: PrescribedSet::class, mappedBy: 'prescribedExercise', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $detailedSets;

    public function __construct()
    {
        $this->detailedSets = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBlock(): ?Block
    {
        return $this->block;
    }

    public function setBlock(?Block $block): static
    {
        $this->block = $block;

        return $this;
    }

    public function getExercise(): ?Exercise
    {
        return $this->exercise;
    }

    public function setExercise(?Exercise $exercise): static
    {
        $this->exercise = $exercise;

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

    public function getSupersetGroup(): ?int
    {
        return $this->supersetGroup;
    }

    public function setSupersetGroup(?int $supersetGroup): static
    {
        $this->supersetGroup = $supersetGroup;

        return $this;
    }

    /**
     * L'exercice est-il lié à un ou plusieurs autres dans son bloc ?
     */
    public function isSuperset(): bool
    {
        return null !== $this->supersetGroup;
    }

    public function getPrescriptionType(): ?PrescriptionType
    {
        return $this->prescriptionType;
    }

    public function setPrescriptionType(PrescriptionType $prescriptionType): static
    {
        $this->prescriptionType = $prescriptionType;

        return $this;
    }

    public function getSets(): ?int
    {
        return $this->sets;
    }

    public function setSets(?int $sets): static
    {
        $this->sets = $sets;

        return $this;
    }

    public function getReps(): ?int
    {
        return $this->reps;
    }

    public function setReps(?int $reps): static
    {
        $this->reps = $reps;

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

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(?int $durationSeconds): static
    {
        $this->durationSeconds = $durationSeconds;

        return $this;
    }

    public function getDistanceMeters(): ?int
    {
        return $this->distanceMeters;
    }

    public function setDistanceMeters(?int $distanceMeters): static
    {
        $this->distanceMeters = $distanceMeters;

        return $this;
    }

    public function getPaceSecondsPerKm(): ?int
    {
        return $this->paceSecondsPerKm;
    }

    public function setPaceSecondsPerKm(?int $paceSecondsPerKm): static
    {
        $this->paceSecondsPerKm = $paceSecondsPerKm;

        return $this;
    }

    public function getTargetReps(): ?int
    {
        return $this->targetReps;
    }

    public function setTargetReps(?int $targetReps): static
    {
        $this->targetReps = $targetReps;

        return $this;
    }

    public function getCapSeconds(): ?int
    {
        return $this->capSeconds;
    }

    public function setCapSeconds(?int $capSeconds): static
    {
        $this->capSeconds = $capSeconds;

        return $this;
    }

    public function getIntensityZone(): ?string
    {
        return $this->intensityZone;
    }

    public function setIntensityZone(?string $intensityZone): static
    {
        $this->intensityZone = $intensityZone;

        return $this;
    }

    public function getElevationGainMeters(): ?int
    {
        return $this->elevationGainMeters;
    }

    public function setElevationGainMeters(?int $elevationGainMeters): static
    {
        $this->elevationGainMeters = $elevationGainMeters;

        return $this;
    }

    public function getRpe(): ?int
    {
        return $this->rpe;
    }

    public function setRpe(?int $rpe): static
    {
        $this->rpe = $rpe;

        return $this;
    }

    public function getRestSeconds(): ?int
    {
        return $this->restSeconds;
    }

    public function setRestSeconds(?int $restSeconds): static
    {
        $this->restSeconds = $restSeconds;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    // ---- Séries détaillées -------------------------------------------------

    /**
     * @return Collection<int, PrescribedSet>
     */
    public function getDetailedSets(): Collection
    {
        return $this->detailedSets;
    }

    public function addDetailedSet(PrescribedSet $set): static
    {
        // Maintient les DEUX côtés de la relation : sans ça la collection en
        // mémoire reste périmée et le stream re-rendu dans la foulée ne montre pas
        // la série (visible seulement au rechargement). Cf. mémoire projet.
        if (!$this->detailedSets->contains($set)) {
            $this->detailedSets->add($set);
            $set->setPrescribedExercise($this);
        }

        return $this;
    }

    public function removeDetailedSet(PrescribedSet $set): static
    {
        if ($this->detailedSets->removeElement($set)) {
            if ($set->getPrescribedExercise() === $this) {
                $set->setPrescribedExercise(null);
            }
        }

        return $this;
    }

    /**
     * L'exercice est-il en mode « séries détaillées » ? Non vide = les lignes
     * priment sur le compteur scalaire.
     */
    public function hasDetailedSets(): bool
    {
        return !$this->detailedSets->isEmpty();
    }

    // ---- Valeurs dérivées (mode scalaire OU détaillé) ----------------------
    // Consommées par les services de calcul (WorkoutMetrics, WorkoutEstimator,
    // ProgressionAggregator) pour rester détaillé-aware sans dupliquer la logique.

    /**
     * Nombre de séries « de travail » (hors échauffement) : lignes détaillées
     * comptées si présentes, sinon le compteur scalaire `sets`.
     */
    public function getWorkingSetCount(): int
    {
        if (!$this->hasDetailedSets()) {
            return $this->sets ?? 0;
        }

        $count = 0;
        foreach ($this->detailedSets as $set) {
            if ($set->getSetType()->countsAsWorking()) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Tonnage (reps × charge, sommé) des séries de travail, hors échauffement et
     * SANS les tours de bloc (le service les applique). Mode scalaire : dérivé du
     * compteur pour les SETS_REPS chargés, 0 sinon.
     */
    public function getTonnageKg(): float
    {
        if ($this->hasDetailedSets()) {
            $tonnage = 0.0;
            foreach ($this->detailedSets as $set) {
                if ($set->getSetType()->countsAsWorking()
                    && null !== $set->getReps() && null !== $set->getWeightKg()) {
                    $tonnage += $set->getReps() * $set->getWeightKg();
                }
            }

            return $tonnage;
        }

        if (PrescriptionType::SETS_REPS === $this->prescriptionType
            && null !== $this->weightKg && null !== $this->reps) {
            return ($this->sets ?? 0) * $this->reps * $this->weightKg;
        }

        return 0.0;
    }

    /**
     * Charge la plus lourde prescrite (top set) : max des charges des lignes
     * détaillées, sinon la charge scalaire. Sert de métrique de progression.
     */
    public function getTopWeightKg(): ?float
    {
        if (!$this->hasDetailedSets()) {
            return $this->weightKg;
        }

        $top = null;
        foreach ($this->detailedSets as $set) {
            if (null !== $set->getWeightKg()) {
                $top = null === $top ? $set->getWeightKg() : max($top, $set->getWeightKg());
            }
        }

        return $top;
    }
}
