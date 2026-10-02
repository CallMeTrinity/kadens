<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\ActivityType;
use App\Service\ActivityPalette;
use PHPUnit\Framework\TestCase;

/**
 * `ActivityPalette` : la couleur de chaque activité, par défaut ou choisie par
 * le lecteur.
 *
 * Ce que les tests tiennent :
 * - le plancher de lisibilité (texte blanc ≥ 4,5:1), d'où qu'arrive la couleur ;
 * - la forme stockée, qui ne garde que les écarts au défaut ;
 * - la parité avec `tokens.css`, où le défaut est recopié en primitives.
 */
final class ActivityPaletteTest extends TestCase
{
    private ActivityPalette $palette;

    protected function setUp(): void
    {
        $this->palette = new ActivityPalette();
    }

    public function testEveryActivityHasADefault(): void
    {
        foreach (ActivityType::cases() as $activity) {
            self::assertArrayHasKey($activity->value, ActivityPalette::DEFAULTS);
        }

        self::assertCount(\count(ActivityType::cases()), ActivityPalette::DEFAULTS);
    }

    /**
     * Les défauts et les pastilles proposées passent eux-mêmes le plancher
     * qu'on impose aux couleurs libres.
     */
    public function testDefaultsAndSwatchesAreReadableUnderWhiteText(): void
    {
        foreach ([...array_values(ActivityPalette::DEFAULTS), ...ActivityPalette::SWATCHES] as $color) {
            self::assertTrue($this->palette->isReadable($color), $color.' doit tenir 4,5:1 avec le blanc.');
        }
    }

    public function testAnonymousReaderSeesTheDefaults(): void
    {
        self::assertSame(ActivityPalette::DEFAULTS, $this->palette->forUser(null));
        self::assertNull($this->palette->cssOverrides(null));
    }

    public function testUserChoicesOverrideTheDefaults(): void
    {
        $user = (new User())->setActivityColors(['gym' => '#3B7A2C']);

        $palette = $this->palette->forUser($user);

        self::assertSame('#3b7a2c', $palette['gym']);
        self::assertSame(ActivityPalette::DEFAULTS['running'], $palette['running']);
    }

    /**
     * Une valeur stockée qui ne passerait plus (saisie hors formulaire, plancher
     * relevé) retombe sur le défaut plutôt que de s'afficher illisible.
     */
    public function testUnreadableOrMalformedStoredColorsFallBackToTheDefault(): void
    {
        $user = (new User())->setActivityColors([
            'gym' => '#ffff66',
            'running' => 'red',
            'unknown' => '#0b0b0b',
        ]);

        self::assertSame(ActivityPalette::DEFAULTS, $this->palette->forUser($user));
    }

    public function testReadabilityThreshold(): void
    {
        self::assertTrue($this->palette->isReadable('#0b0b0b'));
        self::assertFalse($this->palette->isReadable('#ffff66'));
        // Le rouge d'accent passe tout juste : ce n'est pas le contraste qui
        // l'écarte de la palette par défaut, c'est son sens (actions).
        self::assertTrue($this->palette->isReadable('#d8261e'));
        self::assertFalse($this->palette->isReadable('#ff8080'));
        self::assertFalse($this->palette->isReadable('nope'));
    }

    public function testOverridesKeepOnlyWhatDiffersFromTheDefault(): void
    {
        $full = ActivityPalette::DEFAULTS;
        $full['swimming'] = '#0B0B0B';

        self::assertSame(['swimming' => '#0b0b0b'], $this->palette->overridesOf($full));
        self::assertSame([], $this->palette->overridesOf(ActivityPalette::DEFAULTS));
    }

    public function testCssOverridesRewriteTheThreeTokensOfAnActivity(): void
    {
        $user = (new User())->setActivityColors(['cycling' => '#0b0b0b']);

        self::assertSame(
            ':root{--color-activity-bike:#0b0b0b;--color-activity-bike-tint:#e2e2e2;--color-activity-bike-text:#0b0b0b}',
            $this->palette->cssOverrides($user),
        );
    }

    public function testEmptyChoicesAreStoredAsNull(): void
    {
        $user = (new User())->setActivityColors([]);

        self::assertSame([], $user->getActivityColors());
        self::assertNull($this->palette->cssOverrides($user));
    }

    /**
     * Le défaut vit à deux endroits (PHP pour la surcharge, CSS pour le rendu).
     * S'ils divergent, un lecteur qui « revient au défaut » ne verrait pas la
     * couleur affichée aux autres.
     */
    public function testDefaultsMatchTheTokenPrimitives(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/tokens.css');

        foreach (ActivityType::cases() as $activity) {
            $key = $activity->cssKey();

            self::assertMatchesRegularExpression(
                \sprintf('/--kd-act-%s:\s*%s;/i', preg_quote($key, '/'), preg_quote(ActivityPalette::DEFAULTS[$activity->value], '/')),
                $css,
                \sprintf('--kd-act-%s doit valoir %s, comme ActivityPalette::DEFAULTS.', $key, ActivityPalette::DEFAULTS[$activity->value]),
            );

            self::assertMatchesRegularExpression(
                \sprintf('/--kd-act-%s-tint:\s*%s;/i', preg_quote($key, '/'), preg_quote($this->palette->tint(ActivityPalette::DEFAULTS[$activity->value]), '/')),
                $css,
                \sprintf('--kd-act-%s-tint doit être le voile calculé par ActivityPalette::tint().', $key),
            );
        }
    }
}
