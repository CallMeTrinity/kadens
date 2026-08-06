<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BlastImporter;
use PHPUnit\Framework\TestCase;

/**
 * Ce qui rend l'import rejouable tient à une seule propriété : l'identifiant
 * d'une séance ne dépend que de sa clé source. Elle n'a pas de branche à tester,
 * mais elle a une valeur à figer — la changer réimporterait tout en double, sans
 * rien casser de visible au moment du changement.
 */
final class BlastImporterTest extends TestCase
{
    public function testUuidOnlyDependsOnTheSourceKey(): void
    {
        self::assertTrue(
            BlastImporter::uuidFor('2026-01-02 11:37:07')->equals(BlastImporter::uuidFor('2026-01-02 11:37:07')),
        );

        self::assertFalse(
            BlastImporter::uuidFor('2026-01-02 11:37:07')->equals(BlastImporter::uuidFor('2026-01-02 11:37:08')),
        );
    }

    /**
     * La valeur est gelée volontairement. Si ce test casse, c'est que le
     * namespace ou la dérivation a bougé : tout historique déjà importé
     * s'écrirait une seconde fois au lieu d'être remplacé.
     */
    public function testUuidIsStableAcrossRuns(): void
    {
        self::assertSame(
            '1923d15a-7427-54ec-85b2-3956fb520d4b',
            BlastImporter::uuidFor('2026-01-02 11:37:07')->toRfc4122(),
        );
    }
}
