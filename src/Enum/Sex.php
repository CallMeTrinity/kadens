<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Sexe biologique renseigné sur la fiche athlète. Sert notamment au calcul du
 * score de force normalisé (DOTS), dont les coefficients diffèrent H/F.
 */
enum Sex: string
{
    case MALE = 'male';
    case FEMALE = 'female';
    case OTHER = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::MALE => 'Homme',
            self::FEMALE => 'Femme',
            self::OTHER => 'Autre',
        };
    }
}
