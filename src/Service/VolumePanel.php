<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BodySide;
use App\Enum\BodySilhouette;

/**
 * Le bandeau « volume prévu » d'une séance, prêt à rendre : la silhouette, les
 * paliers de charge, et les chiffres — salle ET endurance.
 *
 * Extrait du contrôleur parce qu'il sert désormais deux écrans qui ne le
 * chargent pas de la même façon : le compositeur, qui le rafraîchit en Turbo
 * Stream à chaque mutation, et les pages de consultation, qui le rendent dans le
 * HTML initial (aucun AJAX post-chargement, condition du cache offline). Une
 * seule composition du contexte, deux modes de livraison.
 *
 * La **silhouette** se lit sur l'utilisateur qui REGARDE et non sur le
 * propriétaire de la séance, contrairement aux chiffres : c'est un réglage
 * d'affichage. Un coach voit donc la silhouette qu'il a choisie, avec le volume
 * de son athlète.
 *
 * @phpstan-import-type WorkoutVolume from WorkoutMetrics
 *
 * @phpstan-type EnduranceLine array{activity: ActivityType, meters: int, derivedMeters: int, distanceLabel: string, durationLabel: string|null}
 * @phpstan-type VolumePanelData array{volume: WorkoutVolume, load: array<string, mixed>, plates: list<array<string, mixed>>, endurance: list<EnduranceLine>}
 */
final class VolumePanel
{
    public function __construct(
        private readonly WorkoutMetrics $metrics,
        private readonly BodyLoad $bodyLoad,
        private readonly BodyPlates $bodyPlates,
        private readonly UnitFormatter $units,
    ) {
    }

    /**
     * @param User|null $viewer celui qui regarde, pour sa silhouette (null = repli)
     *
     * @return VolumePanelData
     */
    public function for(Workout $workout, ?User $viewer): array
    {
        $volume = $this->metrics->volume($workout);
        $silhouette = $viewer?->getBodySilhouette() ?? BodySilhouette::MALE;

        return [
            'volume' => $volume,
            'load' => $this->bodyLoad->for($volume['gym']['setsByArea'], $volume['gym']['unmappedSets']),
            'plates' => [
                $this->bodyPlates->plate($silhouette, BodySide::FRONT),
                $this->bodyPlates->plate($silhouette, BodySide::BACK),
            ],
            'endurance' => $this->enduranceLines($volume),
        ];
    }

    /**
     * Les lignes d'endurance à afficher, déjà formatées et déjà filtrées : une
     * activité absente de la séance n'a pas de ligne, et une ligne sans distance
     * ni durée n'existe pas.
     *
     * `derivedMeters` reste distinct de `meters` jusqu'ici : le total additionne
     * les deux, mais la part déduite est dite à part — une estimation tirée d'une
     * allure n'est pas une consigne, et l'écran doit pouvoir le signaler.
     *
     * @param WorkoutVolume $volume
     *
     * @return list<EnduranceLine>
     */
    private function enduranceLines(array $volume): array
    {
        $lines = [];

        // Une liste de paires et non un tableau indexé par enum : une clé de
        // tableau PHP ne peut pas être un enum.
        $activities = [
            [ActivityType::RUNNING, 'running'],
            [ActivityType::CYCLING, 'cycling'],
            [ActivityType::SWIMMING, 'swimming'],
        ];

        foreach ($activities as [$activity, $key]) {
            $data = $volume[$key];
            $total = $data['meters'] + $data['derivedMeters'];

            if (0 === $total && 0 === $data['seconds']) {
                continue;
            }

            $lines[] = [
                'activity' => $activity,
                'meters' => $data['meters'],
                'derivedMeters' => $data['derivedMeters'],
                // Sans distance ni allure, la case reste vide plutôt que d'afficher
                // un « ? » : ce n'est pas une valeur manquante, c'est une séance
                // décrite en durée seule.
                'distanceLabel' => $total > 0 ? $this->units->distance($total) : '',
                'durationLabel' => $data['seconds'] > 0 ? $this->units->duration($data['seconds']) : null,
            ];
        }

        return $lines;
    }
}
