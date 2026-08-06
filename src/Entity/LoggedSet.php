<?php

namespace App\Entity;

use App\Enum\SetType;
use App\Repository\LoggedSetRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Une série RÉALISÉE. Pendant du PrescribedSet, avec deux différences qui disent
 * tout de la nature du réalisé :
 *
 * - `rpe` et `completedAt` : la série prescrite exprime une intention, la série
 *   réalisée un fait daté et ressenti ;
 * - `uuid` **généré par le client**, unique : c'est ce qui rend
 *   `PUT /api/schedule/{uuid}` idempotent, donc la synchro différée possible.
 *   Une écriture rejouée retombe sur la même ligne au lieu d'en créer une seconde.
 *
 * Unités normalisées comme partout : kg et secondes, jamais de texte mixte.
 */
#[ORM\Entity(repositoryClass: LoggedSetRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_logged_set_uuid', columns: ['uuid'])]
class LoggedSet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Identifiant stable de la série, posé par le client mobile hors réseau.
     * char(36) via le type Doctrine `uuid` : le gain de place du binary(16) ne
     * compense pas l'illisibilité en debug sur un projet de cette taille.
     * Les séries créées côté serveur reçoivent le leur au prePersist.
     */
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $uuid = null;

    #[ORM\ManyToOne(inversedBy: 'loggedSets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?LoggedExercise $loggedExercise = null;

    #[ORM\Column]
    private ?int $position = null;

    #[ORM\Column(enumType: SetType::class, options: ['default' => 'normal'])]
    private SetType $setType = SetType::NORMAL;

    #[ORM\Column(nullable: true)]
    private ?int $reps = null;

    #[ORM\Column(nullable: true)]
    private ?float $weightKg = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column(nullable: true)]
    private ?int $rpe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(?Uuid $uuid = null)
    {
        // Créée côté serveur : on pose l'identifiant nous-mêmes. Créée par le
        // mobile : il fournit le sien, c'est lui la source (cf. §2.3 point 2).
        $this->uuid = $uuid ?? Uuid::v7();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): ?Uuid
    {
        return $this->uuid;
    }

    public function setUuid(Uuid $uuid): static
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getLoggedExercise(): ?LoggedExercise
    {
        return $this->loggedExercise;
    }

    public function setLoggedExercise(?LoggedExercise $loggedExercise): static
    {
        $this->loggedExercise = $loggedExercise;

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

    public function getSetType(): SetType
    {
        return $this->setType;
    }

    public function setSetType(SetType $setType): static
    {
        $this->setType = $setType;

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

    public function getRpe(): ?int
    {
        return $this->rpe;
    }

    public function setRpe(?int $rpe): static
    {
        $this->rpe = $rpe;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    /**
     * La série entre-t-elle dans le VOLUME de travail ?
     *
     * Deux conditions, pas une :
     *
     * - le **type** compte (l'échauffement n'est pas du volume, cf.
     *   `SetType::countsAsWorking()`) ;
     * - la série est **chiffrée** : au moins une répétition, ou au moins une
     *   seconde.
     *
     * La seconde condition est ce qui écarte la série cochée sans valeur (« ? »
     * à l'écran) ou ramenée à zéro répétition. Elle a eu lieu — le tableau de la
     * séance la montre, la comparaison prévu/réalisé la compte — mais elle ne
     * décrit aucun travail : la faire entrer dans le décompte de séries, la
     * ventilation par région ou la moyenne « séries par séance » ferait dire à
     * ces chiffres quelque chose qui n'a pas été mesuré. La charge seule ne
     * suffit pas : 100 kg × 0 rep, c'est une barre qu'on n'a pas soulevée.
     *
     * **Le pendant SQL de cette règle vit dans `LoggedSetRepository`**
     * (`workingSetScope()`, `workingSetWindow()`, `correlatedFrom()`) : les deux
     * définitions doivent bouger ensemble, sinon le bandeau d'une séance et
     * `/profile/stats` ne compteraient pas les mêmes séries.
     */
    public function countsAsWorking(): bool
    {
        if (!$this->setType->countsAsWorking()) {
            return false;
        }

        return ($this->reps ?? 0) > 0 || ($this->durationSeconds ?? 0) > 0;
    }

    /**
     * Tonnage de la série (reps × charge), 0 si elle n'est pas chiffrée en
     * charge — une série au poids du corps ou en durée n'a pas de tonnage.
     * L'échauffement et la série non chiffrée sont exclus du volume de travail,
     * comme partout (cf. `countsAsWorking()`).
     */
    public function getTonnageKg(): float
    {
        if (!$this->countsAsWorking() || null === $this->reps || null === $this->weightKg) {
            return 0.0;
        }

        return $this->reps * $this->weightKg;
    }
}
