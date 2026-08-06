<?php

namespace App\Entity;

use App\Repository\LoggedExerciseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un exercice RÉALISÉ pendant une séance datée. Pendant du PrescribedExercise,
 * qu'il ne remplace jamais : le prescrit ne bouge pas, le réalisé vit à côté
 * (cf. CLAUDE.md §3 et docs/feature-live-tracking.md §2).
 *
 * Il n'y a pas d'entité conteneur : c'est ScheduledWorkout qui porte le réalisé,
 * elle avait déjà l'owner, la date, le statut et la note d'écart.
 *
 * Deux liens sont volontairement faibles :
 * - `exercise` (SET NULL) + `exerciseName` en snapshot : supprimer un exercice de
 *   la bibliothèque ne doit pas rendre illisible une séance réellement faite ;
 * - `sourcePrescribedExercise` (SET NULL) : éditer la séance prescrite après coup
 *   ne doit jamais casser un réalisé déjà écrit. null = exercice hors programme
 *   (ajouté à la volée, ou séance libre).
 */
#[ORM\Entity(repositoryClass: LoggedExerciseRepository::class)]
#[ORM\Index(name: 'idx_logged_exercise_exercise', columns: ['exercise_id'])]
class LoggedExercise
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'loggedExercises')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ScheduledWorkout $scheduledWorkout = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Exercise $exercise = null;

    /**
     * Nom au moment de l'exécution. Dupliqué volontairement : c'est ce qui reste
     * quand l'exercice disparaît de la bibliothèque (même logique que le SET NULL
     * de sourcePlanItem).
     */
    #[ORM\Column(length: 255)]
    private ?string $exerciseName = null;

    /** Ligne du programme dont cette exécution découle. null = hors programme. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?PrescribedExercise $sourcePrescribedExercise = null;

    #[ORM\Column]
    private ?int $position = null;

    /**
     * Exercice prévu mais volontairement sauté. Distinct de « pas de série
     * loguée » : un exercice sauté est une information, pas un trou.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $skipped = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * @var Collection<int, LoggedSet>
     */
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[ORM\OneToMany(targetEntity: LoggedSet::class, mappedBy: 'loggedExercise', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $loggedSets;

    public function __construct()
    {
        $this->loggedSets = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getExercise(): ?Exercise
    {
        return $this->exercise;
    }

    public function setExercise(?Exercise $exercise): static
    {
        $this->exercise = $exercise;

        return $this;
    }

    public function getExerciseName(): ?string
    {
        return $this->exerciseName;
    }

    public function setExerciseName(string $exerciseName): static
    {
        $this->exerciseName = $exerciseName;

        return $this;
    }

    public function getSourcePrescribedExercise(): ?PrescribedExercise
    {
        return $this->sourcePrescribedExercise;
    }

    public function setSourcePrescribedExercise(?PrescribedExercise $sourcePrescribedExercise): static
    {
        $this->sourcePrescribedExercise = $sourcePrescribedExercise;

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

    public function isSkipped(): bool
    {
        return $this->skipped;
    }

    public function setSkipped(bool $skipped): static
    {
        $this->skipped = $skipped;

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

    /**
     * @return Collection<int, LoggedSet>
     */
    public function getLoggedSets(): Collection
    {
        return $this->loggedSets;
    }

    public function addLoggedSet(LoggedSet $set): static
    {
        // Maintient les DEUX côtés : sans ça la collection en mémoire reste
        // périmée et un fragment re-rendu dans la même requête ne voit pas la série.
        if (!$this->loggedSets->contains($set)) {
            $this->loggedSets->add($set);
            $set->setLoggedExercise($this);
        }

        return $this;
    }

    public function removeLoggedSet(LoggedSet $set): static
    {
        if ($this->loggedSets->removeElement($set)) {
            if ($set->getLoggedExercise() === $this) {
                $set->setLoggedExercise(null);
            }
        }

        return $this;
    }

    /**
     * Nombre de séries de TRAVAIL réalisées : l'échauffement est exclu du volume,
     * comme partout ailleurs dans le projet, et la série non chiffrée aussi
     * (cf. LoggedSet::countsAsWorking).
     */
    public function getWorkingSetCount(): int
    {
        $count = 0;
        foreach ($this->loggedSets as $set) {
            if ($set->countsAsWorking()) {
                ++$count;
            }
        }

        return $count;
    }
}
