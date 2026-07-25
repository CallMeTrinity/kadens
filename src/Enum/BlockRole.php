<?php

declare(strict_types=1);

namespace App\Enum;

enum BlockRole: string
{
    case WARMUP = 'warmup';
    case MAIN = 'main';
    case COOLDOWN = 'cooldown';

    public function getLabel(): string
    {
        return match ($this) {
            self::WARMUP => 'Échauffement',
            self::MAIN => 'Entraînement',
            self::COOLDOWN => 'Retour au calme',
        };
    }
}
