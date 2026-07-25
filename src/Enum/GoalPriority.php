<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Priorité d'un objectif, calquée sur la périodisation classique des athlètes :
 * un objectif A est LE pic de forme visé, les B/C sont des jalons de préparation.
 * Sert au tri et au marquage visuel, jamais à une logique métier.
 */
enum GoalPriority: string
{
    case A = 'a';
    case B = 'b';
    case C = 'c';

    public function getLabel(): string
    {
        return match ($this) {
            self::A => 'Objectif principal (A)',
            self::B => 'Objectif secondaire (B)',
            self::C => 'Objectif préparatoire (C)',
        };
    }

    public function shortLabel(): string
    {
        return strtoupper($this->value);
    }
}
