<?php

declare(strict_types=1);

namespace App\Enum;

enum PrescriptionType: string
{
    case SETS_REPS = 'sets_reps';
    case SETS_TIME = 'sets_time';
    case AMRAP = 'amrap';
    case FOR_TIME = 'for_time';
    case DISTANCE_PACE = 'distance_pace';
    case DURATION = 'duration';

    public function getLabel(): string
    {
        return match ($this) {
            self::SETS_REPS => 'Séries × répétitions',
            self::SETS_TIME => 'Séries × durée',
            self::AMRAP => 'AMRAP',
            self::FOR_TIME => 'For time',
            self::DISTANCE_PACE => 'Distance × allure',
            self::DURATION => 'Durée',
        };
    }

    /**
     * Sous-ensemble de champs de valeurs pertinent pour ce type de prescription.
     *
     * Source unique consommée par : le form (affichage dynamique des champs),
     * le nettoyage serveur (on annule tout champ hors sous-ensemble) et le rendu
     * via PlanFlattener. `restSeconds` et `notes` sont transverses, donc absents
     * d'ici.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return match ($this) {
            self::SETS_REPS => ['sets', 'reps', 'weightKg'],
            self::SETS_TIME => ['sets', 'durationSeconds', 'weightKg'],
            self::AMRAP => ['durationSeconds', 'targetReps'],
            self::FOR_TIME => ['targetReps', 'capSeconds'],
            self::DISTANCE_PACE => ['distanceMeters', 'paceSecondsPerKm'],
            self::DURATION => ['durationSeconds', 'intensityZone'],
        };
    }

    /**
     * Carte `type => champs pertinents`, pour l'affichage dynamique côté client
     * (contrôleur Stimulus) sans dupliquer la logique de `fields()`.
     *
     * @return array<string, list<string>>
     */
    public static function fieldsMap(): array
    {
        $map = [];
        foreach (self::cases() as $case) {
            $map[$case->value] = $case->fields();
        }

        return $map;
    }
}
