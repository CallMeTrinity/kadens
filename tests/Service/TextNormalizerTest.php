<?php

namespace App\Tests\Service;

use App\Service\TextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `TextNormalizer` : la forme comparable d'un libellé.
 *
 * Ces cas ne sont pas décoratifs — ils **fixent le contrat que
 * `assets/search.js` doit produire à l'identique**. Une divergence entre les
 * deux ferait apparaître à l'écran ce que l'import considère comme un autre
 * exercice, ou ferait échouer une adoption qui aurait dû réussir. Le fichier JS
 * porte les mêmes cas dans son commentaire d'en-tête ; s'ils bougent ici, ils
 * bougent là-bas.
 */
final class TextNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function pairs(): iterable
    {
        yield 'accents' => ['Développé couché', 'developpe couche'];
        yield 'majuscules et parenthèses' => ['Traction en supination (Chin-up)', 'traction en supination chin up'];
        yield 'deux-points et espaces multiples' => ['Mobilité :  Étirement Pigeon', 'mobilite etirement pigeon'];
        yield 'ligature œ' => ['Cœur', 'coeur'];
        yield 'ligature æ' => ['Ex æquo', 'ex aequo'];
        yield 'chiffres conservés' => ['Sprint 100m', 'sprint 100m'];
        yield 'barre oblique' => ['RPM / Spinning', 'rpm spinning'];
        yield 'apostrophe' => ["Pose de l'enfant", 'pose de l enfant'];
        yield 'degré' => ['Banc à 45°', 'banc a 45'];
        yield 'espaces en bordure' => ['  Squat  ', 'squat'];
        yield 'chaîne vide' => ['', ''];
    }

    #[DataProvider('pairs')]
    public function testItProducesTheComparableForm(string $input, string $expected): void
    {
        self::assertSame($expected, TextNormalizer::normalize($input));
    }

    public function testItDropsTheFrenchStopWords(): void
    {
        self::assertSame(
            ['extension', 'triceps', 'poulie', 'haute'],
            TextNormalizer::words('Extension des triceps à la poulie haute'),
        );
    }

    /**
     * `slug()` propose une `refKey`. Ce n'est pas un `SlugGenerator` : ni
     * unicité, ni suffixe, ni régénération — une clé posée ne change plus.
     */
    public function testTheSlugIsKebabCaseAscii(): void
    {
        self::assertSame('traction-en-supination', TextNormalizer::slug('Traction en supination'));
        self::assertSame('mobilite-etirement-pigeon', TextNormalizer::slug('Mobilité : Étirement Pigeon'));
        self::assertSame('', TextNormalizer::slug('—'));
    }
}
