<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Grand ensemble anatomique regroupant plusieurs TargetArea.
 *
 * Raison d'être : la répartition du volume d'une séance est illisible ventilée
 * sur les 17 zones de TargetArea. Les quatre régions donnent une barre empilée
 * qui se lit d'un coup d'œil, et se mappent une pour une sur l'échelle
 * catégorielle du design system (--color-cat-1..4), dans l'ordre de déclaration.
 *
 * Le regroupement reprend celui déjà exprimé en commentaires dans TargetArea :
 * il n'invente rien, il le rend exploitable.
 */
enum TargetRegion: string
{
    case LOWER_BODY = 'lower_body';
    case UPPER_BODY = 'upper_body';
    case CORE = 'core';
    case FULL_BODY = 'full_body';

    public function getLabel(): string
    {
        return match ($this) {
            self::LOWER_BODY => 'Bas du corps',
            self::UPPER_BODY => 'Haut du corps',
            self::CORE => 'Tronc',
            self::FULL_BODY => 'Corps entier',
        };
    }

    /**
     * Rang dans l'échelle catégorielle (1 = le plus dense). Consommé par les
     * templates pour choisir la classe `.kd-cat--{rank}`.
     */
    public function rank(): int
    {
        return match ($this) {
            self::LOWER_BODY => 1,
            self::UPPER_BODY => 2,
            self::CORE => 3,
            self::FULL_BODY => 4,
        };
    }

    public static function of(TargetArea $area): self
    {
        return match ($area) {
            TargetArea::GLUTES, TargetArea::QUADRICEPS, TargetArea::HAMSTRINGS,
            TargetArea::ADDUCTORS, TargetArea::CALVES, TargetArea::SHINS => self::LOWER_BODY,

            TargetArea::CHEST, TargetArea::BACK, TargetArea::LOWER_BACK,
            TargetArea::TRAPS, TargetArea::SHOULDERS, TargetArea::BICEPS,
            TargetArea::TRICEPS, TargetArea::FOREARMS => self::UPPER_BODY,

            TargetArea::ABS, TargetArea::OBLIQUES => self::CORE,

            TargetArea::FULL_BODY => self::FULL_BODY,
        };
    }
}
