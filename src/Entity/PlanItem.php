<?php

namespace App\Entity;

use App\Repository\PlanItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanItemRepository::class)]
class PlanItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'planItems')]
    private ?PlanTemplate $planTemplate = null;

    // Un PlanItem n'est qu'un placement de séance dans la trame : il n'a aucun
    // sens sans elle. Supprimer une séance la retire donc de toutes les cases.
    #[ORM\ManyToOne(inversedBy: 'planItems')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Workout $workout = null;

    #[ORM\Column]
    private ?int $weekNumber = null;

    #[ORM\Column]
    private ?int $dayOfWeek = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlanTemplate(): ?PlanTemplate
    {
        return $this->planTemplate;
    }

    public function setPlanTemplate(?PlanTemplate $planTemplate): static
    {
        $this->planTemplate = $planTemplate;

        return $this;
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

    public function getWeekNumber(): ?int
    {
        return $this->weekNumber;
    }

    public function setWeekNumber(int $weekNumber): static
    {
        $this->weekNumber = $weekNumber;

        return $this;
    }

    public function getDayOfWeek(): ?int
    {
        return $this->dayOfWeek;
    }

    public function setDayOfWeek(int $dayOfWeek): static
    {
        $this->dayOfWeek = $dayOfWeek;

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
}
