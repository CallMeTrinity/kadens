<?php

namespace App\Tests\Command;

use App\Command\ExportDesignTokensCommand;
use PHPUnit\Framework\TestCase;

/**
 * `app:tokens:export` (KL-20) : la projection de `tokens.css` que lit le mobile.
 *
 * Ce que ce fichier garde, c'est la **non-divergence**. Le JSON est un fichier
 * généré et versionné, comme `assets/styles/fonts.css` ou
 * `templates/components/_pwa_splash.html.twig` : rien n'empêche d'ajouter un token
 * au CSS et d'oublier de relancer la commande, et l'oubli ne se verrait que sur un
 * téléphone, en peignant une couleur manquante. Le test régénère donc depuis la
 * source et compare **le document entier** — un token ajouté, renommé ou dont la
 * valeur a bougé fait échouer la suite ici.
 *
 * Pas de `KernelTestCase` : la commande ne touche ni à la base ni au conteneur,
 * et son rendu est une fonction pure de la source.
 */
final class ExportDesignTokensCommandTest extends TestCase
{
    private static function projectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    private static function css(): string
    {
        return (string) file_get_contents(self::projectDir().'/'.ExportDesignTokensCommand::SOURCE);
    }

    /** @return array{primitives: array<string, string>, semantic: array<string, string>} */
    private static function exported(): array
    {
        return json_decode(ExportDesignTokensCommand::render(self::css()), true);
    }

    public function testTheVersionedJsonMatchesTheStylesheet(): void
    {
        $committed = file_get_contents(self::projectDir().'/'.ExportDesignTokensCommand::TARGET);

        self::assertNotFalse($committed, 'public/design-tokens.json est absent : lancer `php bin/console app:tokens:export`.');
        self::assertSame(
            ExportDesignTokensCommand::render(self::css()),
            $committed,
            'design-tokens.json a divergé de tokens.css : relancer `php bin/console app:tokens:export`.',
        );
    }

    /**
     * Le « fini quand » du ticket, pris au mot : tout token sémantique déclaré
     * dans le CSS est une clé du JSON.
     *
     * L'extraction est volontairement écrite ici, naïvement, plutôt qu'empruntée
     * à la commande — sinon le test validerait le parseur avec lui-même.
     */
    public function testEverySemanticTokenOfTheStylesheetIsExported(): void
    {
        $exported = self::exported();

        foreach (self::declaredTokens() as $name) {
            $bucket = str_starts_with($name, '--kd-') ? 'primitives' : 'semantic';

            self::assertArrayHasKey($name, $exported[$bucket], sprintf('%s manque à l\'export.', $name));
        }

        // Et rien d'inventé dans l'autre sens.
        self::assertSame(
            \count(self::declaredTokens()),
            \count($exported['primitives']) + \count($exported['semantic']),
        );
    }

    public function testReferencesAreResolvedDownToTheirPrimitiveValue(): void
    {
        $exported = self::exported();

        // --color-bg: var(--kd-page) — le mobile ne sait pas suivre une référence.
        self::assertSame($exported['primitives']['--kd-page'], $exported['semantic']['--color-bg']);

        foreach ([...$exported['primitives'], ...$exported['semantic']] as $name => $value) {
            self::assertStringNotContainsString('var(', $value, sprintf('%s cite encore un autre token.', $name));
        }
    }

    /** Une référence morte est une faute de frappe dans tokens.css, pas un token nul. */
    public function testAnUnknownReferenceFailsTheExport(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--color-oops référence --kd-nowhere');

        ExportDesignTokensCommand::render(':root { --color-oops: var(--kd-nowhere); }');
    }

    public function testACycleFailsTheExportInsteadOfLoopingForever(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cycle de références');

        ExportDesignTokensCommand::render(':root { --a: var(--b); --b: var(--a); }');
    }

    /**
     * Une propriété personnalisée posée sur un composant est une variable locale,
     * pas un token : elle n'a aucun sens hors de son sélecteur.
     */
    public function testOnlyRootDeclarationsAreExported(): void
    {
        $json = ExportDesignTokensCommand::render(
            ':root { --color-a: #000; } .kd-card { --color-local: 2px; }',
        );

        $exported = json_decode($json, true)['semantic'];

        self::assertArrayHasKey('--color-a', $exported);
        self::assertArrayNotHasKey('--color-local', $exported);
    }

    /** @return list<string> */
    private static function declaredTokens(): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', self::css());

        preg_match_all('/^\s*(--[a-zA-Z0-9_-]+)\s*:/m', $css, $matches);

        return array_values(array_unique($matches[1]));
    }
}
