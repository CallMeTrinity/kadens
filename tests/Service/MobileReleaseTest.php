<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MobileRelease;
use PHPUnit\Framework\TestCase;

/**
 * KL-43 — les deux liens dérivés, et l'état « rien de publié ».
 *
 * Ce test construit ses instances au lieu de lire les paramètres de
 * `services.yaml` : les numéros y bougent à chaque publication, et un test qui
 * les affirmerait deviendrait faux le jour où le projet fait ce pour quoi il est
 * écrit. Ce qui se teste ici, c'est la **règle** — comment un lien se compose,
 * et ce que zéro veut dire.
 */
final class MobileReleaseTest extends TestCase
{
    public function testTheDirectApkLinkFollowsTheWorkflowNaming(): void
    {
        $release = self::release(versionCode: 42, versionName: '1.2.0');

        // Le nom du fichier est celui que compose `.github/workflows/build.yml`
        // (`kadens-<versionName>-<versionCode>.apk`), et le tag est `v<name>`.
        self::assertSame(
            'https://github.com/CallMeTrinity/kadens-mobile/releases/download/v1.2.0/kadens-1.2.0-42.apk',
            $release->apkUrl(),
        );
        self::assertSame(
            'https://github.com/CallMeTrinity/kadens-mobile/releases/tag/v1.2.0',
            $release->releaseUrl(),
        );
    }

    /**
     * Zéro n'est pas « version 0 » : c'est l'absence de version. Les deux liens
     * sont nuls plutôt que pointés sur une release inexistante — un 404 dirait
     * « c'est cassé » là où la vérité est « ce n'est pas encore sorti ».
     */
    public function testNothingPublishedYieldsNoLinkAtAll(): void
    {
        $release = self::release(versionCode: 0, versionName: '0.0.0');

        self::assertFalse($release->isPublished());
        self::assertNull($release->apkUrl());
        self::assertNull($release->releaseUrl());
    }

    public function testTheStoreUrlAndFingerprintTravelUntouched(): void
    {
        // Elles sont recopiées à la main dans un store et comparées du regard :
        // tout ce que le service a le droit de leur faire, c'est rien.
        $release = self::release(versionCode: 1, versionName: '1.0.0');

        self::assertSame('https://store.antoninpamart.fr', $release->storeUrl());
        self::assertSame(str_repeat('ab', 32), $release->storeFingerprint());
    }

    private static function release(int $versionCode, string $versionName): MobileRelease
    {
        return new MobileRelease(
            versionCode: $versionCode,
            versionName: $versionName,
            minimumVersionCode: 0,
            repository: 'CallMeTrinity/kadens-mobile',
            storeUrl: 'https://store.antoninpamart.fr',
            storeFingerprint: str_repeat('ab', 32),
        );
    }
}
