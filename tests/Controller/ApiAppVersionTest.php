<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\MobileRelease;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * KL-43 — `GET /api/app-version`, le seul endpoint anonyme qui ne serve pas à
 * obtenir un jeton.
 *
 * Il n'est pas dans `ApiEndpointMatrixTest` et ne peut pas y être : la matrice
 * vérifie que **tout** endpoint refuse un appel anonyme, ce qui est exactement
 * l'inverse de ce qu'on demande à celui-ci. D'où ce fichier, qui tient les deux
 * gardes qui le concernent — répondre sans jeton, et ne poser aucun cookie.
 */
final class ApiAppVersionTest extends WebTestCase
{
    public function testItAnswersWithoutAToken(): void
    {
        $client = static::createClient();
        $release = static::getContainer()->get(MobileRelease::class);

        $client->request('GET', '/api/app-version');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $payload = json_decode($client->getResponse()->getContent() ?: '', true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame([
            'versionCode' => $release->versionCode(),
            'versionName' => $release->versionName(),
            'minimumVersionCode' => $release->minimumVersionCode(),
            'apkUrl' => $release->apkUrl(),
            'storeUrl' => $release->storeUrl(),
            'installUrl' => 'http://localhost/app',
        ], $payload);
    }

    /**
     * La contrepartie du précédent, et la raison pour laquelle le client mobile
     * appelle cet endpoint **sans** en-tête (`auth: false`) : l'authenticator se
     * déclenche sur la seule présence d'un `Bearer`, quelle que soit la route.
     * Un jeton périmé présenté ici échoue avant le contrôleur — précisément dans
     * la situation où la réponse compte le plus.
     */
    public function testAnUnknownTokenStillFailsBeforeTheController(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/app-version', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('f', 64),
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testItSetsNoCookie(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/app-version');

        self::assertSame([], $client->getResponse()->headers->getCookies());
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }

    public function testItRefusesAnyOtherMethod(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/app-version');

        self::assertResponseStatusCodeSame(405);
    }
}
