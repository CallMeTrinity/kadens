<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\SetType;
use App\Service\FitNotesCsvParser;
use PHPUnit\Framework\TestCase;

/**
 * Les cas qui ont réellement mordu sur l'export FitNotes. Comme pour Blast,
 * chaque test correspond à une irrégularité du format, pas à une branche de
 * code : c'est le fichier qui est capricieux, pas le parseur.
 */
final class FitNotesCsvParserTest extends TestCase
{
    private FitNotesCsvParser $parser;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->parser = new FitNotesCsvParser();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private const string HEADER = 'Date,Exercise,Category,Weight,Weight Unit,Reps,Distance,Distance Unit,Time,Comment';

    /** Écrit un CSV temporaire à partir de lignes déjà formatées. */
    private function csv(string ...$rows): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'fitnotes');
        $this->files[] = $file;

        file_put_contents($file, implode("\n", [self::HEADER, ...$rows]) . "\n");

        return $file;
    }

    public function testUneLigneEstUneSerieEtLeJourIdentifieLaSeance(): void
    {
        $sessions = $this->parser->parse($this->csv(
            '2025-09-24,Fentes,Legs,8.0,kgs,10,,,,',
            '2025-09-24,Fentes,Legs,8.0,kgs,10,,,,',
            '2025-09-24,Fentes,Legs,8.0,kgs,12,,,,',
        ));

        self::assertCount(1, $sessions);
        self::assertSame('fitnotes|2025-09-24', $sessions[0]['sourceKey']);
        self::assertCount(1, $sessions[0]['entries']);
        self::assertCount(3, $sessions[0]['entries'][0]['sets']);
        self::assertSame([10, 10, 12], array_column($sessions[0]['entries'][0]['sets'], 'reps'));
    }

    /**
     * Deux jours = deux séances, et l'export n'ayant pas d'heure, c'est le seul
     * découpage possible : tout ce qui est fait le même jour est une séance.
     */
    public function testDeuxJoursDonnentDeuxSeances(): void
    {
        $sessions = $this->parser->parse($this->csv(
            '2025-10-01,Deadlift,Back,50.0,kgs,1,,,,',
            '2025-09-24,Crunch,Abs,0.0,kgs,15,,,,',
        ));

        self::assertCount(2, $sessions);
        // Rendues dans l'ordre chronologique, pas dans celui du fichier.
        self::assertSame('2025-09-24', $sessions[0]['date']->format('Y-m-d'));
        self::assertSame('2025-10-01', $sessions[1]['date']->format('Y-m-d'));
    }

    /**
     * Le même exercice croisé plus loin dans la journée ouvre une **seconde**
     * entrée. Le fusionner écraserait l'ordre réel du travail (retour à un
     * mouvement, circuit) — c'est le cas du 2025-10-10 dans l'export réel, qui
     * fait Back, Legs, Chest, puis Back à nouveau.
     */
    public function testUnExerciceRepriseePlusLoinOuvreUneSecondeEntree(): void
    {
        $sessions = $this->parser->parse($this->csv(
            '2025-10-10,Deadlift,Back,50.0,kgs,5,,,,',
            '2025-10-10,Barbell Squat,Legs,60.0,kgs,5,,,,',
            '2025-10-10,Deadlift,Back,60.0,kgs,3,,,,',
        ));

        $entries = $sessions[0]['entries'];

        self::assertCount(3, $entries);
        self::assertSame(['Deadlift', 'Barbell Squat', 'Deadlift'], array_column($entries, 'name'));
    }

    /**
     * Le titre est dérivé des catégories distinctes, dans l'ordre d'apparition.
     * Une catégorie qui revient ne se répète pas.
     */
    public function testLeTitreListeLesCategoriesDistinctesDansLOrdre(): void
    {
        $sessions = $this->parser->parse($this->csv(
            '2025-10-13,Lat Pulldown,Back,35.0,kgs,12,,,,',
            '2025-10-13,Cable Biceps Curl,Biceps,13.5,kgs,10,,,,',
            '2025-10-13,Seated Cable Row,Back,32.0,kgs,12,,,,',
        ));

        self::assertSame('Back, Biceps', $sessions[0]['title']);
    }

    /**
     * Le piège de forme du fichier : `Comment` est la dernière colonne, n'est pas
     * quotée et contient des virgules. Le surplus lui revient.
     */
    public function testUnCommentaireAVirgulesNEstPasTronque(): void
    {
        $sessions = $this->parser->parse($this->csv(
            '2026-01-02,Push Up Knees,Chest,0.0,kgs,10,,,,Attention position (pas instinctive), leger tiraillement, 5,5/10',
        ));

        self::assertSame(
            'Attention position (pas instinctive), leger tiraillement, 5,5/10',
            $sessions[0]['entries'][0]['notes'],
        );
    }

    /**
     * La distance n'a pas de colonne en face dans `LoggedSet` : elle part en
     * note, verbatim avec son unité. La série, elle, est conservée — un port de
     * charge n'a ni reps ni durée, mais il a eu lieu.
     */
    public function testLaDistanceDUnPortDeChargePartEnNoteEtLaSerieReste(): void
    {
        $sessions = $this->parser->parse($this->csv(
            "2026-04-23,Farmer's Carry,All,,,,30.0,m,0:00:00,",
            "2026-04-23,Farmer's Carry,All,,,,30.0,m,0:00:00,",
        ));

        $entry = $sessions[0]['entries'][0];

        self::assertCount(2, $entry['sets']);
        self::assertNull($entry['sets'][0]['reps']);
        self::assertNull($entry['sets'][0]['weightKg']);
        self::assertNull($entry['sets'][0]['durationSeconds']);
        // Répétée à l'identique sur les deux séries, écrite une seule fois.
        self::assertSame('Distance : 30 m', $entry['notes']);
    }

    /** Des commentaires différents d'une série à l'autre s'empilent, sans doublon. */
    public function testLesNotesSEmpilentSansSeRepeter(): void
    {
        $sessions = $this->parser->parse($this->csv(
            '2025-12-29,Assisted Pull Up,Back,18.0,kgs,10,,,,Ok ca vole moins',
            '2025-12-29,Assisted Pull Up,Back,18.0,kgs,10,,,,Ok ca vole moins',
            '2025-12-29,Assisted Pull Up,Back,18.0,kgs,8,,,,Du mal a finir 8/10',
        ));

        self::assertSame(
            "Ok ca vole moins\nDu mal a finir 8/10",
            $sessions[0]['entries'][0]['notes'],
        );
    }

    /**
     * Un zéro est une absence, pas une valeur : `0.0 kgs` est le poids du corps,
     * `0:00:00` une durée non mesurée. Les laisser passer ferait entrer un 0 kg
     * dans les records et une série fantôme dans le tonnage.
     */
    public function testLesZerosSontDesAbsences(): void
    {
        $sessions = $this->parser->parse($this->csv(
            '2026-04-23,Wall Sit,Legs,0.0,kgs,,0.0,m,0:00:30,',
        ));

        $set = $sessions[0]['entries'][0]['sets'][0];

        self::assertNull($set['weightKg']);
        self::assertNull($set['reps']);
        self::assertSame(30, $set['durationSeconds']);
        // `0.0 m` ne décrit rien : pas de note de distance.
        self::assertNull($sessions[0]['entries'][0]['notes']);
    }

    /** FitNotes n'a pas la notion de type de série : tout entre en `NORMAL`. */
    public function testToutesLesSeriesSontNormales(): void
    {
        $sessions = $this->parser->parse($this->csv(
            "2025-11-18,Seated Leg Curl Machine,Legs,35.4,kgs,16,,,,A l'echec",
        ));

        self::assertSame(SetType::NORMAL, $sessions[0]['entries'][0]['sets'][0]['setType']);
    }

    /**
     * FitNotes se règle en livres. La charge est normalisée en kilos
     * (`CLAUDE.md` §3), sinon tonnage et records seraient faux pour toujours.
     */
    public function testLesLivresSontConvertiesEnKilos(): void
    {
        $sessions = $this->parser->parse($this->csv(
            '2026-01-02,Flat Barbell Bench Press,Chest,100.0,lbs,5,,,,',
        ));

        self::assertSame(45.36, $sessions[0]['entries'][0]['sets'][0]['weightKg']);
    }

    /** Une unité inconnue est une erreur franche, pas une conversion au hasard. */
    public function testUneUniteDeChargeInconnueEchoue(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unité de charge inconnue');

        $this->parser->parse($this->csv(
            '2026-01-02,Flat Barbell Bench Press,Chest,100.0,stones,5,,,,',
        ));
    }

    /**
     * L'instant dont les séries héritent est **midi local**, pas minuit : minuit
     * local bascule la veille en UTC, et `LogMetrics::loggedAt()` daterait alors
     * la séance du mauvais jour.
     */
    public function testLInstantDesSeriesEstMidiLocalRenduEnUtc(): void
    {
        $sessions = $this->parser->parse($this->csv(
            '2025-09-24,Crunch,Abs,0.0,kgs,15,,,,',
        ));

        self::assertNull($sessions[0]['startedAt']);
        self::assertNull($sessions[0]['endedAt']);
        // Europe/Paris est à UTC+2 le 24 septembre.
        self::assertSame('2025-09-24T10:00:00+00:00', $sessions[0]['loggedAt']->format(\DateTimeInterface::ATOM));
        self::assertSame('2025-09-24', $sessions[0]['date']->format('Y-m-d'));
    }

    /** Une colonne attendue absente est une erreur, pas 1 700 séries de nulls. */
    public function testUnEnTeteIncompletEchoue(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'fitnotes');
        $this->files[] = $file;
        file_put_contents($file, "Date,Exercise,Reps\n2025-09-24,Crunch,15\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Colonnes absentes');

        $this->parser->parse($file);
    }

    /** Un BOM collerait au premier en-tête et rendrait `Date` introuvable. */
    public function testLeBomNeCasseFasLEnTete(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'fitnotes');
        $this->files[] = $file;
        file_put_contents($file, "\u{FEFF}" . self::HEADER . "\n2025-09-24,Crunch,Abs,0.0,kgs,15,,,,\n");

        $sessions = $this->parser->parse($file);

        self::assertCount(1, $sessions);
        self::assertSame('Crunch', $sessions[0]['entries'][0]['name']);
    }

    /** Une ligne sans exercice ne décrit rien de logable. */
    public function testUneLigneSansExerciceEstIgnoree(): void
    {
        $sessions = $this->parser->parse($this->csv(
            '2025-09-24,,Legs,,,,,,,',
            '2025-09-24,Crunch,Abs,0.0,kgs,15,,,,',
        ));

        self::assertCount(1, $sessions);
        self::assertCount(1, $sessions[0]['entries']);
    }
}
