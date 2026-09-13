<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SecretBox;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase
{
    private const string KEY = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';

    public function testSealThenOpenRoundTrips(): void
    {
        $box = new SecretBox(self::KEY);

        $sealed = $box->seal('clé-intervals');

        self::assertStringNotContainsString('clé-intervals', $sealed);
        self::assertSame('clé-intervals', $box->open($sealed));
    }

    /** Nonce aléatoire : deux scellés du même secret ne se ressemblent pas. */
    public function testTheSameSecretNeverSealsTheSameWay(): void
    {
        $box = new SecretBox(self::KEY);

        self::assertNotSame($box->seal('x'), $box->seal('x'));
    }

    /** Authentifié : un octet altéré échoue, il ne rend pas une clé fausse. */
    public function testATamperedMessageIsRejected(): void
    {
        $box = new SecretBox(self::KEY);
        $raw = base64_decode($box->seal('clé'), true);
        $raw[\strlen($raw) - 1] = \chr(\ord($raw[\strlen($raw) - 1]) ^ 1);

        $this->expectException(\RuntimeException::class);
        $box->open(base64_encode($raw));
    }

    public function testAnotherKeyCannotOpen(): void
    {
        $sealed = (new SecretBox(self::KEY))->seal('clé');

        $this->expectException(\RuntimeException::class);
        (new SecretBox(str_repeat('ab', 32)))->open($sealed);
    }

    public function testAMissingKeyFailsLoudlyOnUse(): void
    {
        $this->expectException(\LogicException::class);
        (new SecretBox(null))->seal('clé');
    }
}
