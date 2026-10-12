<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use App\Entity\User;
use App\Enum\BodySide;
use App\Enum\BodySilhouette;

/**
 * La silhouette de la fiche d'un exercice : les zones qu'il déclare, peintes sur
 * le même dessin que le volume d'une séance (`components/_body_map.html.twig`).
 *
 * Pas de règle propre : les zones passent par `BodyLoad`, comme si l'exercice
 * était une séance d'une série. Le corps entier n'y est donc pas peint (il
 * allumerait tout), et la correspondance zone → muscle reste celle du jumeau
 * mobile. Une seule série par zone, donc un seul palier : un exercice ne dit pas
 * quelle zone il charge le plus, et le dessin n'a pas à l'inventer.
 *
 * La silhouette suit **celui qui regarde**, comme partout (c'est un réglage
 * d'affichage, cf. `VolumePanel`) : un coach sur la fiche de son athlète voit la
 * sienne.
 *
 * @phpstan-type ExerciseBodyMapData array{plates: list<array<string, mixed>>, levels: array<string, int>}
 */
final class ExerciseBodyMap
{
    public function __construct(
        private readonly BodyLoad $bodyLoad,
        private readonly BodyPlates $bodyPlates,
    ) {
    }

    /**
     * null quand il n'y a rien à peindre : un exercice sans zone, ou « corps
     * entier » seul. Deux silhouettes grises n'apprendraient rien que les
     * étiquettes de zones ne disent déjà.
     *
     * @return ExerciseBodyMapData|null
     */
    public function for(Exercise $exercise, ?User $viewer): ?array
    {
        $setsByArea = [];
        foreach ($exercise->getTargetAreas() ?? [] as $area) {
            $setsByArea[$area->value] = 1;
        }

        $levels = $this->bodyLoad->for($setsByArea)['levels'];
        if ([] === $levels) {
            return null;
        }

        $silhouette = $viewer?->getBodySilhouette() ?? BodySilhouette::MALE;

        return [
            'plates' => [
                $this->bodyPlates->plate($silhouette, BodySide::FRONT),
                $this->bodyPlates->plate($silhouette, BodySide::BACK),
            ],
            'levels' => $levels,
        ];
    }
}
