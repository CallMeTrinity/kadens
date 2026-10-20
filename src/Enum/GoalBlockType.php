<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Nature d'un bloc du récit d'un objectif. Le récit est une suite ordonnée de
 * blocs, comme une séance est une suite de blocs d'exercices : du texte, une
 * photo, ou une **référence** à un fait déjà consigné ailleurs (une sortie
 * importée, une séance faite). Une référence ne recopie rien : elle se rend à
 * partir de la source qui fait autorité sur ses chiffres.
 */
enum GoalBlockType: string
{
    case TEXT = 'text';
    case PHOTO = 'photo';
    case ACTIVITY = 'activity';
    case SESSION = 'session';

    public function getLabel(): string
    {
        return match ($this) {
            self::TEXT => 'Texte',
            self::PHOTO => 'Photo',
            self::ACTIVITY => 'Activité importée',
            self::SESSION => 'Séance faite',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::TEXT => 'lucide:pilcrow',
            self::PHOTO => 'lucide:image',
            self::ACTIVITY => 'lucide:activity',
            self::SESSION => 'lucide:calendar-check',
        };
    }

    /** Le bloc pointe-t-il vers une entité consignée ailleurs ? */
    public function isReference(): bool
    {
        return self::ACTIVITY === $this || self::SESSION === $this;
    }
}
