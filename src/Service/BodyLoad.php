<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\TargetArea;

/**
 * Ce qu'une séance charge, zone par zone — ce que la silhouette peint.
 *
 * Jumeau serveur de `kadens-mobile/src/session/areas.ts`, à ceci près que le
 * mobile lit le RÉALISÉ à la clôture d'une séance et que celui-ci lit le
 * PRESCRIT pendant qu'on la compose. Les deux consomment la même ventilation
 * `setsByArea` (`WorkoutMetrics::volume()` ici, le déroulé là-bas), et ce n'est
 * pas un hasard : les règles de comptage sont celles de `RegionBreakdown`, et
 * elles ne se rediscutent pas ici.
 *
 * 1. **Une série compte pour CHAQUE zone de l'exercice.** Un développé couché
 *    charge les pectoraux, les triceps et les épaules ; le total attribué dépasse
 *    donc le nombre de séries réelles, et c'est voulu.
 * 2. **Le pourcentage se calcule sur ce total attribué**, jamais sur le nombre de
 *    séries. Sans ça les parts dépasseraient 100 dès le premier exercice
 *    polyarticulaire.
 *
 * Des séries et non du tonnage : réparti sur trois zones, il faudrait une
 * pondération que personne n'a mesurée, et une planche n'en a aucun à donner.
 *
 * Le service ne lit aucune entité et n'a aucune dépendance : il transforme un
 * tableau en un autre. C'est ce qui le rend testable sans base.
 *
 * @phpstan-type AreaLoad array{area: TargetArea, sets: int, percent: float, level: int}
 * @phpstan-type BodyLoadResult array{areas: list<AreaLoad>, levels: array<string, int>, attributed: int, fullBody: int, unmapped: int}
 */
final class BodyLoad
{
    /**
     * La correspondance entre les zones de Kadens et les muscles du jeu de tracés.
     *
     * **Décision produit, donc ici et pas dans `data/body-plates.php`.** Elle est
     * bijective sur les seize zones anatomiques : chaque `TargetArea` a exactement
     * un muscle en face, et aucun muscle n'en reçoit deux. `FULL_BODY` n'y est pas
     * — elle ne se peint jamais, cf. `for()`.
     *
     * Recopiée à l'identique de `AREA_TO_SLUG` (`kadens-mobile/src/components/BodyMap.tsx`) :
     * les deux dessins doivent colorier les mêmes formes pour les mêmes zones.
     */
    public const AREA_TO_SLUG = [
        'chest' => 'chest',
        'back' => 'upper-back',
        'lower_back' => 'lower-back',
        'traps' => 'trapezius',
        'shoulders' => 'deltoids',
        'biceps' => 'biceps',
        'triceps' => 'triceps',
        'forearms' => 'forearm',
        'abs' => 'abs',
        'obliques' => 'obliques',
        'glutes' => 'gluteal',
        'quadriceps' => 'quadriceps',
        'hamstrings' => 'hamstring',
        'adductors' => 'adductors',
        'calves' => 'calves',
        'shins' => 'tibialis',
    ];

    /**
     * La charge par zone, à partir de la ventilation d'une séance.
     *
     * @param array<string, int> $setsByArea   séries attribuées par valeur de TargetArea,
     *                                         tel que le rend `WorkoutMetrics::volume()['gym']['setsByArea']`
     * @param int                $unmappedSets séries d'un exercice sans zone déclarée
     *                                         (`…['gym']['unmappedSets']`) : elles ne peuvent
     *                                         pas se déduire de `$setsByArea`, où une série
     *                                         polyarticulaire compte plusieurs fois
     *
     * @return BodyLoadResult `areas` trié par volume décroissant, `levels` indexé par
     *                        slug de muscle (ce dont le dessin a besoin)
     */
    public function for(array $setsByArea, int $unmappedSets = 0): array
    {
        $byArea = [];
        $attributed = 0;
        $fullBody = 0;

        foreach ($setsByArea as $value => $sets) {
            if ($sets <= 0) {
                continue;
            }

            $area = TargetArea::tryFrom((string) $value);
            if (null === $area) {
                continue;
            }

            // Un exercice « corps entier » ne peint rien : trois burpees
            // allumeraient la silhouette entière, et une carte qui s'allume partout
            // ne dit plus rien de la séance. La zone se compte à part et se dit en
            // légende — hors du dessin, jamais escamotée.
            if (TargetArea::FULL_BODY === $area) {
                $fullBody += $sets;

                continue;
            }

            $byArea[$area->value] = ($byArea[$area->value] ?? 0) + $sets;
            $attributed += $sets;
        }

        $peak = [] === $byArea ? 0 : max($byArea);

        $areas = [];
        $levels = [];
        foreach ($byArea as $value => $sets) {
            $area = TargetArea::from((string) $value);
            $level = $this->levelOf($sets, $peak);

            $areas[] = [
                'area' => $area,
                'sets' => $sets,
                'percent' => round($sets / $attributed * 100, 1),
                'level' => $level,
            ];
            $levels[self::AREA_TO_SLUG[$area->value]] = $level;
        }

        // Tri stable : à égalité de séries, l'ordre d'apparition dans la séance
        // tient (usort ne l'est pas en PHP < 8.0, il l'est depuis).
        usort($areas, static fn (array $a, array $b): int => $b['sets'] <=> $a['sets']);

        return [
            'areas' => $areas,
            'levels' => $levels,
            'attributed' => $attributed,
            'fullBody' => $fullBody,
            // Les séries qu'aucune zone n'a reçues. Dites en légende plutôt que
            // perdues — sinon une carte vide sur une séance pleine resterait
            // inexpliquée. Une séance de cardio pur, elle, rend simplement tout à
            // zéro : c'est un dessin vide, pas un état dégradé.
            'unmapped' => max(0, $unmappedSets),
        ];
    }

    /**
     * Le palier d'une zone, en tiers du maximum de la séance.
     *
     * **Relatif à la séance et non à un barème absolu** : la carte répond à « où
     * ai-je chargé », pas à « est-ce beaucoup ». Un seuil fixe dirait la même chose
     * d'une séance de dix séries et d'une de quarante.
     *
     * Trois paliers et pas plus : ce sont trois teintes distinguables d'un coup
     * d'œil, et le chiffre exact est de toute façon dans la légende.
     */
    private function levelOf(int $sets, int $peak): int
    {
        if ($peak <= 0) {
            return 1;
        }

        $share = $sets / $peak;

        if ($share > 2 / 3) {
            return 3;
        }

        return $share > 1 / 3 ? 2 : 1;
    }
}
