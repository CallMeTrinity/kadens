<?php

namespace App\Service;

use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\ScheduledStatus;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Export .xlsx d'une séance, d'un plan ou d'un planning daté.
 *
 * ## Ce que le classeur lit, et ce qu'il ne lit jamais
 *
 * Le programme vient de {@see PlanFlattener}, et de lui seul : la mise à plat
 * n'est jamais réimplémentée ici. Conséquences héritées, à ne pas « corriger » :
 * - les notes privées (`Workout.notes`, `PlanTemplate.notes`) n'y entrent pas,
 *   puisque le flattener ne les porte pas ;
 * - le réalisé (séries loguées, activités importées) non plus. Le planning ne
 *   dit d'une séance faite que ce que le calendrier en dit : statut, ressenti,
 *   note de clôture. Le repository qui l'alimente ne charge d'ailleurs pas le
 *   réalisé (`findWindowWithContent`).
 *
 * Les libellés d'exercice passent par {@see ExerciseNaming} (langue du lecteur,
 * repli d'un exercice supprimé), les couleurs d'activité par
 * {@see ActivityPalette} (palette personnalisée du lecteur).
 *
 * ## Deux vues par classeur, comme dans l'app
 *
 * Un plan et un planning ouvrent sur une **vue d'ensemble** (la grille semaines
 * × jours de la page plan, la liste datée du calendrier), puis un second onglet
 * « Séances » détaille chaque programme. Une séance seule n'a qu'un onglet.
 *
 * ## Identité « Presse »
 *
 * Encre, papier froid, un seul rouge (RPE ≥ 9, séance manquée), rôle de bloc
 * codé par l'échelle de gris (`--color-cat-*`), activité par sa couleur « Vif ».
 * Les tokens CSS ne s'appliquent pas à un classeur : les valeurs sont reprises
 * en ARGB ci-dessous, avec le token d'origine en commentaire. Les capitales ne
 * touchent jamais au contenu saisi (titres, noms, notes).
 *
 * ## Saisie utilisateur = texte, toujours
 *
 * Tout ce qu'un utilisateur a tapé s'écrit en type chaîne explicite
 * ({@see self::text()}). Le binder par défaut de PhpSpreadsheet transforme en
 * formule une chaîne qui commence par `=` : un titre de séance posé par un coach
 * s'exécuterait alors dans le tableur de son athlète.
 *
 * @phpstan-import-type FlatWorkout from PlanFlattener
 * @phpstan-import-type FlatBlock from PlanFlattener
 * @phpstan-import-type FlatPrescribed from PlanFlattener
 */
final class ExcelExporter
{
    private const FONT = 'Arial';

    private const INK = 'FF0B0B0B';            // --kd-ink-900
    private const TEXT_SECONDARY = 'FF5C5C56'; // --kd-ink-muted
    private const TEXT_FAINT = 'FF75756E';     // --kd-ink-faint
    private const ON_INK = 'FFFFFFFF';         // --kd-paper-0
    private const FILL_SUBTLE = 'FFF3F3F1';    // --kd-paper-150
    private const BORDER = 'FFE2E2DE';         // --kd-border
    private const ACCENT = 'FFD8261E';         // --kd-accent : intensité, échec
    private const STATUS_DONE = 'FF006D14';    // --kd-status-done

    /** Rail d'un bloc selon son rôle : rang dans l'échelle de gris, comme la frise de séance. */
    private const ROLE_RAIL = [
        'warmup' => 'FF8A8A82',   // --kd-cat-3
        'main' => 'FF0B0B0B',     // --kd-cat-1
        'cooldown' => 'FF4A4A46', // --kd-cat-2
    ];

    /** RPE à partir duquel la prescription se lit comme dure (`.kd-exrow__rpe.is-hard`). */
    private const HARD_RPE = 9;

    /** Programme d'une séance : colonne → largeur. */
    private const PROGRAM_COLUMNS = ['A' => 32, 'B' => 34, 'C' => 6, 'D' => 8, 'E' => 30];
    private const PROGRAM_HEADERS = ['Exercice', 'Prescription', 'RPE', 'Repos', 'Notes'];

    /** Grille d'un plan : la semaine, puis lundi..dimanche. */
    private const GRID_COLUMNS = ['A' => 9, 'B' => 19, 'C' => 19, 'D' => 19, 'E' => 19, 'F' => 19, 'G' => 19, 'H' => 19];

    /** Liste datée d'un planning. */
    private const SCHEDULE_COLUMNS = ['A' => 11, 'B' => 10, 'C' => 30, 'D' => 22, 'E' => 10, 'F' => 11, 'G' => 10, 'H' => 34];
    private const SCHEDULE_HEADERS = ['Date', 'Jour', 'Séance', 'Plan', 'Statut', 'Forme', 'Difficulté', 'Notes'];

    /** Noms de jours FR indexés en ISO (1=lundi..7=dimanche). */
    private const DAY_NAMES = [
        1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi',
        5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche',
    ];
    private const DAY_SHORT = [1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 7 => 'Dim'];

    /** Hauteur d'une ligne de texte courant (Arial 10), en points. */
    private const LINE_HEIGHT = 13.0;

    /**
     * Palette d'activités du lecteur (`ActivityType::value` → hex), relue à
     * chaque export : le service est partagé, le lecteur ne l'est pas.
     *
     * @var array<string, string>
     */
    private array $colors = [];

    public function __construct(
        private readonly PlanFlattener $planFlattener,
        private readonly ExerciseNaming $naming,
        private readonly ActivityPalette $activityPalette,
        private readonly Security $security,
    ) {
    }

    /**
     * Classeur d'une séance seule : un onglet, l'en-tête de la séance puis son
     * programme bloc par bloc.
     */
    public function workout(Workout $workout): Spreadsheet
    {
        $this->loadPalette();
        $flat = $this->planFlattener->flattenWorkout($workout);
        $title = $workout->getTitle() ?? 'Séance';

        $spreadsheet = $this->newSpreadsheet($title);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetTitle($title));
        $this->layout($sheet, self::PROGRAM_COLUMNS);

        $row = $this->writeHeading($sheet, 1, 'Séance', $title, $this->workoutMeta($flat), $workout->getDescription(), self::PROGRAM_COLUMNS);
        $this->writeProgram($sheet, $row, $flat);

        $this->preparePrint($sheet, PageSetup::ORIENTATION_PORTRAIT, $title);

        return $spreadsheet;
    }

    /**
     * Classeur d'un plan multi-semaines : la grille semaines × jours (ce que
     * montre la page du plan), puis le détail de chaque séance placée.
     */
    public function planTemplate(PlanTemplate $template): Spreadsheet
    {
        $this->loadPalette();
        $flat = $this->planFlattener->flattenPlanTemplate($template);
        $title = $template->getTitle() ?? 'Plan';

        $sessionCount = 0;
        foreach ($flat['weeks'] as $week) {
            $sessionCount += $this->weekSessionCount($week['days']);
        }
        $weekCount = \count($flat['weeks']);

        $spreadsheet = $this->newSpreadsheet($title);

        // --- Onglet 1 : la grille.
        $grid = $spreadsheet->getActiveSheet();
        $grid->setTitle('Plan');
        $this->layout($grid, self::GRID_COLUMNS);

        $meta = $this->plural($weekCount, 'semaine').' · '.$this->plural($sessionCount, 'séance');
        $row = $this->writeHeading($grid, 1, 'Plan', $title, $meta, $template->getDescription(), self::GRID_COLUMNS);

        $headerRow = $row;
        $this->writeColumnHeaders($grid, $row, ['Semaine', ...array_values(self::DAY_SHORT)]);
        ++$row;

        foreach ($flat['weeks'] as $week) {
            $this->text($grid, 'A'.$row, 'S'.$week['weekNumber']);
            $grid->getStyle('A'.$row)->getFont()->setBold(true);

            foreach ($week['days'] as $day) {
                if ([] === $day['items']) {
                    continue;
                }

                $coordinate = Coordinate::stringFromColumnIndex($day['dayOfWeek'] + 1).$row;
                $only = $day['items'][0];
                $onlyNote = $only['item']->getNotes();

                // Le cas courant, une séance sans consigne : texte simple, mis en
                // forme à la cellule. Le texte enrichi n'est rendu ni par l'aperçu
                // du Finder ni par certains lecteurs, il ne sert qu'au cas qui en a
                // besoin.
                if (1 === \count($day['items']) && (null === $onlyNote || '' === $onlyNote)) {
                    $this->text($grid, $coordinate, $only['workout']['workout']->getTitle() ?? 'Séance');
                    $grid->getStyle($coordinate)->getFont()->setBold(true)->getColor()
                        ->setARGB($this->activityColor($only['workout']['activities']) ?? self::INK);
                    continue;
                }

                // Plusieurs séances (biquotidien) ou une consigne : une ligne par
                // séance, chacune dans la couleur de son activité dominante, la
                // consigne de la case en dessous.
                $cell = new RichText();
                foreach ($day['items'] as $index => $entry) {
                    if ($index > 0) {
                        $cell->createText("\n");
                    }
                    $this->run($cell, $entry['workout']['workout']->getTitle() ?? 'Séance', $this->activityColor($entry['workout']['activities']) ?? self::INK, bold: true);

                    $note = $entry['item']->getNotes();
                    if (null !== $note && '' !== $note) {
                        $this->run($cell, "\n".$note, self::TEXT_SECONDARY, italic: true, size: 9);
                    }
                }
                $grid->getCell($coordinate)->setValue($cell);
            }

            ++$row;
        }

        $gridRange = 'A'.$headerRow.':H'.($row - 1);
        $grid->getStyle($gridRange)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::BORDER);
        $grid->getStyle($gridRange)->getAlignment()->setWrapText(true);
        $grid->freezePane('B'.($headerRow + 1));
        $this->preparePrint($grid, PageSetup::ORIENTATION_LANDSCAPE, $title, $headerRow);

        // --- Onglet 2 : le détail, semaine par semaine.
        if ($sessionCount > 0) {
            $detail = $spreadsheet->createSheet();
            $detail->setTitle('Séances');
            $this->layout($detail, self::PROGRAM_COLUMNS);

            $row = 1;
            foreach ($flat['weeks'] as $week) {
                $count = $this->weekSessionCount($week['days']);
                if (0 === $count) {
                    continue;
                }

                // Une ligne d'air de plus avant une nouvelle semaine qu'entre deux séances.
                $row = $this->writeWeekBanner($detail, 1 === $row ? $row : $row + 1, $week['weekNumber'], $count);

                foreach ($week['days'] as $day) {
                    foreach ($day['items'] as $entry) {
                        // Le jour en capitales, la consigne de la case telle que saisie.
                        $context = $this->upper(self::DAY_NAMES[$day['dayOfWeek']]);
                        $note = $entry['item']->getNotes();
                        if (null !== $note && '' !== $note) {
                            $context .= ' · '.$note;
                        }

                        $row = $this->writeSession($detail, $row, $context, self::TEXT_FAINT, $entry['workout']);
                    }
                }
            }

            $this->preparePrint($detail, PageSetup::ORIENTATION_PORTRAIT, $title);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * Classeur d'un planning daté sur une période : la liste des séances (date,
     * statut, ressenti, note de clôture), puis le programme de chacune.
     *
     * Une séance libre (sans séance source) figure dans la liste avec son titre
     * et son statut : elle a eu lieu, ou elle est prévue. Elle n'a simplement
     * pas de programme à détailler.
     *
     * Le ressenti appartient au propriétaire, et c'est lui qui exporte son
     * calendrier : il a sa place ici, alors qu'il n'entre jamais dans
     * `PlanFlattener`, l'ICS ni une page publique.
     *
     * @param list<ScheduledWorkout>    $scheduled déjà triées par date croissante
     * @param array<int, SessionRating> $ratings   ressenti effectif par séance (`ratingsFor()`)
     */
    public function schedule(array $scheduled, string $periodLabel, array $ratings = []): Spreadsheet
    {
        $this->loadPalette();

        // Une mise à plat par séance, partagée par la liste (activité dominante)
        // et le détail (programme). Clé objet : une séance non persistée n'a pas
        // encore d'identifiant.
        $flats = [];
        $byStatus = [];
        foreach ($scheduled as $item) {
            $workout = $item->getWorkout();
            if (null !== $workout) {
                $flats[spl_object_id($item)] = $this->planFlattener->flattenWorkout($workout);
            }
            $byStatus[$item->getStatus()->value] = ($byStatus[$item->getStatus()->value] ?? 0) + 1;
        }

        $spreadsheet = $this->newSpreadsheet('Planning · '.$periodLabel);

        // --- Onglet 1 : la liste datée.
        $list = $spreadsheet->getActiveSheet();
        $list->setTitle('Planning');
        $this->layout($list, self::SCHEDULE_COLUMNS);

        $meta = $this->plural(\count($scheduled), 'séance');
        foreach ([ScheduledStatus::DONE, ScheduledStatus::MISSED, ScheduledStatus::PLANNED] as $status) {
            $count = $byStatus[$status->value] ?? 0;
            if ($count > 0) {
                $meta .= ' · '.$this->plural($count, mb_strtolower($status->getLabel()));
            }
        }
        $row = $this->writeHeading($list, 1, 'Planning', $periodLabel, $meta, null, self::SCHEDULE_COLUMNS);

        if ([] === $scheduled) {
            $this->mergedText($list, $row, self::SCHEDULE_COLUMNS, 'Aucune séance sur cette période.');
            $list->getStyle('A'.$row)->getFont()->setItalic(true)->getColor()->setARGB(self::TEXT_FAINT);
        } else {
            $headerRow = $row;
            $this->writeColumnHeaders($list, $row, self::SCHEDULE_HEADERS);
            ++$row;

            foreach ($scheduled as $item) {
                $date = $item->getScheduledDate();
                $flat = $flats[spl_object_id($item)] ?? null;
                $rating = $ratings[(int) $item->getId()] ?? null;

                // La date reste une vraie date : triable et filtrable dans le tableur.
                // Alignée à gauche, comme un libellé : à droite, elle collerait au jour.
                $list->setCellValueExplicit('A'.$row, ExcelDate::dateTimeToExcel($date), DataType::TYPE_NUMERIC);
                $list->getStyle('A'.$row)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                $list->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                $this->text($list, 'B'.$row, self::DAY_NAMES[(int) $date->format('N')]);

                $this->text($list, 'C'.$row, $item->getDisplayTitle());
                $list->getStyle('C'.$row)->getFont()->setBold(true)->getColor()
                    ->setARGB($this->activityColor($flat['activities'] ?? []) ?? self::INK);

                $this->text($list, 'D'.$row, $item->getSourcePlanTemplate()?->getTitle());

                $this->text($list, 'E'.$row, $item->getStatus()->getLabel());
                $list->getStyle('E'.$row)->getFont()->setBold(true)->getColor()->setARGB($this->statusColor($item->getStatus()));

                $this->text($list, 'F'.$row, $rating?->feelingLabel());
                if (null !== $rating?->sessionRpe) {
                    $list->setCellValueExplicit('G'.$row, $rating->sessionRpe, DataType::TYPE_NUMERIC);
                    $list->getStyle('G'.$row)->getNumberFormat()->setFormatCode('0"/10"');
                }
                $this->text($list, 'H'.$row, $item->getCompletionNotes());

                $this->rowRule($list, $row, 'H');
                ++$row;
            }

            $tableRange = 'A'.$headerRow.':H'.($row - 1);
            $list->getStyle($tableRange)->getAlignment()->setWrapText(true);
            $list->getStyle('G'.($headerRow + 1).':G'.($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $list->setAutoFilter($tableRange);
            $list->freezePane('A'.($headerRow + 1));
            $this->preparePrint($list, PageSetup::ORIENTATION_LANDSCAPE, 'Planning · '.$periodLabel, $headerRow);
        }

        // --- Onglet 2 : le programme de chaque séance qui en a un.
        if ([] !== $flats) {
            $detail = $spreadsheet->createSheet();
            $detail->setTitle('Séances');
            $this->layout($detail, self::PROGRAM_COLUMNS);

            $row = 1;
            foreach ($scheduled as $item) {
                $flat = $flats[spl_object_id($item)] ?? null;
                if (null === $flat) {
                    continue;
                }

                // La ligne de contexte prend la couleur du statut : faite, manquée
                // (rouge, l'échec), prévue (encre estompée).
                $date = $item->getScheduledDate();
                $context = $this->upper(self::DAY_NAMES[(int) $date->format('N')].' '.$date->format('d/m/Y').' · '.$item->getStatus()->getLabel());

                $row = $this->writeSession($detail, $row, $context, $this->statusColor($item->getStatus()), $flat, $item->getDisplayTitle());
            }

            $this->preparePrint($detail, PageSetup::ORIENTATION_PORTRAIT, 'Planning · '.$periodLabel);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    // ------------------------------------------------------------ Programme

    /**
     * Une séance dans un onglet de détail : ligne de contexte (jour, date,
     * statut…), titre dans la couleur de l'activité dominante, programme.
     * Renvoie la ligne libre suivante, respiration comprise.
     *
     * @param FlatWorkout $flat
     */
    private function writeSession(Worksheet $sheet, int $row, string $context, string $contextColor, array $flat, ?string $title = null): int
    {
        $this->mergedText($sheet, $row, self::PROGRAM_COLUMNS, $context, 0.9);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(9)->getColor()->setARGB($contextColor);
        ++$row;

        $this->mergedText($sheet, $row, self::PROGRAM_COLUMNS, $title ?? $flat['workout']->getTitle() ?? 'Séance', 1.3);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(13)->getColor()
            ->setARGB($this->activityColor($flat['activities']) ?? self::INK);
        ++$row;

        return $this->writeProgram($sheet, $row, $flat) + 1;
    }

    /**
     * Le programme d'une séance : une ligne d'en-têtes, puis chaque bloc.
     *
     * @param FlatWorkout $flat
     */
    private function writeProgram(Worksheet $sheet, int $row, array $flat): int
    {
        if ([] === $flat['blocks']) {
            $this->mergedText($sheet, $row, self::PROGRAM_COLUMNS, 'Séance vide.');
            $sheet->getStyle('A'.$row)->getFont()->setItalic(true)->getColor()->setARGB(self::TEXT_FAINT);

            return $row + 1;
        }

        $this->writeColumnHeaders($sheet, $row, self::PROGRAM_HEADERS);
        ++$row;

        foreach ($flat['blocks'] as $index => $flatBlock) {
            $row = $this->writeBlock($sheet, $row, $index + 1, $flatBlock);
        }

        return $row;
    }

    /**
     * Un bloc : sa ligne de tête (numéro, rôle, libellé, tours), puis ses
     * segments. Un groupe lié (superset, circuit) s'annonce par une ligne à lui,
     * comme sur la page de la séance ; ses membres gardent leur rang (« A1 »).
     *
     * @param FlatBlock $flatBlock
     */
    private function writeBlock(Worksheet $sheet, int $row, int $number, array $flatBlock): int
    {
        $block = $flatBlock['block'];
        $role = $block->getRole();

        // Le rôle et les tours en capitales, le libellé saisi tel quel.
        $heading = sprintf('%02d  %s', $number, $this->upper($role?->getLabel() ?? 'Bloc'));
        $label = $block->getLabel();
        if (null !== $label && '' !== $label) {
            $heading .= ' · '.$label;
        }
        if ($block->getRounds() > 1) {
            $heading .= ' · × '.$block->getRounds().' TOURS';
        }

        $this->mergedText($sheet, $row, self::PROGRAM_COLUMNS, $heading);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true);
        $sheet->getStyle('A'.$row.':E'.$row)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::FILL_SUBTLE);
        $sheet->getStyle('A'.$row)->getBorders()->getLeft()
            ->setBorderStyle(Border::BORDER_THICK)->getColor()->setARGB(self::ROLE_RAIL[$role->value ?? 'main']);
        ++$row;

        if ([] === $flatBlock['exercises']) {
            $this->mergedText($sheet, $row, self::PROGRAM_COLUMNS, 'Aucun exercice.');
            $sheet->getStyle('A'.$row)->getFont()->setItalic(true)->getColor()->setARGB(self::TEXT_FAINT);

            return $row + 1;
        }

        foreach ($flatBlock['segments'] as $segment) {
            $grouped = null !== $segment['label'];
            if ($grouped) {
                $kind = 'circuit' === $segment['kind'] ? 'Circuit' : 'Superset';
                $this->mergedText($sheet, $row, self::PROGRAM_COLUMNS, $kind.' '.$segment['label'].' · enchaîné sans repos', 0.9);
                $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(9)->getColor()->setARGB(self::TEXT_SECONDARY);
                ++$row;
            }

            foreach ($segment['exercises'] as $flatExercise) {
                $this->writeExercise($sheet, $row, $flatExercise, $grouped);
                ++$row;
            }
        }

        return $row;
    }

    /**
     * Une ligne d'exercice. La prescription est la vue condensée du flattener
     * (`values`, sans le RPE) : le RPE et le repos ont leur colonne, en nombres,
     * pour rester triables. Une série détaillée se lit regroupée (« 3× 8 reps
     * @ 100 kg · Drop set… »), comme dans l'aperçu au survol.
     *
     * @param FlatPrescribed $flat
     */
    private function writeExercise(Worksheet $sheet, int $row, array $flat, bool $grouped): void
    {
        $name = $this->naming->label($flat['exercise']);
        if (null !== $flat['groupLabel']) {
            $name = $flat['groupLabel'].' · '.$name;
        }

        $this->text($sheet, 'A'.$row, $name);
        $this->text($sheet, 'B'.$row, $flat['values']);

        $rpe = $flat['prescribed']->getRpe();
        if (null !== $rpe) {
            $sheet->setCellValueExplicit('C'.$row, $rpe, DataType::TYPE_NUMERIC);
            if ($rpe >= self::HARD_RPE) {
                $sheet->getStyle('C'.$row)->getFont()->setBold(true)->getColor()->setARGB(self::ACCENT);
            }
        }

        if (null !== $flat['rest']) {
            $sheet->setCellValueExplicit('D'.$row, $flat['rest'], DataType::TYPE_NUMERIC);
            $sheet->getStyle('D'.$row)->getNumberFormat()->setFormatCode('0" s"');
        }

        $this->text($sheet, 'E'.$row, $flat['notes']);

        $sheet->getStyle('A'.$row.':E'.$row)->getAlignment()->setWrapText(true);
        $sheet->getStyle('C'.$row.':D'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('E'.$row)->getFont()->getColor()->setARGB(self::TEXT_SECONDARY);
        if ($grouped) {
            $sheet->getStyle('A'.$row)->getAlignment()->setIndent(1);
        }
        $this->rowRule($sheet, $row, 'E');
    }

    // -------------------------------------------------------------- En-têtes

    /**
     * En-tête d'onglet : surtitre en capitales, titre, ligne de repères,
     * description. Renvoie la ligne libre suivante, respiration comprise.
     *
     * @param non-empty-array<string, int> $columns
     */
    private function writeHeading(Worksheet $sheet, int $row, string $eyebrow, string $title, ?string $meta, ?string $description, array $columns): int
    {
        $this->mergedText($sheet, $row, $columns, $this->upper($eyebrow), 0.9);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(9)->getColor()->setARGB(self::TEXT_FAINT);
        ++$row;

        $this->mergedText($sheet, $row, $columns, $title, 1.8);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(18)->getColor()->setARGB(self::INK);
        ++$row;

        if (null !== $meta && '' !== $meta) {
            $this->mergedText($sheet, $row, $columns, $this->upper($meta), 0.9);
            $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(9)->getColor()->setARGB(self::TEXT_SECONDARY);
            ++$row;
        }

        if (null !== $description && '' !== $description) {
            $this->mergedText($sheet, $row, $columns, $description);
            $sheet->getStyle('A'.$row)->getFont()->getColor()->setARGB(self::TEXT_SECONDARY);
            ++$row;
        }

        return $row + 1;
    }

    /**
     * Repères d'une séance, ceux du hero de sa page : activités, durée
     * estimée, nombre de blocs et d'exercices.
     *
     * @param FlatWorkout $flat
     */
    private function workoutMeta(array $flat): string
    {
        $parts = array_map(static fn (ActivityType $activity): string => $activity->getLabel(), $flat['activities']);

        $minutes = $flat['workout']->getEstimatedDurationMinutes();
        if (null !== $minutes && $minutes > 0) {
            $parts[] = $minutes.' min';
        }
        $parts[] = $this->plural(\count($flat['blocks']), 'bloc');
        $parts[] = $this->plural($flat['exerciseCount'], 'exercice');

        return implode(' · ', $parts);
    }

    /**
     * Bandeau de semaine dans le détail d'un plan : un filet d'encre dessous,
     * comme une tête de rubrique.
     */
    private function writeWeekBanner(Worksheet $sheet, int $row, int $weekNumber, int $count): int
    {
        $this->mergedText($sheet, $row, self::PROGRAM_COLUMNS, 'SEMAINE '.$weekNumber.' · '.$this->upper($this->plural($count, 'séance')), 1.2);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A'.$row.':E'.$row)->getBorders()->getBottom()
            ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB(self::INK);

        return $row + 2;
    }

    /**
     * Ligne d'en-têtes de tableau : aplat d'encre, capitales, comme le tableau
     * de séries de la page séance.
     *
     * @param list<string> $labels
     */
    private function writeColumnHeaders(Worksheet $sheet, int $row, array $labels): void
    {
        foreach ($labels as $index => $label) {
            $this->text($sheet, Coordinate::stringFromColumnIndex($index + 1).$row, $this->upper($label));
        }

        $range = 'A'.$row.':'.Coordinate::stringFromColumnIndex(\count($labels)).$row;
        $style = $sheet->getStyle($range);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::INK);
        $style->getFont()->setBold(true)->setSize(9)->getColor()->setARGB(self::ON_INK);
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension($row)->setRowHeight(18);
    }

    // -------------------------------------------------------------- Socle

    private function newSpreadsheet(string $title): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('Kadens')
            ->setLastModifiedBy('Kadens')
            ->setTitle($title);

        $default = $spreadsheet->getDefaultStyle();
        $default->getFont()->setName(self::FONT)->setSize(10)->getColor()->setARGB(self::INK);
        $default->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        return $spreadsheet;
    }

    /**
     * @param array<string, int> $columns
     */
    private function layout(Worksheet $sheet, array $columns): void
    {
        foreach ($columns as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->setShowGridlines(false);
    }

    /**
     * A4 à la largeur de la page, pied de page « Kadens · titre » et numéro de
     * page. Une ligne d'en-têtes de tableau, si on la donne, se répète en haut
     * de chaque page imprimée.
     */
    private function preparePrint(Worksheet $sheet, string $orientation, string $label, ?int $repeatRow = null): void
    {
        $sheet->getPageSetup()
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation($orientation)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.5)->setBottom(0.6)->setLeft(0.4)->setRight(0.4);
        // `&` ouvre un code d'en-tête Excel : un titre qui en contient le double.
        $sheet->getHeaderFooter()->setOddFooter('&L&8Kadens · '.str_replace('&', '&&', $label).'&R&8&P / &N');

        if (null !== $repeatRow) {
            $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($repeatRow, $repeatRow);
        }
    }

    /**
     * Texte sur toute la largeur de l'onglet. Une cellule fusionnée ne
     * s'ajuste jamais en hauteur dans Excel : la hauteur est estimée ici, sans
     * quoi une description longue serait coupée à la première ligne.
     *
     * @param non-empty-array<string, int> $columns
     * @param float                        $scale   taille de police relative au texte courant
     */
    private function mergedText(Worksheet $sheet, int $row, array $columns, string $value, float $scale = 1.0): void
    {
        $this->text($sheet, 'A'.$row, $value);
        $sheet->mergeCells('A'.$row.':'.array_key_last($columns).$row);
        $sheet->getStyle('A'.$row)->getAlignment()->setWrapText(true);

        $lines = $this->lineCount($value, array_sum($columns) / $scale);
        if ($lines > 1 || $scale > 1.0) {
            $sheet->getRowDimension($row)->setRowHeight($lines * self::LINE_HEIGHT * max(1.0, $scale));
        }
    }

    /**
     * Écrit une saisie utilisateur en chaîne explicite, jamais interprétée :
     * ni formule (`=…`), ni nombre, ni date. Une valeur vide laisse la cellule
     * vide.
     */
    private function text(Worksheet $sheet, string $coordinate, ?string $value): void
    {
        if (null === $value || '' === $value) {
            return;
        }

        $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
    }

    /**
     * Un fragment de texte enrichi. Chaque fragment porte sa police complète :
     * un fragment sans police retomberait sur celle d'Excel, pas sur celle du
     * classeur.
     */
    private function run(RichText $text, string $value, string $color, bool $bold = false, bool $italic = false, float $size = 10): void
    {
        $font = (new Font())->setName(self::FONT)->setSize($size)->setBold($bold)->setItalic($italic);
        $font->getColor()->setARGB($color);
        $text->createTextRun($value)->setFont($font);
    }

    /** Filet fin sous une ligne de tableau. */
    private function rowRule(Worksheet $sheet, int $row, string $lastColumn): void
    {
        $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->getBorders()->getBottom()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::BORDER);
    }

    // ------------------------------------------------------------- Dérivés

    private function loadPalette(): void
    {
        $user = $this->security->getUser();
        $this->colors = $this->activityPalette->forUser($user instanceof User ? $user : null);
    }

    /**
     * Couleur de l'activité dominante (la première du classement), en ARGB.
     *
     * @param list<ActivityType> $activities
     */
    private function activityColor(array $activities): ?string
    {
        $dominant = $activities[0] ?? null;
        $hex = null !== $dominant ? ($this->colors[$dominant->value] ?? null) : null;

        return null !== $hex ? 'FF'.strtoupper(ltrim($hex, '#')) : null;
    }

    /** Fait en couleur de statut, manqué en rouge (échec), prévu en encre estompée. */
    private function statusColor(ScheduledStatus $status): string
    {
        return match ($status) {
            ScheduledStatus::DONE => self::STATUS_DONE,
            ScheduledStatus::MISSED => self::ACCENT,
            ScheduledStatus::PLANNED => self::TEXT_FAINT,
        };
    }

    /**
     * @param list<array{dayOfWeek: int, items: list<mixed>}> $days
     */
    private function weekSessionCount(array $days): int
    {
        $count = 0;
        foreach ($days as $day) {
            $count += \count($day['items']);
        }

        return $count;
    }

    private function plural(int $count, string $noun): string
    {
        return $count.' '.$noun.($count > 1 ? 's' : '');
    }

    private function upper(string $value): string
    {
        return mb_strtoupper($value);
    }

    /** Nombre de lignes qu'occupe un texte dans une largeur donnée (en caractères). */
    private function lineCount(string $text, float $widthChars): int
    {
        $width = max(1.0, $widthChars * 0.95);
        $lines = 0;
        foreach (preg_split('/\R/u', $text) ?: [$text] as $line) {
            $lines += max(1, (int) ceil(mb_strlen($line) / $width));
        }

        return max(1, $lines);
    }

    /**
     * Nettoie et tronque un nom d'onglet Excel (max 31 car., caractères
     * `* : / \ ? [ ]` interdits).
     */
    private function sheetTitle(string $title): string
    {
        $clean = preg_replace('/[*:\/\\\\?\[\]]/', ' ', $title) ?? $title;

        return mb_substr(trim($clean), 0, 31) ?: 'Feuille';
    }
}
