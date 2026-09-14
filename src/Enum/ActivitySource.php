<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * D'où vient une activité importée. Une seule source aujourd'hui, mais le modèle
 * ne la suppose pas : l'unicité se pose sur le couple (`source`, `externalId`),
 * parce qu'un identifiant n'a de sens que chez celui qui l'a émis. Un import de
 * fichier FIT s'ajoutera ici sans toucher au reste.
 */
enum ActivitySource: string
{
    case INTERVALS = 'intervals';

    public function getLabel(): string
    {
        return match ($this) {
            self::INTERVALS => 'Intervals.icu',
        };
    }
}
