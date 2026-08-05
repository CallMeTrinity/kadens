<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\TargetArea;
use App\Enum\TargetRegion;

/**
 * Ventilation d'un volume de séries par grande région anatomique.
 *
 * Extrait de WorkoutMetrics parce que le réalisé (LogMetrics) répartit son
 * volume exactement de la même façon que le prescrit : seule la façon de
 * compter les séries par zone diffère, pas leur regroupement ni le calcul des
 * parts. Une seule définition, donc une seule barre empilée possible.
 *
 * @phpstan-type RegionShare array{region: TargetRegion, sets: int, percent: float}
 */
final class RegionBreakdown
{
    /**
     * Répartition par grande région anatomique, en part des séries attribuées.
     * Voir TargetRegion : ventiler les 17 zones de TargetArea donnerait une
     * barre illisible.
     *
     * Attention, le total des séries par zone dépasse le nombre de séries
     * réelles (une série compte pour CHAQUE zone ciblée). Le pourcentage se
     * calcule donc sur ce total attribué, jamais sur le nombre de séries.
     *
     * @param array<string, int> $setsByArea séries attribuées, indexées par valeur de TargetArea
     *
     * @return list<RegionShare> trié par volume décroissant, régions vides omises
     */
    public function shares(array $setsByArea): array
    {
        $byRegion = [];
        $total = 0;

        foreach ($setsByArea as $areaValue => $sets) {
            $area = TargetArea::tryFrom($areaValue);
            if (null === $area) {
                continue;
            }

            $region = TargetRegion::of($area);
            $byRegion[$region->value] = ($byRegion[$region->value] ?? 0) + $sets;
            $total += $sets;
        }

        if (0 === $total) {
            return [];
        }

        $shares = [];
        foreach (TargetRegion::cases() as $region) {
            $sets = $byRegion[$region->value] ?? 0;
            if ($sets > 0) {
                $shares[] = [
                    'region' => $region,
                    'sets' => $sets,
                    'percent' => round($sets / $total * 100, 1),
                ];
            }
        }

        usort($shares, static fn (array $a, array $b): int => $b['sets'] <=> $a['sets']);

        return $shares;
    }
}
