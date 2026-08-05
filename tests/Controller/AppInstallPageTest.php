<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\MobileRelease;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * KL-43 — la page `/app`, celle qu'on ouvre depuis un téléphone qui n'a pas
 * encore l'app.
 *
 * Ce qu'elle affiche est **dérivé du service**, jamais réaffirmé ici : les
 * numéros de version bougent à chaque publication, et un test qui les figerait
 * casserait à la première release. Ce qui se teste, ce sont les propriétés qui
 * ne doivent jamais changer — s'ouvrir sans session, porter l'adresse du dépôt
 * en clair et en QR, et rendre le tout sans une requête de plus.
 */
final class AppInstallPageTest extends WebTestCase
{
    public function testItOpensWithoutASession(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        // Pas de header applicatif hors connexion (base.html.twig) : la marque
        // en tête est ce qui rattache la page au site.
        self::assertSelectorExists('.kd-install__brand');
        self::assertSelectorTextContains('h1', 'app Kadens');
        self::assertCount(0, $crawler->filter('.kd-nav'));
    }

    public function testTheStoreAddressIsGivenTwice(): void
    {
        $client = static::createClient();
        $release = static::getContainer()->get(MobileRelease::class);

        $crawler = $client->request('GET', '/app');

        // En clair, pour la recopier.
        self::assertSelectorTextSame('.kd-install__url', $release->storeUrl());
        self::assertSelectorTextSame('.kd-install__fingerprint', $release->storeFingerprint());

        // Et en QR, dessiné côté serveur : le SVG est dans la réponse, il n'y a
        // aucune bibliothèque JavaScript à charger pour le voir apparaître.
        self::assertCount(1, $crawler->filter('.kd-install__qr svg'));
    }

    /**
     * Tant que rien n'est publié, la page ne propose aucun téléchargement — et
     * dès qu'une version l'est, elle en propose un. Les deux moitiés sont
     * vérifiées ensemble parce que c'est la même règle, lue depuis le service.
     */
    public function testTheDownloadOnlyExistsOnceAVersionIsPublished(): void
    {
        $client = static::createClient();
        $release = static::getContainer()->get(MobileRelease::class);

        $crawler = $client->request('GET', '/app');
        $links = $crawler->filter('.kd-install__acts a');

        if (!$release->isPublished()) {
            self::assertCount(0, $links);

            return;
        }

        self::assertSame($release->apkUrl(), $links->eq(0)->attr('href'));
        self::assertSelectorTextContains('.kd-install__version', $release->versionName());
    }

    /**
     * Une page publique n'ouvre pas de session. Ce n'est pas cosmétique : `/app`
     * est la seule page anonyme qu'on ouvrira depuis un QR ou un lien partagé, et
     * un cookie posé là ferait porter au mutualisé une session par visiteur.
     */
    public function testItSetsNoCookie(): void
    {
        $client = static::createClient();
        $client->request('GET', '/app');

        self::assertSame([], $client->getResponse()->headers->getCookies());
    }
}
