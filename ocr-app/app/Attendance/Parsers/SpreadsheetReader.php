<?php

namespace App\Attendance\Parsers;

use App\Attendance\Support\Grid;
use App\Attendance\Template;
use App\Processing\ProcessingException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

/**
 * Reads CSV (encoding detected: UTF-8 with or without BOM, else Shift-JIS) and
 * Excel (XLSX, XLS) into a Grid. For Excel, formula cells give the value Excel
 * saved: recalculating would change results (formulas with TODAY()) or fail.
 */
class SpreadsheetReader
{
    /**
     * @throws ProcessingException E-IMP-01 when a CSV's encoding cannot be detected
     */
    public function read(string $path, Template $template, ?string $fileName = null): Grid
    {
        $extension = strtolower(pathinfo($fileName ?? $path, PATHINFO_EXTENSION));

        return in_array($extension, ['xlsx', 'xls'], true)
            ? $this->excel($path, $template->sheet)
            : $this->csv($path, $template->encoding, $template->delimiter);
    }

    private function csv(string $path, string $encoding, string $delimiter): Grid
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, $this->toUtf8((string) file_get_contents($path), $encoding));
        rewind($handle);

        $rows = [];
        $number = 0;
        while (($cells = fgetcsv($handle, null, $delimiter ?: ',', '"', '')) !== false) {
            $number++;
            foreach ($cells as $i => $cell) {
                if (trim((string) $cell) !== '') {
                    $rows[$number][Template::letter($i)] = trim((string) $cell);
                }
            }
            $rows[$number] ??= [];
        }
        fclose($handle);

        return new Grid($rows);
    }

    private function excel(string $path, string $sheet): Grid
    {
        $reader = IOFactory::createReaderForFile($path);
        $book = $reader->load($path);
        $worksheet = match (true) {
            $sheet === 'first' || $sheet === '' => $book->getSheet(0),
            ctype_digit($sheet) => $book->getSheet((int) $sheet - 1),
            default => $book->getSheetByName($sheet) ?? $book->getSheet(0),
        };

        $rows = [];
        $formats = [];
        $lastRow = $worksheet->getHighestDataRow();
        $lastColumn = $worksheet->getHighestDataColumn();
        foreach ($worksheet->getRowIterator(1, $lastRow) as $row) {
            $number = $row->getRowIndex();
            $rows[$number] = [];
            foreach ($row->getCellIterator('A', $lastColumn) as $cell) {
                $value = self::value($cell);
                if ($value === null || $value === '') {
                    continue;
                }
                $rows[$number][$cell->getColumn()] = $value;
                $format = $cell->getStyle()->getNumberFormat()->getFormatCode();
                if ($format !== 'General') {
                    $formats[$cell->getCoordinate()] = $format;
                }
            }
        }

        return new Grid($rows, $formats, $worksheet->getTitle());
    }

    /** The saved value: for formulas, Excel's last calculated result. */
    private static function value(Cell $cell): mixed
    {
        $value = $cell->getValue();
        if ($cell->isFormula()) {
            $value = $cell->getOldCalculatedValue();
        }
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if (is_string($value)) {
            $value = trim($value);
            if (in_array($value, ['#VALUE!', '#N/A', '#REF!', '#DIV/0!', '#NAME?'], true)) {
                return null;
            }
        }

        return $value;
    }

    private function toUtf8(string $content, string $encoding): string
    {
        if (str_starts_with($content, "\u{FEFF}")) {
            return substr($content, 3);
        }
        if ($encoding === 'UTF-8' || ($encoding === 'auto' && mb_check_encoding($content, 'UTF-8'))) {
            return $content;
        }
        if (in_array($encoding, ['auto', 'SJIS-win'], true) && mb_check_encoding($content, 'SJIS-win')) {
            return mb_convert_encoding($content, 'UTF-8', 'SJIS-win');
        }

        throw new ProcessingException('E-IMP-01', __('The file encoding could not be detected. Save the CSV as UTF-8 or Shift-JIS.'));
    }
}
