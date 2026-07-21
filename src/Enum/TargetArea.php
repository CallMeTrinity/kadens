<?php

declare(strict_types=1);
namespace App\Enum;

enum TargetArea: string
{
    // Haut du corps
    case CHEST = 'chest';
    case BACK = 'back';
    case LOWER_BACK = 'lower_back';
    case TRAPS = 'traps';
    case SHOULDERS = 'shoulders';
    case BICEPS = 'biceps';
    case TRICEPS = 'triceps';
    case FOREARMS = 'forearms';

    // Tronc
    case ABS = 'abs';
    case OBLIQUES = 'obliques';

    // Bas du corps
    case GLUTES = 'glutes';
    case QUADRICEPS = 'quadriceps';
    case HAMSTRINGS = 'hamstrings';
    case ADDUCTORS = 'adductors';
    case CALVES = 'calves';

    case FULL_BODY = 'full_body';

    public function getLabel(): string
    {
        return match($this) {
            self::CHEST => 'Pectoraux',
            self::BACK => 'Dos',
            self::LOWER_BACK => 'Lombaires',
            self::TRAPS => 'Trapèzes',
            self::SHOULDERS => 'Épaules',
            self::BICEPS => 'Biceps',
            self::TRICEPS => 'Triceps',
            self::FOREARMS => 'Avant-bras',
            self::ABS => 'Abdominaux',
            self::OBLIQUES => 'Obliques',
            self::GLUTES => 'Fessiers',
            self::QUADRICEPS => 'Quadriceps',
            self::HAMSTRINGS => 'Ischio-jambiers',
            self::ADDUCTORS => 'Adducteurs',
            self::CALVES => 'Mollets',
            self::FULL_BODY => 'Corps entier',
        };
    }
}
