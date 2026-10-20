<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExerciseSetupRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Les **réglages** d'un exercice, notés par celui qui le pratique : hauteur du
 * dossier, cran de la poulie, position du boudin (« dossier : 5, jambe : 1 »).
 * Un bloc de texte libre, sans structure, parce qu'aucune machine ne règle les
 * mêmes choses.
 *
 * ## Pourquoi ni `Exercise` ni le réalisé
 *
 * - **Pas une colonne d'`Exercise`** : la bibliothèque globale est partagée, et
 *   `Exercise` n'a pas de paramètres (`CLAUDE.md` §3). Le cran de poulie de l'un
 *   n'est pas celui de l'autre ; la même ligne de bibliothèque porte donc une
 *   note **par pratiquant**.
 * - **Pas `LoggedExercise.notes`** : celle-là dit ce qui s'est passé **une**
 *   séance, et reste avec elle. Un réglage vaut d'une séance à l'autre, il doit
 *   se relire la fois suivante sans fouiller l'historique.
 *
 * ## Ce qui tient la forme
 *
 * - **Une ligne par (utilisateur, exercice)**, garantie par la base. Le texte
 *   s'écrase, il ne s'empile pas : c'est l'état de la machine, pas un journal.
 * - **Pas de ligne vide** : effacer le texte supprime la ligne
 *   (`Api\ExerciseController::setup`). « Aucun réglage » n'a qu'une écriture.
 * - **Deux `CASCADE`** : un compte supprimé n'a plus de machines à régler, un
 *   exercice supprimé n'a plus rien à régler. La relation est unidirectionnelle,
 *   ni `User` ni `Exercise` ne la connaissent — c'est la base qui nettoie.
 * - **Privée au pratiquant**, coach compris : le coach prescrit, il ne s'assied
 *   pas sur la machine de son athlète. Rien ne la lit en dehors de l'API mobile,
 *   et l'API ne la rend qu'à son auteur.
 */
#[ORM\Entity(repositoryClass: ExerciseSetupRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_exercise_setup_owner_exercise', columns: ['owner_id', 'exercise_id'])]
class ExerciseSetup
{
    /** Assez pour une machine à six réglages et une remarque ; pas assez pour un roman. */
    public const int MAX_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Exercise $exercise;

    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, Exercise $exercise, string $text, ?\DateTimeImmutable $at = null)
    {
        $this->owner = $owner;
        $this->exercise = $exercise;
        $this->text = $text;
        $this->updatedAt = $at ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getExercise(): Exercise
    {
        return $this->exercise;
    }

    public function getText(): string
    {
        return $this->text;
    }

    /**
     * Réécrit le texte. L'horodatage ne bouge que s'il a vraiment changé : un
     * `PUT` rejoué à l'identique ne doit pas faire croire à une modification.
     */
    public function rewrite(string $text, ?\DateTimeImmutable $at = null): static
    {
        if ($text !== $this->text) {
            $this->text = $text;
            $this->updatedAt = $at ?? new \DateTimeImmutable();
        }

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
