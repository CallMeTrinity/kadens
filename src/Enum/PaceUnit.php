<?php

declare(strict_types=1);

namespace App\Enum;

use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Unité d'allure exposée à la saisie et à l'affichage, selon l'activité.
 *
 * La base ne stocke qu'une seule mesure normalisée de vitesse : les secondes par
 * kilomètre (`paceSecondsPerKm`, cf. la règle « unités normalisées »). km/h et
 * min/100m sont de simples représentations de cette même vitesse. Cet enum porte
 * la conversion aller/retour, pour que chaque sport se saisisse dans son unité
 * naturelle (course en min/km, vélo en km/h, natation en min/100m) sans jamais
 * exposer les secondes/km à l'utilisateur.
 */
enum PaceUnit: string
{
    case MIN_PER_KM = 'min_per_km';
    case KM_PER_H = 'km_per_h';
    case MIN_PER_100M = 'min_per_100m';

    /**
     * Unité naturelle d'une activité. Endurance à pied (course) et défaut =
     * min/km ; vélo = km/h ; natation = min/100m.
     */
    public static function forActivity(?ActivityType $activity): self
    {
        return match ($activity) {
            ActivityType::CYCLING => self::KM_PER_H,
            ActivityType::SWIMMING => self::MIN_PER_100M,
            default => self::MIN_PER_KM,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MIN_PER_KM => 'min/km',
            self::KM_PER_H => 'km/h',
            self::MIN_PER_100M => 'min/100m',
        };
    }

    public function placeholder(): string
    {
        return match ($this) {
            self::MIN_PER_KM => '5:30',
            self::KM_PER_H => '30',
            self::MIN_PER_100M => '1:45',
        };
    }

    /**
     * Saisie utilisateur -> secondes par km (unité normalisée en base). Renvoie
     * null pour une saisie vide, lève TransformationFailedException si invalide.
     */
    public function toSecondsPerKm(string $text): ?int
    {
        $text = trim($text);
        if ('' === $text) {
            return null;
        }

        return match ($this) {
            self::MIN_PER_KM => $this->minPerDistanceToSeconds($text, 1000),
            self::MIN_PER_100M => $this->minPerDistanceToSeconds($text, 100),
            self::KM_PER_H => $this->kmhToSeconds($text),
        };
    }

    /**
     * Secondes par km -> valeur de champ (sans suffixe d'unité), pour préremplir
     * la saisie.
     */
    public function toInputValue(int $secondsPerKm): string
    {
        return match ($this) {
            self::MIN_PER_KM => $this->secondsToMinPer($secondsPerKm, 1000),
            self::MIN_PER_100M => $this->secondsToMinPer($secondsPerKm, 100),
            self::KM_PER_H => $this->secondsToKmh($secondsPerKm),
        };
    }

    /**
     * Secondes par km -> chaîne lisible avec suffixe d'unité (affichage).
     */
    public function format(int $secondsPerKm): string
    {
        return match ($this) {
            self::MIN_PER_KM => $this->secondsToMinPer($secondsPerKm, 1000).'/km',
            self::MIN_PER_100M => $this->secondsToMinPer($secondsPerKm, 100).'/100m',
            self::KM_PER_H => $this->secondsToKmh($secondsPerKm).' km/h',
        };
    }

    /**
     * « m:ss » (temps sur `distanceMeters`) -> secondes par km. Un nombre simple
     * est interprété en minutes (« 5 » -> 5:00, « 5,5 » -> 5:30).
     */
    private function minPerDistanceToSeconds(string $text, int $distanceMeters): int
    {
        if (preg_match('/^(\d+):([0-5]?\d)$/', $text, $matches)) {
            $seconds = (int) $matches[1] * 60 + (int) $matches[2];
        } else {
            $normalized = str_replace(',', '.', $text);
            if (!is_numeric($normalized)) {
                throw new TransformationFailedException('Allure invalide.');
            }
            $seconds = (int) round((float) $normalized * 60);
        }

        // Temps sur distanceMeters -> temps sur 1000 m.
        return (int) round($seconds * 1000 / $distanceMeters);
    }

    private function secondsToMinPer(int $secondsPerKm, int $distanceMeters): string
    {
        $seconds = (int) round($secondsPerKm * $distanceMeters / 1000);

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    private function kmhToSeconds(string $text): int
    {
        $normalized = str_replace(',', '.', $text);
        if (!is_numeric($normalized) || (float) $normalized <= 0) {
            throw new TransformationFailedException('Vitesse invalide.');
        }

        return (int) round(3600 / (float) $normalized);
    }

    private function secondsToKmh(int $secondsPerKm): string
    {
        if ($secondsPerKm <= 0) {
            return '0';
        }

        $kmh = 3600 / $secondsPerKm;

        // Un rendu propre : entier sans décimale inutile (30 plutôt que 30,00).
        return rtrim(rtrim(number_format($kmh, 1, ',', ' '), '0'), ',');
    }
}
