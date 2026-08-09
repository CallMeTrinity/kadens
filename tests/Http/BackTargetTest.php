<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\BackTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le jeton `?from=` vient de la barre d'adresse : n'importe qui peut y écrire
 * n'importe quoi, et une URL vieillie ou tronquée y arrive toute seule. Ce qui
 * est vérifié ici, c'est donc la frontière, pas la logique — un jeton illisible
 * doit se lire comme une absence, jamais produire une page cassée ni une route
 * qu'on n'a pas prévue.
 */
final class BackTargetTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function validTokens(): iterable
    {
        yield 'plan' => ['plan-12', BackTarget::PLAN, '12'];
        // Le piège de l'alternance : `plan-edit-12` ne doit pas se lire comme un
        // plan dont l'identifiant serait « edit-12 ».
        yield 'éditeur de plan' => ['plan-edit-12', BackTarget::PLAN_EDIT, '12'];
        yield 'semaine' => ['cal-week-2026-08-10', BackTarget::CAL_WEEK, '2026-08-10'];
        yield 'mois' => ['cal-month-2026-08', BackTarget::CAL_MONTH, '2026-08'];
    }

    #[DataProvider('validTokens')]
    public function testReadsAKnownToken(string $raw, string $kind, string $value): void
    {
        $target = BackTarget::parse($raw);

        self::assertNotNull($target);
        self::assertSame($kind, $target->kind);
        self::assertSame($value, $target->value);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function rejectedTokens(): iterable
    {
        yield 'absent' => [null];
        yield 'vide' => [''];
        yield 'type inconnu' => ['workout-12'];
        yield 'identifiant non numérique' => ['plan-abc'];
        yield 'identifiant manquant' => ['plan-'];
        // Le format est bon, la date n'existe pas. Sans le filtre, la route se
        // génèrerait (son requirement est la même regex) et c'est le calendrier
        // qui planterait à l'arrivée.
        yield 'mois impossible' => ['cal-month-2026-13'];
        yield 'jour impossible' => ['cal-week-2026-02-31'];
        yield 'date au mauvais format' => ['cal-week-2026-8-10'];
        // Le jeton ne porte jamais d'URL : rien de ce qui ressemble à une
        // destination ne doit passer, sinon il deviendrait une redirection ouverte.
        yield 'url absolue' => ['https://exemple.test/'];
        yield 'chemin' => ['/workout/12'];
        yield 'jeton avec suffixe' => ['plan-12-bis'];
    }

    #[DataProvider('rejectedTokens')]
    public function testRejectsAnythingElse(?string $raw): void
    {
        self::assertNull(BackTarget::parse($raw));
    }

    public function testTokenIsReadBackByParse(): void
    {
        // Émission et lecture sont deux moitiés d'un même format : le jour où
        // l'une bouge sans l'autre, tous les retours retombent silencieusement
        // sur leur repli et personne ne voit d'erreur.
        foreach ([[BackTarget::PLAN, 12], [BackTarget::PLAN_EDIT, 12], [BackTarget::CAL_MONTH, '2026-08']] as [$kind, $value]) {
            $target = BackTarget::parse(BackTarget::token($kind, $value));

            self::assertNotNull($target, \sprintf('Le jeton « %s » n\'est pas relu.', $kind));
            self::assertSame($kind, $target->kind);
        }
    }
}
