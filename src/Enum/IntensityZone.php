<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Zone d'intensité cardio (Z1..Z5). Sert de valeur au champ `intensityZone` d'un
 * exercice prescrit (course/vélo/natation) et de socle aux zones BPM du profil.
 *
 * Les fourchettes de pourcentage par défaut (`defaultBounds`) sont exprimées en
 * fraction de la réserve cardiaque (méthode Karvonen). Elles ne servent QUE de
 * repli quand aucune borne n'est surchargée dans le profil : la conversion en BPM
 * réels est portée par le service HeartRateZones (source unique).
 */
enum IntensityZone: string
{
    case Z1 = 'z1';
    case Z2 = 'z2';
    case Z3 = 'z3';
    case Z4 = 'z4';
    case Z5 = 'z5';

    public function label(): string
    {
        return match ($this) {
            self::Z1 => 'Récupération',
            self::Z2 => 'Endurance',
            self::Z3 => 'Tempo',
            self::Z4 => 'Seuil',
            self::Z5 => 'VO2max',
        };
    }

    /**
     * « Z4 Seuil » : libellé compact pour les résumés (PlanFlattener).
     */
    public function shortLabel(): string
    {
        return sprintf('%s %s', strtoupper($this->value), $this->label());
    }

    /**
     * Fourchette [bas, haut] de pourcentage de réserve cardiaque, par défaut.
     *
     * @return array{0: float, 1: float}
     */
    public function defaultBounds(): array
    {
        return match ($this) {
            self::Z1 => [0.50, 0.60],
            self::Z2 => [0.60, 0.70],
            self::Z3 => [0.70, 0.80],
            self::Z4 => [0.80, 0.90],
            self::Z5 => [0.90, 1.00],
        };
    }
}
