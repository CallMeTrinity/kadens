<?php

declare(strict_types=1);

namespace App\Enum;

enum PrescriptionType: string
{
    case SETS_REPS = 'sets_reps';
    case SETS_TIME = 'sets_time';
    case AMRAP = 'amrap';
    case FOR_TIME = 'for_time';
    case DISTANCE_PACE = 'distance_pace';
    case DURATION = 'duration';

    public function getLabel(): string
    {
        return match ($this) {
            self::SETS_REPS => 'Séries × répétitions',
            self::SETS_TIME => 'Séries × durée',
            self::AMRAP => 'AMRAP',
            self::FOR_TIME => 'For time',
            self::DISTANCE_PACE => 'Distance × allure',
            self::DURATION => 'Durée',
        };
    }
}
