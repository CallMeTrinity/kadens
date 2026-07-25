<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Objectif principal d'entraînement affiché sur la fiche athlète. Purement
 * déclaratif (aucune logique métier dérivée) : donne une identité à la fiche.
 */
enum TrainingGoal: string
{
    case STRENGTH = 'strength';
    case HYPERTROPHY = 'hypertrophy';
    case ENDURANCE = 'endurance';
    case HEALTH = 'health';

    public function getLabel(): string
    {
        return match ($this) {
            self::STRENGTH => 'Force',
            self::HYPERTROPHY => 'Hypertrophie',
            self::ENDURANCE => 'Endurance',
            self::HEALTH => 'Santé / forme',
        };
    }
}
