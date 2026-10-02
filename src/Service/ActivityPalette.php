<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\ActivityType;

/**
 * La palette des activités : une couleur par `ActivityType`, posée en aplat sous
 * du texte blanc (calendrier, hero de séance, badge…).
 *
 * Deux sources, une seule autorité :
 * - `DEFAULTS` est la palette « Vif » de la maquette (issue #35). Elle est
 *   recopiée en primitives `--kd-act-*` dans `assets/styles/tokens.css`, et les
 *   deux doivent bouger ensemble (test croisé dans `ActivityPaletteTest`) ;
 * - `User.activityColors` ne garde que les écarts à ce défaut. Ils partent dans
 *   la page en `<style>:root{…}</style>` (`cssOverrides()`), qui surcharge les
 *   tokens sémantiques `--color-activity-*` : les composants n'en savent rien.
 *
 * **Le plancher de lisibilité est la règle, pas une recommandation.** Chaque
 * couleur porte du texte blanc de 10 à 12 px : `isReadable()` exige le contraste
 * WCAG AA (4,5:1). Le formulaire refuse en dessous, et `forUser()` ignore une
 * valeur stockée qui ne passerait plus — une couleur illisible ne s'affiche
 * jamais, d'où qu'elle vienne.
 */
final class ActivityPalette
{
    /** Contraste minimal avec le blanc (WCAG AA, texte courant). */
    public const MIN_CONTRAST = 4.5;

    /** Part de la couleur dans son voile clair (`-tint`), le reste en blanc. */
    private const TINT_RATIO = 0.12;

    /**
     * @var array<string, string> `ActivityType::value` → hex
     */
    public const DEFAULTS = [
        'gym' => '#bd1f44',
        'running' => '#aa5300',
        'swimming' => '#006bbb',
        'cycling' => '#7945ab',
        'mobility' => '#007979',
        'other' => '#5c5c56',
    ];

    /**
     * Les pastilles proposées dans les réglages : les six défauts, plus un vert
     * et l'encre. Le sélecteur libre reste disponible à côté.
     *
     * @var list<string>
     */
    public const SWATCHES = [
        '#bd1f44', '#aa5300', '#006bbb', '#7945ab',
        '#007979', '#5c5c56', '#3b7a2c', '#0b0b0b',
    ];

    /**
     * La palette complète vue par ce lecteur : le défaut, surchargé par ses
     * choix. Un anonyme (page publique) voit le défaut.
     *
     * @return array<string, string> `ActivityType::value` → hex, dans l'ordre de l'enum
     */
    public function forUser(?User $user): array
    {
        return $this->complete($user?->getActivityColors() ?? []);
    }

    /**
     * Une palette complète à partir de surcharges, sans retenir celles qui ne
     * passeraient pas (activité inconnue, hex invalide, texte blanc illisible).
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, string>
     */
    public function complete(array $overrides): array
    {
        $palette = self::DEFAULTS;

        foreach ($overrides as $activity => $color) {
            if (isset($palette[$activity]) && self::isValidHex($color) && $this->isReadable($color)) {
                $palette[$activity] = strtolower($color);
            }
        }

        return $palette;
    }

    /**
     * Ce qui s'écarte du défaut dans une palette complète. C'est la forme
     * stockée : revenir au défaut retire la clé.
     *
     * @param array<string, string> $palette
     *
     * @return array<string, string>
     */
    public function overridesOf(array $palette): array
    {
        $overrides = [];

        foreach (self::DEFAULTS as $activity => $default) {
            $color = strtolower((string) ($palette[$activity] ?? $default));

            if ($color !== $default) {
                $overrides[$activity] = $color;
            }
        }

        return $overrides;
    }

    /**
     * Les déclarations à poser sur `:root` pour ce lecteur, ou `null` s'il suit
     * le défaut (rien à injecter). Chaque activité surchargée réécrit ses trois
     * tokens : l'aplat, son voile clair et sa variante texte.
     */
    public function cssOverrides(?User $user): ?string
    {
        $declarations = [];

        foreach ($this->overridesOf($this->forUser($user)) as $activity => $color) {
            $key = ActivityType::from($activity)->cssKey();

            $declarations[] = \sprintf('--color-activity-%s:%s', $key, $color);
            $declarations[] = \sprintf('--color-activity-%s-tint:%s', $key, $this->tint($color));
            $declarations[] = \sprintf('--color-activity-%s-text:%s', $key, $color);
        }

        return [] === $declarations ? null : ':root{'.implode(';', $declarations).'}';
    }

    /**
     * Le texte blanc reste-t-il lisible sur cette couleur ?
     */
    public function isReadable(string $hex): bool
    {
        return self::isValidHex($hex) && $this->contrastWithWhite($hex) >= self::MIN_CONTRAST;
    }

    /**
     * Rapport de contraste WCAG 2 entre la couleur et le blanc (1 à 21).
     */
    public function contrastWithWhite(string $hex): float
    {
        return 1.05 / ($this->relativeLuminance($hex) + 0.05);
    }

    /**
     * Le voile clair d'une couleur, mélangée au blanc : fond d'icône, de puce.
     */
    public function tint(string $hex): string
    {
        return \sprintf('#%02x%02x%02x', ...array_map(
            static fn (int $channel): int => (int) round($channel * self::TINT_RATIO + 255 * (1 - self::TINT_RATIO)),
            self::channels($hex),
        ));
    }

    public static function isValidHex(mixed $value): bool
    {
        return \is_string($value) && 1 === preg_match('/^#[0-9a-fA-F]{6}$/', $value);
    }

    private function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = array_map(static function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::channels($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * @return array{int, int, int}
     */
    private static function channels(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }
}
