<?php

declare(strict_types=1);
namespace App\Enum;

enum ActivityType: string
{
    case GYM = 'gym';
    case RUNNING = 'running';
    case SWIMMING = 'swimming';
    case CYCLING = 'cycling';
    case MOBILITY = 'mobility';
    case OTHER = 'other';

    public function getLabel(): string
    {
        return match($this) {
            self::GYM => 'Salle de sport',
            self::RUNNING => 'Course à pied',
            self::SWIMMING => 'Natation',
            self::CYCLING => 'Cyclisme',
            self::MOBILITY => 'Mobilité',
            self::OTHER => 'Autre',
        };
    }

    /**
     * Range des activités de la plus portée à la moins portée, à partir de leur
     * nombre d'exercices. La première est la **dominante** : c'est elle qui
     * colore une séance (aplat), la deuxième fait la bande.
     *
     * Départage : à égalité, l'ordre de déclaration de l'enum tranche (la salle
     * d'abord). Arbitraire mais stable, et écrit une seule fois : l'historique
     * (`TrainingHistory`) et l'affichage d'une séance (`WorkoutMetrics`) ne
     * doivent pas ranger la même séance à deux endroits.
     *
     * @param array<string, int> $exerciseCounts `ActivityType::value` → nombre d'exercices
     *
     * @return list<self>
     */
    public static function rankByCount(array $exerciseCounts): array
    {
        $ranked = array_values(array_filter(
            self::cases(),
            static fn (self $case): bool => ($exerciseCounts[$case->value] ?? 0) > 0,
        ));

        // usort est stable depuis PHP 8 : à compte égal, l'ordre de l'enum reste.
        usort($ranked, static fn (self $a, self $b): int => $exerciseCounts[$b->value] <=> $exerciseCounts[$a->value]);

        return $ranked;
    }

    /**
     * Le suffixe CSS de l'activité : `--color-activity-{clé}` côté tokens,
     * `kd-act--{clé}` côté composants. Historique (run/swim/bike, pas la valeur
     * de l'enum), et à ne pas renommer : le mobile reçoit ces noms par
     * `design-tokens.json`. Le macro Twig `activity.modifier()` le lit ici.
     */
    public function cssKey(): string
    {
        return match($this) {
            self::GYM => 'gym',
            self::RUNNING => 'run',
            self::SWIMMING => 'swim',
            self::CYCLING => 'bike',
            self::MOBILITY => 'mobility',
            self::OTHER => 'other',
        };
    }
}
