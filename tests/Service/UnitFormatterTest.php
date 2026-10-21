<?php

namespace App\Tests\Service;

use App\Service\UnitFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Le tonnage bascule en tonnes au-delà de 100 000 kg, la charge jamais.
 */
final class UnitFormatterTest extends TestCase
{
    public function testATonnageStaysInKilosBelowTheThreshold(): void
    {
        $units = new UnitFormatter();

        self::assertSame('0 kg', $units->tonnage(0));
        self::assertSame('32 627 kg', $units->tonnage(32626.5), 'Un tonnage s\'arrondit au kilo.');
        self::assertSame('99 999 kg', $units->tonnage(99999.4));
    }

    public function testATonnageSwitchesToTonnesFromOneHundredThousandKilos(): void
    {
        $units = new UnitFormatter();

        self::assertSame('100 t', $units->tonnage(99999.5), 'Arrondi au kilo, il atteint le seuil.');
        self::assertSame('100 t', $units->tonnage(100000));
        self::assertSame('123,5 t', $units->tonnage(123456));
        self::assertSame('612 t', $units->tonnage(612010));
        self::assertSame('1 248,3 t', $units->tonnage(1248300));
    }

    public function testALoadNeverSwitchesToTonnes(): void
    {
        self::assertSame('140 kg', (new UnitFormatter())->weight(140));
        self::assertSame('150 000 kg', (new UnitFormatter())->weight(150000));
    }
}
