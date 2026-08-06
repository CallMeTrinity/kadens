<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\SetType;
use App\Service\BlastCsvParser;
use PHPUnit\Framework\TestCase;

/**
 * Les cas qui ont réellement mordu sur les trois exports Blast. Chaque test
 * correspond à une irrégularité du format, pas à une branche de code : c'est le
 * fichier qui est capricieux, pas le parseur.
 */
final class BlastCsvParserTest extends TestCase
{
    private BlastCsvParser $parser;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->parser = new BlastCsvParser();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private const string HEADER = 'Date%1$sEntraînement%1$sExercise%1$sEquipement%1$sExécution%1$sType de Set%1$sReps%1$sCharge%1$sDurée%1$sDistance%1$scsv.headers.steps%1$sRPE%1$sCalories Brûlées%1$sDurée de l\'Entraînement';

    /** Écrit un CSV temporaire à partir de lignes déjà formatées. */
    private function csv(string $delimiter, string ...$rows): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'blast');
        file_put_contents($file, \sprintf(self::HEADER, $delimiter) . "\n" . implode("\n", $rows) . "\n");
        $this->files[] = $file;

        return $file;
    }

    /**
     * Le séparateur change d'un export à l'autre : virgule en 2024/2025,
     * point-virgule en 2026. Le supposer casserait deux fichiers sur trois.
     */
    public function testDelimiterIsDetectedPerFile(): void
    {
        $comma = $this->csv(',', '2025-01-05 18:48:04,Pull,Curl marteau,Haltères,Bilatéral,Normale,10,20,0,0,0,0,300,01:00:00');
        $semi = $this->csv(';', '2026-01-05 18:48:04;Pull;Curl marteau;Haltères;Bilatéral;Normale;10;20;0;0;0;0;300;01:00:00');

        foreach ([$comma, $semi] as $file) {
            $sessions = $this->parser->parse($file);

            self::assertCount(1, $sessions);
            self::assertSame('Pull', $sessions[0]['title']);
            self::assertSame('Curl marteau', $sessions[0]['entries'][0]['name']);
        }
    }

    /**
     * Un titre contenant le séparateur est quoté. `explode` le couperait en deux
     * colonnes et décalerait tout le reste de la ligne.
     */
    public function testQuotedFieldContainingTheDelimiter(): void
    {
        $file = $this->csv(',', '2025-07-16 10:18:05,"Shoulders, Arms",Développé militaire,Haltères,Bilatéral,Normale,10,27.5,0,0,0,0,202,00:45:10');

        $sessions = $this->parser->parse($file);

        self::assertSame('Shoulders, Arms', $sessions[0]['title']);
        self::assertSame('Développé militaire', $sessions[0]['entries'][0]['name']);
        self::assertSame(27.5, $sessions[0]['entries'][0]['sets'][0]['weightKg']);
    }

    /**
     * Un exercice qui revient plus loin dans la séance ouvre une **seconde**
     * entrée. Le fusionner avec la première écraserait l'ordre réel du travail.
     */
    public function testRepeatedExerciseOpensASecondEntry(): void
    {
        $file = $this->csv(
            ';',
            '2026-01-02 11:37:07;Legs;Squat;Barre;Bilatéral;Normale;5;100;0;0;0;0;0;01:00:00',
            '2026-01-02 11:37:07;Legs;Squat;Barre;Bilatéral;Normale;5;100;0;0;0;0;0;01:00:00',
            '2026-01-02 11:37:07;Legs;Curl;Barre;Bilatéral;Normale;10;30;0;0;0;0;0;01:00:00',
            '2026-01-02 11:37:07;Legs;Squat;Barre;Bilatéral;Normale;3;110;0;0;0;0;0;01:00:00',
        );

        $entries = $this->parser->parse($file)[0]['entries'];

        self::assertCount(3, $entries);
        self::assertSame(['Squat', 'Curl', 'Squat'], array_column($entries, 'name'));
        self::assertCount(2, $entries[0]['sets']);
        self::assertCount(1, $entries[2]['sets']);
    }

    /**
     * L'équipement et l'exécution entrent dans la clé, parce que Kadens n'a ni
     * champ « équipement » ni champ « unilatéral » : ce sont des exercices
     * distincts. Le bilatéral, lui, est le cas ordinaire et ne suffixe rien.
     */
    public function testKeyCarriesEquipmentAndNonDefaultExecution(): void
    {
        $file = $this->csv(
            ';',
            '2026-01-02 11:37:07;Pull;Curl;Haltères;Bilatéral;Normale;10;20;0;0;0;0;0;01:00:00',
            '2026-01-02 11:37:07;Pull;Curl;Haltères;Unilatéral;Normale;10;20;0;0;0;0;0;01:00:00',
            '2026-01-02 11:37:07;Pull;Curl;Barre;Bilatéral;Normale;10;30;0;0;0;0;0;01:00:00',
        );

        $entries = $this->parser->parse($file)[0]['entries'];

        self::assertSame(
            ['Curl|Haltères', 'Curl|Haltères|Unilatéral', 'Curl|Barre'],
            array_column($entries, 'key'),
        );
    }

    /**
     * Un zéro de Blast est une absence, pas une valeur : c'est ce qu'il écrit
     * pour une charge au poids du corps. Le garder ferait entrer un 0 kg dans les
     * records et compterait une série fantôme dans le tonnage.
     */
    public function testZeroMeansAbsentNotZero(): void
    {
        $file = $this->csv(';', '2026-01-02 11:37:07;Push;Pompe;Poids du corps;Bilatéral;Normale;20;0;0;0;0;0;0;01:00:00');

        $set = $this->parser->parse($file)[0]['entries'][0]['sets'][0];

        self::assertSame(20, $set['reps']);
        self::assertNull($set['weightKg']);
        self::assertNull($set['durationSeconds']);
    }

    /**
     * Les isométries n'ont ni répétitions ni durée dans l'export. La série a bien
     * eu lieu, seule sa mesure manque : la jeter perdrait le fait.
     */
    public function testIsometricSetWithoutAnyMeasureIsKept(): void
    {
        $file = $this->csv(';', '2026-05-02 09:45:00;Core;Planche frontale;Poids du corps;Bilatéral;Normale;0;0;0;0;0;0;0;01:00:00');

        $sets = $this->parser->parse($file)[0]['entries'][0]['sets'];

        self::assertCount(1, $sets);
        self::assertNull($sets[0]['reps']);
        self::assertNull($sets[0]['durationSeconds']);
    }

    public function testSetTypesAreMappedAndDefaultToNormal(): void
    {
        $file = $this->csv(
            ';',
            '2026-01-02 11:37:07;Push;Développé;Barre;Bilatéral;Normale;5;80;0;0;0;0;0;01:00:00',
            '2026-01-02 11:37:07;Push;Développé;Barre;Bilatéral;Échec;3;80;0;0;0;0;0;01:00:00',
            '2026-01-02 11:37:07;Push;Développé;Barre;Bilatéral;Dégressive;8;60;0;0;0;0;0;01:00:00',
            '2026-01-02 11:37:07;Push;Développé;Barre;Bilatéral;;8;60;0;0;0;0;0;01:00:00',
        );

        $sets = $this->parser->parse($file)[0]['entries'][0]['sets'];

        self::assertSame(
            [SetType::NORMAL, SetType::TO_FAILURE, SetType::DEGRESSIVE, SetType::NORMAL],
            array_column($sets, 'setType'),
        );
    }

    /**
     * L'export horodate en heure locale sans décalage, et PHP tourne en UTC. Lire
     * la date sans fuseau explicite décalerait chaque séance d'une à deux heures
     * par rapport à celles que le téléphone logue. Le **jour**, lui, reste le jour
     * local.
     */
    public function testTimestampsAreReadLocallyAndStoredInUtc(): void
    {
        $file = $this->csv(';', '2024-08-26 23:57:00;Upper;Pompe;Poids du corps;Bilatéral;Normale;10;0;0;0;0;0;0;01:30:00');

        $session = $this->parser->parse($file, 'Europe/Paris')[0];

        // Août : Paris est à UTC+2.
        self::assertSame('2024-08-26T21:57:00+00:00', $session['startedAt']->format('c'));
        self::assertSame('2024-08-26', $session['date']->format('Y-m-d'));
        self::assertSame('2024-08-26T23:27:00+00:00', $session['endedAt']?->format('c'));
    }

    /**
     * `00:00:00` n'est pas une séance instantanée, c'est une durée que Blast n'a
     * pas mesurée. Un `endedAt` égal au départ afficherait une séance de zéro
     * minute.
     */
    public function testZeroDurationYieldsNoEnd(): void
    {
        $file = $this->csv(';', '2024-08-26 23:57:00;Upper;Pompe;Poids du corps;Bilatéral;Normale;10;0;0;0;0;0;0;00:00:00');

        self::assertNull($this->parser->parse($file)[0]['endedAt']);
    }

    /** Une ligne sans exercice ne décrit rien ; sa séance disparaît si c'est la seule. */
    public function testRowWithoutExerciseIsDropped(): void
    {
        $file = $this->csv(',', '2025-02-11 14:55:05,Fractionné 8x400m,,,,,,,0,0,0,0,0,00:00:00');

        self::assertSame([], $this->parser->parse($file));
    }

    public function testSessionsComeBackInChronologicalOrder(): void
    {
        $file = $this->csv(
            ';',
            '2026-03-02 11:00:00;B;Squat;Barre;Bilatéral;Normale;5;100;0;0;0;0;0;01:00:00',
            '2026-01-02 11:00:00;A;Squat;Barre;Bilatéral;Normale;5;100;0;0;0;0;0;01:00:00',
        );

        self::assertSame(['A', 'B'], array_column($this->parser->parse($file), 'title'));
    }

    /** Un en-tête incomplet est une erreur franche, pas une colonne lue de travers. */
    public function testMissingColumnIsRefused(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'blast');
        file_put_contents($file, "Date;Entraînement;Exercise\n2026-01-02 11:00:00;A;Squat\n");
        $this->files[] = $file;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Colonnes absentes/');

        $this->parser->parse($file);
    }
}
