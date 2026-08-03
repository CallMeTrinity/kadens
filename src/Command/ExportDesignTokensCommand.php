<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Publie `assets/styles/tokens.css` en JSON, pour le mobile (KL-20).
 *
 * Les tokens sont la source de vérité de l'identité « Presse », et React Native
 * ne sait pas lire du CSS. Les recopier à la main dans `kadens-mobile`, ce serait
 * accepter qu'ils divergent le jour où une valeur bouge ici — donc on les
 * **projette** : `tokens.css` reste la seule source, `design-tokens.json` en est
 * la sortie machine, et `npm run sync:tokens` (KL-22) la consomme.
 *
 * Deux choix qui tiennent le reste :
 *
 * - **Les `var()` sont résolues, rien d'autre ne l'est.** Un consommateur natif
 *   n'a aucun moyen de suivre une référence : `--color-bg` doit valoir `#dcdcd7`,
 *   pas `var(--kd-page)`. En revanche la commande ne *traduit* pas — une pile de
 *   polices reste une pile de polices, un `color-mix()` reste un `color-mix()`.
 *   La fidélité à la source est ce qui rend l'export vérifiable ; l'adaptation
 *   aux API natives vit dans le générateur TypeScript du repo mobile.
 * - **Une `var()` qui ne se résout pas fait échouer la commande.** Un token qui
 *   pointe un nom inexistant est une faute de frappe dans `tokens.css` : elle se
 *   voit ici, au build, plutôt que sur un téléphone où la couleur manquerait sans
 *   rien dire.
 *
 * Le fichier produit est **déterministe** (aucun horodatage) et versionné : c'est
 * ce qui permet à `ExportDesignTokensCommandTest` d'échouer quand il a divergé de
 * `tokens.css`, comme `PwaHeadTest` le fait pour `_pwa_splash.html.twig`.
 */
#[AsCommand(
    name: 'app:tokens:export',
    description: 'Exporte les tokens de design en JSON pour le client mobile.',
)]
final class ExportDesignTokensCommand extends Command
{
    public const string SOURCE = 'assets/styles/tokens.css';
    public const string TARGET = 'public/design-tokens.json';

    /** Préfixe des primitives ; tout le reste est un token sémantique. */
    private const string PRIMITIVE_PREFIX = '--kd-';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'output',
            null,
            InputOption::VALUE_REQUIRED,
            'Chemin du JSON produit (relatif à la racine du projet)',
            self::TARGET,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $source = $this->projectDir.'/'.self::SOURCE;

        if (!is_readable($source)) {
            $io->error(sprintf('Source introuvable : %s', self::SOURCE));

            return Command::FAILURE;
        }

        try {
            $json = self::render((string) file_get_contents($source));
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $target = $this->projectDir.'/'.$input->getOption('output');
        $dir = \dirname($target);

        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            $io->error(sprintf('Impossible de créer %s', $dir));

            return Command::FAILURE;
        }

        file_put_contents($target, $json);

        $decoded = json_decode($json, true);
        $io->success(sprintf(
            '%d primitives et %d tokens sémantiques écrits dans %s.',
            \count($decoded['primitives']),
            \count($decoded['semantic']),
            $input->getOption('output'),
        ));

        return Command::SUCCESS;
    }

    /**
     * Rend le document JSON complet (avec saut de ligne final) à partir du CSS.
     *
     * Publique et statique parce que le test en a besoin sans écrire de fichier :
     * comparer le JSON versionné à ce que la source produit *aujourd'hui* est la
     * seule façon de détecter un token ajouté sans régénération.
     */
    public static function render(string $css): string
    {
        $raw = self::declarations($css);

        $primitives = [];
        $semantic = [];

        foreach ($raw as $name => $value) {
            $resolved = self::resolve($name, $raw);

            if (str_starts_with($name, self::PRIMITIVE_PREFIX)) {
                $primitives[$name] = $resolved;
            } else {
                $semantic[$name] = $resolved;
            }
        }

        return json_encode(
            [
                'generator' => 'app:tokens:export',
                'source' => self::SOURCE,
                'primitives' => $primitives,
                'semantic' => $semantic,
            ],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        )."\n";
    }

    /**
     * Les déclarations de propriétés personnalisées, dans l'ordre du fichier.
     *
     * On ne lit que les blocs `:root` : une propriété personnalisée posée sur un
     * sélecteur de composant est une variable **locale** à ce composant, pas un
     * token — l'exporter donnerait au mobile une valeur qui n'a de sens nulle part
     * ailleurs. C'est aussi ce qui laisse `--kd-navbar-h`, qui n'existe que sous
     * 560px (design-system §5), hors du champ.
     *
     * @return array<string, string>
     */
    private static function declarations(string $css): array
    {
        // Les commentaires citent des noms de tokens (« --kd-cat-*, --kd-chart-* »).
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        preg_match_all('/:root\s*\{([^}]*)\}/', $css, $blocks);

        $declarations = [];

        foreach ($blocks[1] as $block) {
            preg_match_all('/(--[a-zA-Z0-9_-]+)\s*:\s*([^;]+);/', $block, $matches, \PREG_SET_ORDER);

            foreach ($matches as $match) {
                $declarations[$match[1]] = trim(preg_replace('/\s+/', ' ', $match[2]));
            }
        }

        if ([] === $declarations) {
            throw new \RuntimeException('Aucune propriété personnalisée trouvée dans le bloc :root.');
        }

        return $declarations;
    }

    /**
     * Remplace les `var()` d'un token par la valeur des tokens qu'il cite.
     *
     * @param array<string, string> $raw
     * @param list<string>          $stack chaîne de résolution en cours, pour le cycle
     */
    private static function resolve(string $name, array $raw, array $stack = []): string
    {
        if (\in_array($name, $stack, true)) {
            throw new \RuntimeException(sprintf(
                'Cycle de références : %s.',
                implode(' → ', [...$stack, $name]),
            ));
        }

        $stack[] = $name;

        return (string) preg_replace_callback(
            '/var\(\s*(--[a-zA-Z0-9_-]+)\s*(?:,\s*([^()]*?)\s*)?\)/',
            static function (array $m) use ($raw, $stack, $name): string {
                if (isset($raw[$m[1]])) {
                    return self::resolve($m[1], $raw, $stack);
                }

                // Un repli explicite reste valide : c'est ce que le navigateur
                // utiliserait. Sans repli, la référence est une faute de frappe.
                if (isset($m[2]) && '' !== $m[2]) {
                    return $m[2];
                }

                throw new \RuntimeException(sprintf(
                    '%s référence %s, qui n\'est déclaré nulle part.',
                    $name,
                    $m[1],
                ));
            },
            $raw[$name],
        );
    }
}
