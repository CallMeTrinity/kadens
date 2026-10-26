<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Feeling;

/**
 * Le ressenti **lu** d'une séance faite : forme 1-5, difficulté 1-10.
 *
 * Valeur effective, jamais stockée telle quelle : celle saisie dans Kadens si
 * elle existe, sinon celle des activités Intervals rattachées (difficulté la
 * plus haute, forme la plus basse quand la montre en a produit plusieurs — on
 * garde le pire des deux). Chaque axe se résout indépendamment : une course
 * notée en difficulté sur la montre puis en forme dans Kadens porte les deux.
 * Résolu par `ScheduledWorkoutRepository::ratingsFor()`, en une requête.
 *
 * La difficulté de séance n'est pas le RPE des séries (`LoggedSet::$rpe`) :
 * c'est un jugement global porté à la fin, pas une moyenne.
 */
final readonly class SessionRating
{
    public const int FEELING_MIN = 1;
    public const int FEELING_MAX = 5;
    public const int RPE_MIN = 1;
    public const int RPE_MAX = 10;

    public function __construct(
        public ?int $feeling,
        public ?int $sessionRpe,
        /** Au moins un des deux axes vient de la montre, pas d'une saisie Kadens. */
        public bool $fromIntervals = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return null === $this->feeling && null === $this->sessionRpe;
    }

    public function feelingLabel(): ?string
    {
        return Feeling::labelOf($this->feeling);
    }

    public function effortLabel(): ?string
    {
        return self::effortLabelOf($this->sessionRpe);
    }

    /**
     * Les paliers de l'échelle RPE de Garmin. Une moyenne prend le palier de
     * son arrondi.
     */
    public static function effortLabelOf(int|float|null $rpe): ?string
    {
        if (null === $rpe) {
            return null;
        }

        return match ((int) round($rpe)) {
            1 => 'Très léger',
            2, 3 => 'Léger',
            4, 5, 6 => 'Modéré',
            7, 8 => 'Difficile',
            9 => 'Très difficile',
            10 => 'Maximal',
            default => null,
        };
    }

    /** Une valeur reçue, ramenée à null hors de l'échelle de la forme. */
    public static function boundFeeling(?int $value): ?int
    {
        return null !== $value && $value >= self::FEELING_MIN && $value <= self::FEELING_MAX ? $value : null;
    }

    /** Une valeur reçue, ramenée à null hors de l'échelle de difficulté. */
    public static function boundRpe(?int $value): ?int
    {
        return null !== $value && $value >= self::RPE_MIN && $value <= self::RPE_MAX ? $value : null;
    }
}
