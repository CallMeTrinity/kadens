<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\IntensityZone;

/**
 * Temps passé dans chaque zone cardio, en durée lisible et en part du total.
 *
 * Extrait de ImportedActivityPresenter pour la même raison que RegionBreakdown :
 * une sortie (page de la séance) et une fenêtre de stats (onglet Endurance)
 * répartissent leur temps exactement de la même façon. Seul ce qu'on somme en
 * amont diffère. Une seule définition, donc une seule barre de zones possible.
 *
 * Les secondes sont celles figées à l'import (`ImportedActivity::hrZoneSeconds`),
 * avec les zones du profil Kadens de ce moment-là.
 *
 * @phpstan-type ZoneShare array{zone: IntensityZone, seconds: int, duration: string, percent: int}
 */
final class ZoneBreakdown
{
    public function __construct(private readonly UnitFormatter $units)
    {
    }

    /**
     * @param list<mixed>|null $seconds secondes en Z1..Z5, dans l'ordre de IntensityZone::cases()
     *
     * @return list<ZoneShare> vide sans données ou sans aucune seconde comptée
     */
    public function shares(?array $seconds): array
    {
        $values = self::seconds($seconds);
        $total = array_sum($values);

        if (0 === $total) {
            return [];
        }

        $zones = [];
        foreach (IntensityZone::cases() as $index => $zone) {
            $value = $values[$index];
            $zones[] = [
                'zone' => $zone,
                'seconds' => $value,
                'duration' => $this->units->duration($value),
                'percent' => (int) round($value * 100 / $total),
            ];
        }

        return $zones;
    }

    /**
     * Normalise un tableau de zones lu en base : cinq entiers positifs, zéro
     * pour toute valeur absente ou illisible. Null ou vide donne cinq zéros, ce
     * qui permet de sommer plusieurs sorties sans cas particulier.
     *
     * @param list<mixed>|null $seconds
     *
     * @return list<int>
     */
    public static function seconds(?array $seconds): array
    {
        $values = [];
        foreach (array_keys(IntensityZone::cases()) as $index) {
            $raw = $seconds[$index] ?? 0;
            $values[] = is_numeric($raw) ? max(0, (int) $raw) : 0;
        }

        return $values;
    }
}
