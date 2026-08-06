<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Fenêtre de lecture des statistiques d'entraînement.
 *
 * Quatre cas et pas un de plus, parce qu'ils répondent à quatre questions
 * différentes : « où j'en suis là » (4 semaines), « est-ce que la saison
 * tient » (6 mois), « qu'est-ce que j'ai accumulé » (tous temps) et « ce
 * mois-là précisément » (un mois choisi).
 *
 * MONTH est le seul cas paramétré : sa valeur d'URL n'est pas `month` mais le
 * mois lui-même (`2026-07`). L'enum dit le *type* de fenêtre, StatsPeriod la
 * résout en bornes de dates — c'est lui, et lui seul, qui lit la chaîne brute.
 */
enum StatsRange: string
{
    case FOUR_WEEKS = '4w';
    case SIX_MONTHS = '6m';
    case ALL = 'all';
    case MONTH = 'month';

    public function getLabel(): string
    {
        return match ($this) {
            self::FOUR_WEEKS => '4 dernières semaines',
            self::SIX_MONTHS => '6 derniers mois',
            self::ALL => 'Depuis le début',
            self::MONTH => 'Un mois',
        };
    }

    /**
     * Libellé court du sélecteur (segmenté), en condensé capitales côté CSS.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::FOUR_WEEKS => '4 semaines',
            self::SIX_MONTHS => '6 mois',
            self::ALL => 'Tout',
            self::MONTH => 'Par mois',
        };
    }

    /**
     * Les trois fenêtres directement cliquables. MONTH en est exclu : il ne se
     * choisit pas d'un clic, il se choisit avec un mois.
     *
     * @return list<self>
     */
    public static function pickable(): array
    {
        return [self::FOUR_WEEKS, self::SIX_MONTHS, self::ALL];
    }
}
