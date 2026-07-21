<?php

declare(strict_types=1);
namespace App\Enum;

enum TargetArea: string
{
    case CHEST = 'chest';
    case BACK = 'back';
    case SHOULDERS = 'shoulders';
    case ARMS = 'arms';
    case LEGS = 'legs';
    case CORE = 'core';
    case FULL_BODY = 'full_body';

    public function getLabel(): string
    {
        return match($this) {
            self::CHEST => 'Poitrine',
            self::BACK => 'Dos',
            self::SHOULDERS => 'Épaules',
            self::ARMS => 'Bras',
            self::LEGS => 'Jambes',
            self::CORE => 'Tronc',
            self::FULL_BODY => 'Corps entier',
        };
    }
}
