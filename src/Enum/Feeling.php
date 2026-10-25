<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * La forme ressentie sur une séance, à l'échelle de Garmin (« Comment vous
 * êtes-vous senti ? »), du plus faible au plus en forme.
 *
 * **Adossée à un entier, par exception à la convention `enum X: string`** : c'est
 * un ordinal, et la colonne doit le rester pour que `AVG()` ait un sens en SQL
 * (une forme moyenne de 3,6 se lit entre « normal » et « en forme »). C'est aussi
 * pour ça que les entités stockent l'entier et non l'enum : l'enum ne sert
 * qu'à nommer et à borner.
 */
enum Feeling: int
{
    case VERY_WEAK = 1;
    case WEAK = 2;
    case NORMAL = 3;
    case STRONG = 4;
    case VERY_STRONG = 5;

    public function getLabel(): string
    {
        return match ($this) {
            self::VERY_WEAK => 'Très faible',
            self::WEAK => 'Faible',
            self::NORMAL => 'Normal',
            self::STRONG => 'En forme',
            self::VERY_STRONG => 'Très en forme',
        };
    }

    /**
     * Le libellé d'une moyenne : le cran le plus proche. Null hors échelle.
     */
    public static function labelOf(int|float|null $value): ?string
    {
        if (null === $value) {
            return null;
        }

        return self::tryFrom((int) round($value))?->getLabel();
    }
}
