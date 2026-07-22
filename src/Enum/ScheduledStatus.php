<?php

declare(strict_types=1);

namespace App\Enum;

enum ScheduledStatus: string
{
    case PLANNED = 'planned';
    case DONE = 'done';
    case MISSED = 'missed';

    public function getLabel(): string
    {
        return match ($this) {
            self::PLANNED => 'Prévue',
            self::DONE => 'Faite',
            self::MISSED => 'Manquée',
        };
    }
}
