<?php

declare(strict_types=1);
namespace App\Enum;

enum ActivityType: string
{
    case GYM = 'gym';
    case RUNNING = 'running';
    case SWIMMING = 'swimming';
    case CYCLING = 'cycling';
    case MOBILITY = 'mobility';
    case OTHER = 'other';

    public function getLabel(): string
    {
        return match($this) {
            self::GYM => 'Salle de sport',
            self::RUNNING => 'Course à pied',
            self::SWIMMING => 'Natation',
            self::CYCLING => 'Cyclisme',
            self::MOBILITY => 'Mobilité',
            self::OTHER => 'Autre',
        };
    }
}
