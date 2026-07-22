<?php

namespace App\Service;

use App\Entity\PlanTemplate;
use App\Entity\ScheduledWorkout;
use App\Entity\Workout;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export .xlsx d'une séance, d'un plan ou d'un planning daté (Phase 8).
 *
 * Consomme la sortie « plate » de {@see PlanFlattener} : la mise à plat n'est
 * jamais réimplémentée ici. Grâce aux unités normalisées en base (kg/m/s), le
 * champ `summary` du flattener porte déjà le rendu lisible (mm:ss, allure,
 * distance) ; l'export se contente de le poser dans les cellules.
 *
 * Couleurs alignées sur l'identité « Carnet clair » (voir assets/styles/tokens.css) :
 * terracotta pour les en-têtes, olive pour les entêtes de bloc. Les tokens CSS
 * ne s'appliquant pas à un classeur Excel, les valeurs sont reprises en dur ici,
 * en ARGB.
 */
final class ExcelExporter
{
    private const COLOR_INK = 'FF1A1712';        // --kd-ink-900
    private const COLOR_TERRACOTTA = 'FFB7532E'; // --kd-terracotta
    private const COLOR_OLIVE_TINT = 'FFEEF1E3';  // --kd-olive-tint
    private const COLOR_OLIVE_TEXT = 'FF4C5730';  // --kd-olive-label
    private const COLOR_PAPER = 'FFFFFDF9';       // --kd-paper-0
    private const COLOR_BORDER = 'FFE6E0D4';      // --kd-border-cell

    /** Table détaillée d'une séance : 4 colonnes A..D. */
    private const LAST_COLUMN = 'D';

    /** Noms de jours FR indexés en ISO (1=lundi..7=dimanche). */
    private const DAY_NAMES = [
        1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi',
        5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche',
    ];

    public function __construct(private readonly PlanFlattener $planFlattener)
    {
    }

    /**
     * Classeur d'une séance seule : un onglet, le détail bloc par bloc.
     */
    public function workout(Workout $workout): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetTitle($workout->getTitle() ?? 'Séance'));

        $this->layoutColumns($sheet);
        $row = $this->writeWorkoutSection($sheet, 1, $this->planFlattener->flattenWorkout($workout), []);

        $this->finalizeSheet($sheet, $row - 1);

        return $spreadsheet;
    }

    /**
     * Classeur d'un plan multi-semaines : un onglet, les séances listées
     * semaine par semaine puis jour par jour (grille dense du flattener,
     * les cases vides étant simplement sautées).
     */
    public function planTemplate(PlanTemplate $template): Spreadsheet
    {
        $flat = $this->planFlattener->flattenPlanTemplate($template);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetTitle($template->getTitle() ?? 'Plan'));
        $this->layoutColumns($sheet);

        $row = $this->writeTitle($sheet, 1, $template->getTitle() ?? 'Plan', $template->getDescription());

        foreach ($flat['weeks'] as $week) {
            $hasItems = false;
            foreach ($week['days'] as $day) {
                foreach ($day['items'] as $item) {
                    if (!$hasItems) {
                        $row = $this->writeBanner($sheet, $row, 'Semaine '.$week['weekNumber']);
                        $hasItems = true;
                    }

                    $meta = self::DAY_NAMES[$day['dayOfWeek']]
                        .(null !== $item['item']->getNotes() && '' !== $item['item']->getNotes()
                            ? ' · '.$item['item']->getNotes()
                            : '');
                    $row = $this->writeWorkoutSection($sheet, $row, $item['workout'], [$meta]);
                }
            }
        }

        $this->finalizeSheet($sheet, $row - 1);

        return $spreadsheet;
    }

    /**
     * Classeur d'un planning daté sur une période : un onglet, chaque séance
     * datée avec sa date réelle et son statut prévu/fait/manqué.
     *
     * @param list<ScheduledWorkout> $scheduled déjà triées par date croissante
     */
    public function schedule(array $scheduled, string $periodLabel): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetTitle('Planning'));
        $this->layoutColumns($sheet);

        $row = $this->writeTitle($sheet, 1, 'Planning', $periodLabel);

        foreach ($scheduled as $item) {
            $workout = $item->getWorkout();
            if (null === $workout) {
                continue;
            }

            $meta = ucfirst($item->getScheduledDate()->format('d/m/Y'))
                .' · '.($item->getStatus()?->getLabel() ?? '');
            if (null !== $item->getCompletionNotes() && '' !== $item->getCompletionNotes()) {
                $meta .= ' · '.$item->getCompletionNotes();
            }

            $row = $this->writeWorkoutSection($sheet, $row, $this->planFlattener->flattenWorkout($workout), [$meta]);
        }

        $this->finalizeSheet($sheet, $row - 1);

        return $spreadsheet;
    }

    /**
     * Écrit une séance (titre + éventuelles lignes de contexte + blocs) à partir
     * de la ligne `$row`. Renvoie la ligne libre suivante.
     *
     * @param array{workout: Workout, blocks: array<int, array{block: \App\Entity\Block, exercises: array<int, array<string, mixed>>}>} $flatWorkout
     * @param list<string> $metaLines lignes de contexte affichées au-dessus du titre (date, jour, statut…)
     */
    private function writeWorkoutSection(Worksheet $sheet, int $row, array $flatWorkout, array $metaLines): int
    {
        $workout = $flatWorkout['workout'];

        foreach ($metaLines as $meta) {
            $sheet->setCellValue('A'.$row, $meta);
            $sheet->mergeCells('A'.$row.':'.self::LAST_COLUMN.$row);
            $sheet->getStyle('A'.$row)->getFont()->setBold(true)->getColor()->setARGB(self::COLOR_TERRACOTTA);
            ++$row;
        }

        $row = $this->writeTitle($sheet, $row, $workout->getTitle() ?? 'Séance', null, false);

        foreach ($flatWorkout['blocks'] as $flatBlock) {
            $row = $this->writeBlock($sheet, $row, $flatBlock);
        }

        // Ligne vide de respiration entre deux séances.
        return $row + 1;
    }

    /**
     * @param array{block: \App\Entity\Block, exercises: array<int, array<string, mixed>>} $flatBlock
     */
    private function writeBlock(Worksheet $sheet, int $row, array $flatBlock): int
    {
        $block = $flatBlock['block'];

        // En-tête de bloc : rôle, tours si >1, label éventuel.
        $heading = $block->getRole()?->getLabel() ?? '';
        if (($block->getRounds() ?? 1) > 1) {
            $heading .= ' · '.$block->getRounds().' tours';
        }
        if (null !== $block->getLabel() && '' !== $block->getLabel()) {
            $heading .= ' · '.$block->getLabel();
        }

        $sheet->setCellValue('A'.$row, $heading);
        $sheet->mergeCells('A'.$row.':'.self::LAST_COLUMN.$row);
        $sheet->getStyle('A'.$row.':'.self::LAST_COLUMN.$row)
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_OLIVE_TINT);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->getColor()->setARGB(self::COLOR_OLIVE_TEXT);
        ++$row;

        // En-têtes de colonnes.
        $sheet->fromArray(['Exercice', 'Prescription', 'Repos', 'Notes'], null, 'A'.$row);
        $sheet->getStyle('A'.$row.':'.self::LAST_COLUMN.$row)->getFont()->setBold(true);
        $sheet->getStyle('A'.$row.':'.self::LAST_COLUMN.$row)
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_TERRACOTTA);
        $sheet->getStyle('A'.$row.':'.self::LAST_COLUMN.$row)->getFont()->getColor()->setARGB(self::COLOR_PAPER);
        ++$row;

        foreach ($flatBlock['exercises'] as $flat) {
            $sheet->fromArray([
                $flat['exercise']?->getName() ?? '(exercice supprimé)',
                $flat['summary'],
                $this->formatDuration($flat['rest']),
                $flat['notes'] ?? '',
            ], null, 'A'.$row);
            ++$row;
        }

        return $row;
    }

    /**
     * Titre (grand, terracotta) avec sous-titre optionnel. En début de classeur
     * (`$primary`) le titre est plus imposant qu'un titre de séance imbriqué.
     */
    private function writeTitle(Worksheet $sheet, int $row, string $title, ?string $subtitle, bool $primary = true): int
    {
        $sheet->setCellValue('A'.$row, $title);
        $sheet->mergeCells('A'.$row.':'.self::LAST_COLUMN.$row);
        $sheet->getStyle('A'.$row)->getFont()
            ->setBold(true)->setSize($primary ? 16 : 13)
            ->getColor()->setARGB(self::COLOR_TERRACOTTA);
        ++$row;

        if (null !== $subtitle && '' !== $subtitle) {
            $sheet->setCellValue('A'.$row, $subtitle);
            $sheet->mergeCells('A'.$row.':'.self::LAST_COLUMN.$row);
            $sheet->getStyle('A'.$row)->getFont()->setItalic(true)->getColor()->setARGB(self::COLOR_INK);
            ++$row;
        }

        return $row + 1;
    }

    /**
     * Bandeau de section (ex. « Semaine 2 »), sur toute la largeur.
     */
    private function writeBanner(Worksheet $sheet, int $row, string $label): int
    {
        $sheet->setCellValue('A'.$row, $label);
        $sheet->mergeCells('A'.$row.':'.self::LAST_COLUMN.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(13)->getColor()->setARGB(self::COLOR_INK);

        return $row + 2;
    }

    private function layoutColumns(Worksheet $sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(34);
        $sheet->getColumnDimension('B')->setWidth(30);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(40);
        $sheet->setShowGridlines(false);
    }

    /**
     * Bordures fines sur toute la zone remplie et alignement haut/retour ligne.
     */
    private function finalizeSheet(Worksheet $sheet, int $lastRow): void
    {
        if ($lastRow < 1) {
            return;
        }

        $range = 'A1:'.self::LAST_COLUMN.$lastRow;
        $sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::COLOR_BORDER);
        $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
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

    /**
     * Secondes -> mm:ss (ou h:mm:ss au-delà d'une heure) ; chaîne vide si null.
     * Duplique le format lisible côté PlanFlattener pour le repos (champ transverse,
     * absent du `summary`).
     */
    private function formatDuration(?int $seconds): string
    {
        if (null === $seconds) {
            return '';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }
}
