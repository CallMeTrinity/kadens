<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Garde-fou d'installabilité : sans manifest, sans icône ou sans écran de
 * démarrage, l'app cesse d'être installable — sans qu'aucun test métier ne
 * le voie passer.
 */
class PwaHeadTest extends WebTestCase
{
    public function testHeadCarriesPwaMetadata(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        $html = $client->getResponse()->getContent();

        foreach ([
            '<link rel="manifest" href="/manifest.json">',
            '<meta name="theme-color" content="#0b0b0b">',
            '<link rel="apple-touch-icon" href="/pwa/apple-touch-icon.png" sizes="180x180">',
            '<meta name="apple-mobile-web-app-title" content="Kadens">',
            // Condition pour que env(safe-area-inset-bottom) soit non nul, donc
            // pour que la nav basse ne passe pas sous la barre gestuelle iOS.
            'viewport-fit=cover',
        ] as $needle) {
            self::assertStringContainsString($needle, $html, "Métadonnée PWA manquante : {$needle}");
        }

        // Le service worker ne s'enregistre qu'en prod (cf. base.html.twig).
        self::assertStringNotContainsString("register('/sw.js')", $html);
    }

    /**
     * Une media query iOS doit correspondre exactement : un <link> sans fichier
     * (ou un fichier sans <link>) donne un écran de démarrage blanc. Le fragment
     * et les PNG sont générés ensemble par tools/build-pwa-icons.php — ce test
     * vérifie qu'ils n'ont pas divergé depuis.
     */
    public function testEveryStartupImageIsBackedByAFile(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        $html = $client->getResponse()->getContent();

        preg_match_all('~rel="apple-touch-startup-image" href="(/pwa/splash/[^"]+)"~', $html, $matches);
        self::assertNotEmpty($matches[1], 'Aucun écran de démarrage iOS déclaré.');

        $public = self::getContainer()->getParameter('kernel.project_dir') . '/public';
        foreach ($matches[1] as $href) {
            self::assertFileExists($public . $href);
        }

        $onDisk = array_map(
            static fn (string $path): string => '/pwa/splash/' . basename($path),
            glob($public . '/pwa/splash/*.png')
        );
        self::assertSame([], array_values(array_diff($onDisk, $matches[1])), 'Écran de démarrage sans <link> correspondant.');
    }

    public function testManifestIconsExist(): void
    {
        $public = \dirname(__DIR__, 2) . '/public';
        $manifest = json_decode(file_get_contents($public . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('standalone', $manifest['display']);
        self::assertNotEmpty($manifest['icons']);

        foreach ($manifest['icons'] as $icon) {
            self::assertFileExists($public . $icon['src']);
            // Apache masque /icons/* derrière son Alias d'autoindex : les visuels
            // doivent rester sous /pwa/ pour être atteignables en prod.
            self::assertStringStartsWith('/pwa/', $icon['src']);
        }

        $purposes = array_column($manifest['icons'], 'purpose');
        self::assertContains('any', $purposes);
        self::assertContains('maskable', $purposes);
    }
}
