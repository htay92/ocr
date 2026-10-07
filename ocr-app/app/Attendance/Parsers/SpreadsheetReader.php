<?php

namespace App\Attendance\Parsers;

use App\Models\ImportProfile;
use App\Processing\ProcessingException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reads CSV (encoding detected: UTF-8 with or without BOM, else Shift-JIS) and
 * Excel (XLSX, XLS) into rows of strings, keeping the row numbers of the file.
 */
class SpreadsheetReader
{
    /**
     * @return array<int, list<string>> file row number (1-based) => cells
     *
     * @throws ProcessingException E-IMP-01 when the encoding cannot be detected
     */
    public function read(string $path, ImportProfile $profile): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['xlsx', 'xls'], true) ? $this->excel($path) : $this->csv($path, $profile);
    }

    private function csv(string $path, ImportProfile $profile): array
    {
        $content = (string) file_get_contents($path);
        $content = $this->toUtf8($content, $profile->encoding ?: 'auto');

        $handle = fopen('php://temp', 'w+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        $number = 0;
        while (($cells = fgetcsv($handle, null, $profile->delimiter ?: ',', '"', '')) !== false) {
            $number++;
            $rows[$number] = array_map(fn ($cell) => trim((string) $cell), $cells);
        }
        fclose($handle);

        return $rows;
    }

    private function excel(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $rows = [];
        foreach ($sheet->toArray(null, true, true, false) as $i => $cells) {
            $rows[$i + 1] = array_map(fn ($cell) => trim((string) $cell), $cells);
        }

        return $rows;
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
