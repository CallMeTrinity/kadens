<?php

namespace App\Entity;

use App\Repository\LoggedSetRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une série RÉALISÉE, à une date donnée. C'est la moitié « réalisé » de la
 * boucle prévu vs réalisé, et elle est délibérément **séparée de la
 * prescription** : `PrescribedSet` dit ce qu'il fallait faire, `LoggedSet` dit
 * ce qui a été fait. Valider une série n'écrit donc jamais dans la séance, ce
 * qui préserve la bibliothèque et toutes les autres dates qui la référencent
 * (une même séance peut être posée sur dix jours).
 *
 * Pourquoi (prescribedExercise + setIndex) et non un lien vers PrescribedSet :
 * un exercice de force peut être en mode scalaire (`sets`/`reps`/`weightKg`),
 * auquel cas **aucune** ligne PrescribedSet n'existe en base alors que la vue en
 * déroule N (voir PlanFlattener::setLines). L'index rend le pointage uniforme
 * dans les deux modes, sans forcer la matérialisation du détail.
 *
 * Conséquences assumées :
 * - `setIndex` peut dépasser le nombre de séries prescrites : c'est une série
 *   faite en plus, et le modèle l'accepte sans rien changer ;
 * - si la prescription est réduite après coup, un log orphelin subsiste. Il est
 *   ignoré en lecture (SessionSheet n'expose que les index prescrits, plus les
 *   index supplémentaires réellement loggés), jamais supprimé en silence.
 *
 * Les types d'effort sans séries (course, AMRAP…) se valident en une seule
 * ligne, `setIndex` = 1 : « l'exercice est fait ».
 */
#[ORM\Entity(repositoryClass: LoggedSetRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_logged_set_line', columns: ['scheduled_workout_id', 'prescribed_exercise_id', 'set_index'])]
class LoggedSet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'loggedSets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ScheduledWorkout $scheduledWorkout = null;

    // Le log n'a aucun sens sans l'exercice prescrit qu'il valide : on cascade.
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PrescribedExercise $prescribedExercise = null;

    /** Rang de la série dans l'exercice, base 1 (aligné sur FlatSetLine.index). */
    #[ORM\Column]
    private ?int $setIndex = null;

    // Valeurs RÉELLES. Pré-remplies par le prévu à la validation, puis
    // modifiables : c'est ce qui donne l'écart. null = non renseigné, ce qui
    // reste distinct de « série non faite » (l'absence de LoggedSet).
    #[ORM\Column(nullable: true)]
    private ?int $reps = null;

    #[ORM\Column(nullable: true)]
    private ?float $weightKg = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct()
    {
        $this->completedAt = new \DateTimeImmutable();
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

    public function getPrescribedExercise(): ?PrescribedExercise
    {
        return $this->prescribedExercise;
    }

    public function setPrescribedExercise(?PrescribedExercise $prescribedExercise): static
    {
        $this->prescribedExercise = $prescribedExercise;

        return $this;
    }

    public function getSetIndex(): ?int
    {
        return $this->setIndex;
    }

    public function setSetIndex(int $setIndex): static
    {
        $this->setIndex = $setIndex;

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

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }
}
