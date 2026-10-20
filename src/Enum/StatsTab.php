<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Rubrique affichée par `/profile/stats` (`?tab=`).
 *
 * Trois rubriques et pas une par sport : la salle a de quoi remplir une page
 * (tonnage, régions, rampe, records), l'endurance pas encore — course, vélo et
 * natation tiennent côte à côte en une tuile chacun. Les découper aujourd'hui
 * donnerait trois onglets presque vides.
 *
 * Une rubrique ne filtre rien : `TrainingStats::over()` calcule la fenêtre
 * entière et l'onglet choisit seulement les blocs rendus. Les chiffres d'un
 * onglet à l'autre viennent donc du même agrégat et ne peuvent pas diverger.
 */
enum StatsTab: string
{
    case OVERVIEW = 'overview';
    case GYM = 'gym';
    case ENDURANCE = 'endurance';

    public function getLabel(): string
    {
        return match ($this) {
            self::OVERVIEW => 'Vue d\'ensemble',
            self::GYM => 'Salle',
            self::ENDURANCE => 'Endurance',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::OVERVIEW => 'lucide:gauge',
            self::GYM => 'lucide:dumbbell',
            self::ENDURANCE => 'lucide:footprints',
        };
    }

    /**
     * Lit la valeur brute de l'URL. Une valeur inconnue retombe sur la vue
     * d'ensemble, comme `StatsPeriod::resolve()` : un lien périmé doit
     * afficher des statistiques, pas une 404.
     */
    public static function resolve(string $raw): self
    {
        return self::tryFrom($raw) ?? self::OVERVIEW;
    }
}
