<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\IntensityZone;

/**
 * Calcule les zones cardio (BPM) d'un utilisateur à partir de sa fiche profil.
 *
 * Source unique consommée par le formulaire d'exercice prescrit (libellés des
 * choix de zone) ET par l'affichage du profil. Deux modes combinés :
 *  - dérivation automatique (Karvonen : bpm = repos + pct × (max − repos)),
 *  - override manuel de la borne haute de chaque zone (User::hrZoneNMax).
 *
 * Sans FC max renseignée, les zones existent quand même mais sans bornes BPM
 * (le concept Z1..Z5 reste sélectionnable, juste sans repère chiffré).
 */
final class HeartRateZones
{
    /**
     * @return list<array{zone: IntensityZone, min: ?int, max: ?int}>
     */
    public function forUser(User $user): array
    {
        $max = $user->getMaxHeartRate();

        if (null === $max) {
            return array_map(
                static fn (IntensityZone $zone): array => ['zone' => $zone, 'min' => null, 'max' => null],
                IntensityZone::cases(),
            );
        }

        $resting = $user->getRestingHeartRate() ?? 0;

        // Bornes hautes effectives de Z1..Z4 : override si présent, sinon dérivée
        // Karvonen. Z5 plafonne à la FC max. La borne basse de Z1 est dérivée à
        // 50 % de la réserve. Chaque zone reprend la borne haute de la précédente
        // pour rester contiguë même en cas d'override.
        $overrides = [
            $user->getHrZone1Max(),
            $user->getHrZone2Max(),
            $user->getHrZone3Max(),
            $user->getHrZone4Max(),
        ];

        $karvonen = static fn (float $pct): int => (int) round($resting + $pct * ($max - $resting));

        $lower = $karvonen(IntensityZone::Z1->defaultBounds()[0]);

        $zones = [];
        foreach (IntensityZone::cases() as $index => $zone) {
            if (IntensityZone::Z5 === $zone) {
                $upper = $max;
            } else {
                $upper = $overrides[$index] ?? $karvonen($zone->defaultBounds()[1]);
            }

            $zones[] = ['zone' => $zone, 'min' => $lower, 'max' => $upper];
            $lower = $upper;
        }

        return $zones;
    }
}
