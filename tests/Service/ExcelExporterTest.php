<?php

namespace App\Tests\Service;

use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\PrescribedExercise;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\ExerciseLanguage;
use App\Enum\PrescriptionType;
use App\Enum\ScheduledStatus;
use App\Service\ActivityPalette;
use App\Service\ExcelExporter;
use App\Service\ExerciseNaming;
use App\Service\PlanFlattener;
use App\Service\RegionBreakdown;
use App\Service\SupersetGrouper;
use App\Service\UnitFormatter;
use App\Service\WorkoutEstimator;
use App\Service\WorkoutMetrics;
use App\Tests\ReadsSpreadsheets;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\RichText\Run;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Le classeur tel que l'utilisateur l'ouvre : chaque test écrit le fichier, le
 * relit, et vérifie des cellules. Les règles gardées ici sont celles qui ont
 * décroché de l'export pendant que l'app avançait sans lui : nommage des
 * exercices, notes privées, séances libres, saisie jamais interprétée.
 */
final class ExcelExporterTest extends TestCase
{
    use ReadsSpreadsheets;

    public function testWorkoutSheetReadsLikeTheWorkoutPage(): void
    {
        $workout = $this->workout('Haut du corps')
            ->setDescription('Pousser lourd, tirer propre.')
            ->setEstimatedDurationMinutes(60);

        $main = $this->block(BlockRole::MAIN, 0, rounds: 1, label: 'Force');
        $main->addPrescribedExercise($this->sets('Développé couché', 0, group: 1)->setRpe(9)->setRestSeconds(90));
        $main->addPrescribedExercise($this->sets('Rowing barre', 1, group: 1));
        $main->addPrescribedExercise($this->sets('Dips', 2)->setNotes('Lent en descente'));
        $workout->addBlock($main);

        $cooldown = $this->block(BlockRole::COOLDOWN, 1, rounds: 2);
        $cooldown->addPrescribedExercise($this->sets('Gainage', 0));
        $workout->addBlock($cooldown);

        $sheet = $this->roundTrip($this->exporter()->workout($workout))->getActiveSheet();

        self::assertSame('SÉANCE', $this->plain($sheet, 'A1'));
        self::assertSame('Haut du corps', $this->plain($sheet, 'A2'));
        self::assertSame('SALLE DE SPORT · 60 MIN · 2 BLOCS · 4 EXERCICES', $this->plain($sheet, 'A3'));
        self::assertSame('Pousser lourd, tirer propre.', $this->plain($sheet, 'A4'));

        // En-têtes une fois par séance, puis la tête de bloc : numéro, rôle en
        // capitales, libellé saisi laissé tel quel.
        $header = $this->rowOf($sheet, 'EXERCICE');
        self::assertSame(['EXERCICE', 'PRESCRIPTION', 'RPE', 'REPOS', 'NOTES'], $this->rowValues($sheet, $header, 'E'));
        self::assertSame('01  ENTRAÎNEMENT · Force', $this->plain($sheet, 'A'.($header + 1)));

        // Le superset s'annonce, ses membres gardent leur rang.
        self::assertSame('Superset A · enchaîné sans repos', $this->plain($sheet, 'A'.($header + 2)));
        $bench = $header + 3;
        self::assertSame('A1 · Développé couché', $this->plain($sheet, 'A'.$bench));
        self::assertSame('3 × 8 @ 80 kg', $this->plain($sheet, 'B'.$bench));

        // RPE et repos restent des nombres : triables, sommables.
        self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('C'.$bench)->getDataType());
        self::assertEquals(9, $sheet->getCell('C'.$bench)->getValue());
        self::assertSame('FFD8261E', $sheet->getStyle('C'.$bench)->getFont()->getColor()->getARGB(), 'RPE 9 = intensité, en rouge');
        self::assertEquals(90, $sheet->getCell('D'.$bench)->getValue());
        self::assertSame('90 s', $sheet->getCell('D'.$bench)->getFormattedValue());

        self::assertSame('A2 · Rowing barre', $this->plain($sheet, 'A'.($bench + 1)));
        self::assertSame('Dips', $this->plain($sheet, 'A'.($bench + 2)));
        self::assertSame('Lent en descente', $this->plain($sheet, 'E'.($bench + 2)));

        self::assertSame('02  RETOUR AU CALME · × 2 TOURS', $this->plain($sheet, 'A'.($bench + 3)));
    }

    /** Le libellé d'un exercice passe par ExerciseNaming : langue du lecteur, repli français. */
    public function testExerciseNamesFollowTheReadersLanguage(): void
    {
        $reader = (new User())->setEmail('reader@example.com')->setExerciseLanguage(ExerciseLanguage::EN);

        $workout = $this->workout('Push');
        $block = $this->block(BlockRole::MAIN, 0);
        $bench = $this->sets('Développé couché', 0);
        $bench->getExercise()?->setNameEn('Bench press');
        $block->addPrescribedExercise($bench);
        $block->addPrescribedExercise($this->sets('Dips', 1));
        $workout->addBlock($block);

        $text = $this->spreadsheetText($this->roundTrip($this->exporter($reader)->workout($workout)));

        self::assertStringContainsString('Bench press', $text);
        self::assertStringNotContainsString('Développé couché', $text);
        self::assertStringContainsString('Dips', $text, 'sans nom anglais, le français reste');
    }

    /**
     * Une saisie qui commence par `=` reste du texte. Sans type explicite,
     * PhpSpreadsheet en fait une formule : un titre posé par un coach
     * s'exécuterait dans le tableur de son athlète.
     */
    public function testUserInputIsNeverWrittenAsAFormula(): void
    {
        $workout = $this->workout('=1+1');
        $block = $this->block(BlockRole::MAIN, 0);
        $block->addPrescribedExercise($this->sets('Squat', 0)->setNotes('=HYPERLINK("http://example.com","clic")'));
        $workout->addBlock($block);

        $sheet = $this->roundTrip($this->exporter()->workout($workout))->getActiveSheet();

        self::assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
        self::assertSame('=1+1', $sheet->getCell('A2')->getValue());

        $row = $this->rowOf($sheet, 'Squat');
        self::assertSame(DataType::TYPE_STRING, $sheet->getCell('E'.$row)->getDataType());
        self::assertSame('=HYPERLINK("http://example.com","clic")', $sheet->getCell('E'.$row)->getValue());
    }

    /** Le bloc-notes privé n'entre pas dans PlanFlattener, donc jamais dans un classeur. */
    public function testPrivateNotesNeverReachTheWorkbook(): void
    {
        $workout = $this->workout('Seuil')->setNotes('BROUILLON-SEANCE');
        $block = $this->block(BlockRole::MAIN, 0);
        $block->addPrescribedExercise($this->sets('Squat', 0));
        $workout->addBlock($block);

        $template = $this->template('Prépa', 1)->setNotes('BROUILLON-PLAN');
        $template->addPlanItem((new PlanItem())->setWorkout($workout)->setWeekNumber(1)->setDayOfWeek(1));

        $exporter = $this->exporter();
        $workoutText = $this->spreadsheetText($this->roundTrip($exporter->workout($workout)));
        $planText = $this->spreadsheetText($this->roundTrip($exporter->planTemplate($template)));

        self::assertStringContainsString('Seuil', $workoutText);
        self::assertStringNotContainsString('BROUILLON', $workoutText);
        self::assertStringContainsString('Seuil', $planText);
        self::assertStringNotContainsString('BROUILLON', $planText);
    }

    /**
     * Le plan ouvre sur la grille de sa page (semaines × jours), chaque séance
     * dans la couleur de son activité — celle du lecteur s'il l'a changée —,
     * puis un onglet de détail qui saute les semaines vides.
     */
    public function testPlanOpensOnTheWeekGridThenDetailsEachSession(): void
    {
        $reader = (new User())->setEmail('reader@example.com')->setActivityColors(['running' => '#3b7a2c']);

        $intervals = $this->workout('Fractionné');
        $block = $this->block(BlockRole::MAIN, 0);
        $block->addPrescribedExercise($this->distance('400 m', 0));
        $intervals->addBlock($block);

        $template = $this->template('Prépa 10 km', 3);
        $template->addPlanItem((new PlanItem())->setWorkout($intervals)->setWeekNumber(1)->setDayOfWeek(3)->setNotes('à jeun'));
        $template->addPlanItem((new PlanItem())->setWorkout($intervals)->setWeekNumber(3)->setDayOfWeek(6));

        $book = $this->roundTrip($this->exporter($reader)->planTemplate($template));

        self::assertSame(['Plan', 'Séances'], $book->getSheetNames());

        $grid = $book->getSheetByNameOrThrow('Plan');
        self::assertSame('3 SEMAINES · 2 SÉANCES', $this->plain($grid, 'A3'));
        $header = $this->rowOf($grid, 'SEMAINE');
        self::assertSame(['SEMAINE', 'LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'], $this->rowValues($grid, $header, 'H'));

        // S1, mercredi (colonne D) : le titre, puis la consigne de la case.
        self::assertSame('S1', $this->plain($grid, 'A'.($header + 1)));
        self::assertSame("Fractionné\nà jeun", $this->plain($grid, 'D'.($header + 1)));
        self::assertSame('FF3B7A2C', $this->firstRunColor($grid, 'D'.($header + 1)));
        self::assertNull($grid->getCell('D'.($header + 2))->getValue(), 'S2 est une semaine de repos');
        self::assertSame('Fractionné', $this->plain($grid, 'G'.($header + 3)));

        $detail = $this->spreadsheetText($book->getSheetByNameOrThrow('Séances'));
        self::assertStringContainsString('SEMAINE 1', $detail);
        self::assertStringContainsString('MERCREDI · à jeun', $detail);
        self::assertStringNotContainsString('SEMAINE 2', $detail);
        self::assertStringContainsString('SEMAINE 3', $detail);
    }

    /**
     * Une séance libre (sans séance source) figure dans la liste du mois : elle
     * a eu lieu, ou elle est prévue. Elle n'a juste pas de programme à détailler.
     */
    public function testScheduleListsEverySessionAndDetailsOnlyThoseWithAProgram(): void
    {
        $tempo = $this->workout('Tempo');
        $block = $this->block(BlockRole::MAIN, 0);
        $block->addPrescribedExercise($this->distance('5 km', 0));
        $tempo->addBlock($block);

        $done = (new ScheduledWorkout())
            ->setWorkout($tempo)
            ->setScheduledDate(new \DateTimeImmutable('2026-07-14'))
            ->setStatus(ScheduledStatus::DONE)
            ->setCompletionNotes('Jambes lourdes');
        $free = (new ScheduledWorkout())
            ->setTitle('Séance improvisée')
            ->setScheduledDate(new \DateTimeImmutable('2026-07-16'))
            ->setStatus(ScheduledStatus::PLANNED);

        $book = $this->roundTrip($this->exporter()->schedule(
            [$done, $free],
            'Juillet 2026',
        ));

        self::assertSame(['Planning', 'Séances'], $book->getSheetNames());

        $list = $book->getSheetByNameOrThrow('Planning');
        self::assertSame('Juillet 2026', $this->plain($list, 'A2'));
        self::assertSame('2 SÉANCES · 1 FAITE · 1 PRÉVUE', $this->plain($list, 'A3'));

        $header = $this->rowOf($list, 'DATE');
        $first = $header + 1;

        // Une vraie date, pas une chaîne : le tableur sait la trier.
        self::assertSame(DataType::TYPE_NUMERIC, $list->getCell('A'.$first)->getDataType());
        self::assertSame('2026-07-14', ExcelDate::excelToDateTimeObject((float) $list->getCell('A'.$first)->getValue())->format('Y-m-d'));
        self::assertSame('14/07/2026', $list->getCell('A'.$first)->getFormattedValue());
        self::assertSame(['Mardi', 'Tempo'], [$this->plain($list, 'B'.$first), $this->plain($list, 'C'.$first)]);
        self::assertSame('Faite', $this->plain($list, 'E'.$first));
        self::assertSame('Jambes lourdes', $this->plain($list, 'H'.$first));

        self::assertSame('Séance improvisée', $this->plain($list, 'C'.($first + 1)));
        self::assertSame('Prévue', $this->plain($list, 'E'.($first + 1)));
        self::assertSame('A'.$header.':H'.($first + 1), $list->getAutoFilter()->getRange());

        $detail = $this->spreadsheetText($book->getSheetByNameOrThrow('Séances'));
        self::assertStringContainsString('MARDI 14/07/2026 · FAITE', $detail);
        self::assertStringContainsString('Tempo', $detail);
        self::assertStringNotContainsString('Séance improvisée', $detail);
    }

    public function testAnEmptyMonthSaysSoWithoutADetailSheet(): void
    {
        $book = $this->roundTrip($this->exporter()->schedule([], 'Août 2026'));

        self::assertSame(['Planning'], $book->getSheetNames());
        self::assertStringContainsString('Aucune séance sur cette période.', $this->spreadsheetText($book));
    }

    // --------------------------------------------------------- Fixtures

    private function exporter(?User $reader = null): ExcelExporter
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($reader);

        $flattener = new PlanFlattener(
            new UnitFormatter(),
            new WorkoutMetrics(new WorkoutEstimator(), new SupersetGrouper(), new RegionBreakdown()),
            new SupersetGrouper(),
        );

        return new ExcelExporter($flattener, new ExerciseNaming($security), new ActivityPalette(), $security);
    }

    private function workout(string $title): Workout
    {
        return (new Workout())->setTitle($title)->setSlug('s-'.bin2hex(random_bytes(3)));
    }

    private function template(string $title, int $weeks): PlanTemplate
    {
        return (new PlanTemplate())->setTitle($title)->setDurationWeeks($weeks)->setSlug('p-'.bin2hex(random_bytes(3)));
    }

    private function block(BlockRole $role, int $position, int $rounds = 1, ?string $label = null): Block
    {
        return (new Block())->setRole($role)->setRounds($rounds)->setPosition($position)->setLabel($label);
    }

    private function sets(string $name, int $position, ?int $group = null): PrescribedExercise
    {
        return (new PrescribedExercise())
            ->setExercise((new Exercise())->setName($name)->setActivity(ActivityType::GYM))
            ->setPrescriptionType(PrescriptionType::SETS_REPS)
            ->setPosition($position)
            ->setSupersetGroup($group)
            ->setSets(3)->setReps(8)->setWeightKg(80.0);
    }

    private function distance(string $name, int $position): PrescribedExercise
    {
        return (new PrescribedExercise())
            ->setExercise((new Exercise())->setName($name)->setActivity(ActivityType::RUNNING))
            ->setPrescriptionType(PrescriptionType::DISTANCE_PACE)
            ->setPosition($position)
            ->setDistanceMeters(400);
    }

    // ---------------------------------------------------------- Lecture

    private function plain(Worksheet $sheet, string $coordinate): string
    {
        $value = $sheet->getCell($coordinate)->getValue();

        return $value instanceof RichText ? $value->getPlainText() : (string) $value;
    }

    /** Première ligne dont la colonne A porte exactement ce texte. */
    private function rowOf(Worksheet $sheet, string $text): int
    {
        for ($row = 1; $row <= $sheet->getHighestRow(); ++$row) {
            if ($text === $this->plain($sheet, 'A'.$row)) {
                return $row;
            }
        }

        self::fail(sprintf('« %s » introuvable en colonne A.', $text));
    }

    /** @return list<string> */
    private function rowValues(Worksheet $sheet, int $row, string $lastColumn): array
    {
        $values = [];
        foreach (range('A', $lastColumn) as $column) {
            $values[] = $this->plain($sheet, $column.$row);
        }

        return $values;
    }

    private function firstRunColor(Worksheet $sheet, string $coordinate): ?string
    {
        $value = $sheet->getCell($coordinate)->getValue();
        self::assertInstanceOf(RichText::class, $value);
        $run = $value->getRichTextElements()[0] ?? null;
        self::assertInstanceOf(Run::class, $run);

        return $run->getFont()?->getColor()->getARGB();
    }
}
