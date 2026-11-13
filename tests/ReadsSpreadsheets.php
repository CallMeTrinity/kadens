<?php

namespace App\Tests;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Relire un export Excel comme le ferait un tableur.
 *
 * Pourquoi un endroit unique : un .xlsx est une archive zip. Chercher une valeur
 * dans le corps brut de la réponse ne trouve jamais rien, ce qui fait passer à
 * coup sûr une assertion « ceci n'apparaît pas ». Un test d'export doit donc
 * rouvrir le classeur, et vérifier aussi qu'il y trouve ce qui DOIT y être :
 * c'est ce qui prouve qu'il lit bien quelque chose.
 */
trait ReadsSpreadsheets
{
    /** Rouvre un classeur à partir de ses octets (corps d'une réponse HTTP). */
    private function readSpreadsheet(string $binary): Spreadsheet
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'kadens-xlsx-');
        file_put_contents($path, $binary);

        try {
            return IOFactory::createReader('Xlsx')->load($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * Écrit puis relit un classeur : ce que l'utilisateur ouvrira, et non
     * l'objet en mémoire (le format de fichier peut perdre ce que l'objet a).
     */
    private function roundTrip(Spreadsheet $spreadsheet): Spreadsheet
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'kadens-xlsx-');

        try {
            (new Xlsx($spreadsheet))->save($path);

            return IOFactory::createReader('Xlsx')->load($path);
        } finally {
            unlink($path);
        }
    }

    /** Tout le texte d'un classeur (tous onglets confondus) ou d'un onglet, une cellule par ligne. */
    private function spreadsheetText(Spreadsheet|Worksheet $source): string
    {
        $sheets = $source instanceof Worksheet ? [$source] : $source->getAllSheets();

        $parts = [];
        foreach ($sheets as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    $value = $cell->getValue();
                    if ($value instanceof RichText) {
                        $value = $value->getPlainText();
                    }
                    if (\is_scalar($value) && '' !== (string) $value) {
                        $parts[] = (string) $value;
                    }
                }
            }
        }

        return implode("\n", $parts);
    }
}
