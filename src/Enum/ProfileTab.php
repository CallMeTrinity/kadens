<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Onglet affiché par `/profile` (`?tab=`).
 *
 * Le profil était une page longue qui répondait à tout à la fois. Il se lit
 * désormais en trois temps : l'aperçu (ce qu'on cherche en ouvrant le profil,
 * records et zones), la fiche complète (chaque mesure, renseignée ou non), les
 * statistiques depuis le début. « Où j'en suis cette semaine » est parti sur
 * l'accueil (`/`).
 *
 * Des liens et non un contrôleur `tabs`, comme `StatsTab` : chaque onglet a
 * son URL, la page reste auto-suffisante (cache offline) et lisible sans JS.
 * Un onglet ne filtre aucun calcul : `ProfileStats::for()` rend tout, l'onglet
 * choisit seulement les blocs rendus.
 */
enum ProfileTab: string
{
    case OVERVIEW = 'overview';
    case SHEET = 'sheet';
    case STATS = 'stats';

    public function getLabel(): string
    {
        return match ($this) {
            self::OVERVIEW => 'Aperçu',
            self::SHEET => 'Fiche complète',
            self::STATS => 'Stats',
        };
    }

    /**
     * Une valeur inconnue retombe sur l'aperçu : un lien périmé doit afficher
     * le profil, pas une 404.
     */
    public static function resolve(string $raw): self
    {
        return self::tryFrom($raw) ?? self::OVERVIEW;
    }
}
