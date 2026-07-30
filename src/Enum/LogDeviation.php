<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * L'écart entre ce qui était prévu et ce qui a été fait, pour un exercice ou
 * pour une série (LogComparator).
 *
 * Six états, pas cinq : le modèle du réalisé distingue déjà l'exercice
 * **volontairement sauté** (`LoggedExercise.skipped`, une information) de
 * l'exercice **jamais logué** (un trou). Les confondre ferait dire à l'app que
 * l'athlète a déclaré quelque chose qu'il n'a pas déclaré.
 *
 * HELD est la valeur « rien à signaler » : c'est celle qu'on rend aussi quand
 * l'écart n'est pas mesurable (prescrit sans séries à apparier). On ne prétend
 * jamais mesurer ce qu'on ne sait pas comparer.
 */
enum LogDeviation: string
{
    case HELD = 'held';
    case EXCEEDED = 'exceeded';
    case LIGHTENED = 'lightened';
    case SKIPPED = 'skipped';
    case NOT_LOGGED = 'not_logged';
    case UNPLANNED = 'unplanned';

    public function getLabel(): string
    {
        return match ($this) {
            self::HELD => 'Tenu',
            self::EXCEEDED => 'Dépassé',
            self::LIGHTENED => 'Allégé',
            self::SKIPPED => 'Sauté',
            self::NOT_LOGGED => 'Non réalisé',
            self::UNPLANNED => 'Hors programme',
        };
    }

    public function isDeviation(): bool
    {
        return self::HELD !== $this;
    }
}
