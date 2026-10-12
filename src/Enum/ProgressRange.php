<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Fenêtre de lecture des courbes de progression d'un exercice
 * (`/exercise/{id}?range=`).
 *
 * Distincte de `StatsRange` à dessein : les statistiques répondent à « où j'en
 * suis cette semaine », une progression sur un exercice ne se lit pas en quatre
 * semaines (trois ou quatre points, aucune pente). Ses quatre fenêtres sont
 * celles d'un cycle : le plan en cours, une saison, une année, tout.
 *
 * PLAN est la seule fenêtre qui n'existe pas toujours : sans plan en cours sur le
 * calendrier, elle n'est pas proposée. C'est `ExerciseProgress` qui la résout en
 * bornes, et lui seul qui lit la valeur brute d'URL.
 */
enum ProgressRange: string
{
    case PLAN = 'plan';
    case SIX_MONTHS = '6m';
    case YEAR = '1y';
    case ALL = 'all';

    /**
     * Libellé court du sélecteur (segmenté), en condensé capitales côté CSS.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::PLAN => 'Plan',
            self::SIX_MONTHS => '6 mois',
            self::YEAR => '1 an',
            self::ALL => 'Tout',
        };
    }
}
