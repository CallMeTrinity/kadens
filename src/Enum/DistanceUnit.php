<?php

declare(strict_types=1);

namespace App\Enum;

use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Unité de distance exposée à la saisie, selon l'activité.
 *
 * La base ne stocke que des mètres (`distanceMeters`, cf. la règle « unités
 * normalisées »). Course et vélo se pensent en km, natation (et le reste) en
 * mètres : cet enum porte la conversion aller/retour pour que chaque sport se
 * saisisse dans son unité naturelle, sans jamais exposer la conversion à
 * l'utilisateur. L'AFFICHAGE reste géré par UnitFormatter::distance (m sous
 * 1 km, km au-delà), déjà lisible pour tous.
 */
enum DistanceUnit: string
{
    case KM = 'km';
    case METERS = 'm';

    /**
     * Unité naturelle d'une activité. Course et vélo = km ; natation et défaut =
     * mètres.
     */
    public static function forActivity(?ActivityType $activity): self
    {
        return match ($activity) {
            ActivityType::RUNNING, ActivityType::CYCLING => self::KM,
            default => self::METERS,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::KM => 'km',
            self::METERS => 'm',
        };
    }

    public function placeholder(): string
    {
        return match ($this) {
            self::KM => '5',
            self::METERS => '2000',
        };
    }

    /**
     * Saisie utilisateur -> mètres (unité normalisée en base). Renvoie null pour
     * une saisie vide, lève TransformationFailedException si invalide. La virgule
     * est acceptée comme séparateur décimal (« 5,5 » -> 5500 m).
     */
    public function toMeters(string $text): ?int
    {
        $text = trim($text);
        if ('' === $text) {
            return null;
        }

        $normalized = str_replace(',', '.', $text);
        if (!is_numeric($normalized) || (float) $normalized < 0) {
            throw new TransformationFailedException('Distance invalide.');
        }

        return match ($this) {
            self::KM => (int) round((float) $normalized * 1000),
            self::METERS => (int) round((float) $normalized),
        };
    }

    /**
     * Mètres -> valeur de champ (sans suffixe d'unité), pour préremplir la saisie.
     */
    public function toInputValue(int $meters): string
    {
        return match ($this) {
            // Jusqu'à 3 décimales (précision au mètre), zéros inutiles retirés :
            // 5000 -> « 5 », 5500 -> « 5,5 », 400 -> « 0,4 ».
            self::KM => rtrim(rtrim(number_format($meters / 1000, 3, ',', ''), '0'), ','),
            self::METERS => (string) $meters,
        };
    }
}
