<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\PaceUnit;

/**
 * Formatage lisible des unités normalisées en base (kg, mètres, secondes).
 *
 * Source unique de conversion « valeur brute -> chaîne lisible ». Extrait de
 * PlanFlattener pour être réutilisé par les agrégats de volume (voir
 * PlanVolumeAggregator) sans dupliquer la logique mm:ss / km / allure.
 */
final class UnitFormatter
{
    public function weight(float $kg): string
    {
        // Affiche un entier sans décimales inutiles (60 kg plutôt que 60,00 kg).
        $formatted = rtrim(rtrim(number_format($kg, 2, ',', ' '), '0'), ',');

        return $formatted.' kg';
    }

    /**
     * Seuil de bascule du tonnage en tonnes. En dessous, le kilo reste lisible
     * (« 32 626 kg ») ; au-dessus, un cumul de six chiffres ne se lit plus d'un
     * coup d'œil (« 612 480 kg »), la tonne le ramène à trois.
     */
    public const TONNAGE_TONNES_FROM_KG = 100_000;

    /**
     * Un tonnage (cumul de charge × répétitions), arrondi au kilo, en tonnes
     * au-delà de 100 000 kg avec une décimale au plus (« 123,5 t »). À ne pas
     * confondre avec `weight()`, qui affiche une charge : 140 kg sur une barre
     * ne devient jamais « 0,1 t ».
     */
    public function tonnage(float $kg): string
    {
        $kg = round($kg);

        if ($kg < self::TONNAGE_TONNES_FROM_KG) {
            return $this->weight($kg);
        }

        return rtrim(rtrim(number_format($kg / 1000, 1, ',', ' '), '0'), ',').' t';
    }

    public function distance(?int $meters): string
    {
        if (null === $meters) {
            return '?';
        }

        if ($meters >= 1000) {
            $km = rtrim(rtrim(number_format($meters / 1000, 2, ',', ' '), '0'), ',');

            return $km.' km';
        }

        return $meters.' m';
    }

    /**
     * Secondes -> mm:ss (ou h:mm:ss au-delà d'une heure).
     */
    public function duration(?int $seconds): string
    {
        if (null === $seconds) {
            return '?';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }

    /**
     * Secondes par km -> allure lisible dans l'unité demandée (min/km, km/h ou
     * min/100m). La conversion vit sur l'enum PaceUnit (source unique).
     */
    public function pace(int $secondsPerKm, PaceUnit $unit = PaceUnit::MIN_PER_KM): string
    {
        return $unit->format($secondsPerKm);
    }
}
