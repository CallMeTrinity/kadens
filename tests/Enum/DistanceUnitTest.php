<?php

namespace App\Tests\Enum;

use App\Enum\ActivityType;
use App\Enum\DistanceUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Exception\TransformationFailedException;

final class DistanceUnitTest extends TestCase
{
    public function testForActivityMapsRunningAndCyclingToKm(): void
    {
        self::assertSame(DistanceUnit::KM, DistanceUnit::forActivity(ActivityType::RUNNING));
        self::assertSame(DistanceUnit::KM, DistanceUnit::forActivity(ActivityType::CYCLING));
        self::assertSame(DistanceUnit::METERS, DistanceUnit::forActivity(ActivityType::SWIMMING));
        self::assertSame(DistanceUnit::METERS, DistanceUnit::forActivity(ActivityType::GYM));
        self::assertSame(DistanceUnit::METERS, DistanceUnit::forActivity(null));
    }

    #[DataProvider('kmCases')]
    public function testKmRoundTrip(string $input, int $meters, string $display): void
    {
        self::assertSame($meters, DistanceUnit::KM->toMeters($input));
        self::assertSame($display, DistanceUnit::KM->toInputValue($meters));
    }

    public static function kmCases(): \Generator
    {
        yield 'entier' => ['5', 5000, '5'];
        yield 'décimal virgule' => ['5,5', 5500, '5,5'];
        yield 'décimal point' => ['5.5', 5500, '5,5'];
        yield 'fraction de km' => ['0,4', 400, '0,4'];
    }

    public function testMetersRoundTrip(): void
    {
        self::assertSame(2000, DistanceUnit::METERS->toMeters('2000'));
        self::assertSame('2000', DistanceUnit::METERS->toInputValue(2000));
    }

    public function testEmptyInputIsNull(): void
    {
        self::assertNull(DistanceUnit::KM->toMeters(''));
        self::assertNull(DistanceUnit::METERS->toMeters('   '));
    }

    public function testInvalidInputThrows(): void
    {
        $this->expectException(TransformationFailedException::class);
        DistanceUnit::KM->toMeters('vite');
    }
}
