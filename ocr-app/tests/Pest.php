<?php

use App\Models\ImportProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

// Unit tests also boot the app, so config(), Http::fake() and Sleep::fake() work.
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Unit');

/**
 * A POST /ocr response in the spec 3.2 contract shape.
 *
 * @param  list<array{0: string, 1: float}>  $lines  text and confidence, top to bottom
 */
function ocrResponse(array $lines = [['ローソン', 0.98], ['合計 ¥1,280', 0.96]], array $overrides = []): array
{
    return array_merge([
        'engine' => 'paddle',
        'engine_version' => '3.7.0 / PP-OCRv5',
        'elapsed_ms' => 2340,
        'image' => ['width' => 1240, 'height' => 2480, 'rotation' => 0],
        'lines' => array_map(fn (array $line, int $i) => [
            'text' => $line[0],
            'confidence' => $line[1],
            'bbox' => [[102, 100 + $i * 50], [640, 100 + $i * 50], [640, 140 + $i * 50], [102, 140 + $i * 50]],
        ], $lines, array_keys($lines)),
        'preprocessed_image' => null,
    ], $overrides);
}

/** A temporary PNG of the given size; deleted when the test ends. */
function tempImage(int $width = 200, int $height = 100): string
{
    $path = tempnam(sys_get_temp_dir(), 'ocr').'.png';
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imagepng($image, $path);
    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

/**
 * A temporary PDF. Each page is a list of text lines (a born-digital PDF with a
 * text layer), or an empty list for a page with only a drawn box, like a scan.
 *
 * @param  list<list<string>>  $pages
 */
function pdfFile(array $pages): string
{
    $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $kids = [];

    foreach ($pages as $lines) {
        $stream = $lines === []
            ? '0 0 0 RG 4 w 72 72 400 600 re S'
            : "BT /F1 14 Tf 72 720 Td 18 TL\n".implode('', array_map(fn ($line) => '('.addcslashes($line, '()\\').") Tj T*\n", $lines)).'ET';
        $pageId = count($objects) + 1;
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents '.($pageId + 1).' 0 R /Resources << /Font << /F1 3 0 R >> >> >>';
        $objects[$pageId + 1] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        $kids[] = "{$pageId} 0 R";
    }
    $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $id => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
    }
    $xref = strlen($pdf);
    $size = count($objects) + 1;
    $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer << /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

    $path = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
    file_put_contents($path, $pdf);
    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

function hasPoppler(): bool
{
    return trim((string) shell_exec('command -v pdftotext')) !== '' && trim((string) shell_exec('command -v pdftoppm')) !== '';
}

/** The real 勤務データ sample PDF (git-ignored) and its expected values (git-ignored .json) are both present. */
function hasSamplePdf(): bool
{
    return hasPoppler() && is_file(base_path('tests/fixtures/attendance/kintai-2026-10-sample.pdf'))
        && is_file(base_path('tests/fixtures/attendance/kintai-2026-10-sample.json'));
}

/** The employee name printed in the real sample PDF; kept out of git (CLAUDE.md: no personal data). */
function samplePdfName(): string
{
    return json_decode((string) file_get_contents(base_path('tests/fixtures/attendance/kintai-2026-10-sample.json')), true)['employee_name'];
}

/**
 * OCR lines of a receipt that passes every critical check (V-01 total, V-02 date).
 *
 * @return list<array{0: string, 1: float}>
 */
function validReceiptLines(): array
{
    return [['ローソン', 0.98], ['2026年10月1日 12:00', 0.97], ['合計 ¥1,280', 0.96]];
}

/**
 * Synthetic attendance month (no personal data): 山田 花子, E001, October 2026.
 * Weekdays 09:00–18:00 with a 1 h break, except 10/6 (until 19:30), 10/14 (until
 * 22:30, 30 min late night), 10/20 paid leave, 10/27 morning half day, and the
 * public holiday 10/12. Totals: worked 162:00, overtime 6:00, late night 0:30.
 *
 * @return list<array{date: string, day: string, type: string, start: string, end: string, break: string, worked: string, overtime: string, late: string, legal: string, legal_late: string, memo: string, confirmed: string}>
 */
function kintaiMonth(): array
{
    $weekday = ['日', '月', '火', '水', '木', '金', '土'];
    $rows = [];
    for ($d = 1; $d <= 31; $d++) {
        $time = mktime(0, 0, 0, 10, $d, 2026);
        $row = ['date' => sprintf('2026/10/%02d', $d), 'day' => "10/{$d}(".$weekday[(int) date('w', $time)].')',
            'type' => '', 'start' => '', 'end' => '', 'break' => '', 'worked' => '', 'overtime' => '', 'late' => '',
            'legal' => '', 'legal_late' => '', 'memo' => '', 'confirmed' => ''];
        if ((int) date('N', $time) <= 5 && $d !== 12) {
            $row = match ($d) {
                6 => [...$row, 'type' => '出勤', 'start' => '09:00', 'end' => '19:30', 'break' => '01:00', 'worked' => '09:30', 'overtime' => '01:30'],
                14 => [...$row, 'type' => '出勤', 'start' => '09:00', 'end' => '22:30', 'break' => '01:00', 'worked' => '12:30', 'overtime' => '04:30', 'late' => '00:30'],
                20 => [...$row, 'type' => '有給休暇'],
                27 => [...$row, 'type' => '午前半休', 'start' => '14:00', 'end' => '18:00', 'break' => '00:00', 'worked' => '04:00'],
                default => [...$row, 'type' => '出勤', 'start' => '09:00', 'end' => '18:00', 'break' => '01:00', 'worked' => '08:00'],
            };
            $row['confirmed'] = '✓';
        }
        if ($d === 1) {
            $row['memo'] = '定例 会議';
        }
        $rows[] = $row;
    }

    return $rows;
}

/**
 * kintaiMonth() as pdftotext -bbox-layout words [text, xMin, yMin, xMax, yMax],
 * at the positions of the real 勤務データ PDF (TCPDF, A4).
 *
 * @return list<array{0: string, 1: float, 2: float, 3: float, 4: float}>
 */
function kintaiPdfWords(string $name = '山田 花子'): array
{
    $words = [];
    $word = function (string $text, float $x, float $y, float $width = 16) use (&$words) {
        $words[] = [$text, $x, $y, $x + $width, $y + 6];
    };
    [$family, $given] = explode(' ', $name);
    $word("【勤務データ】{$family}", 341.6, 34.8, 60);
    $word("{$given}_2026年10月1日~2026年10月31日", 410, 34.8, 150);

    $block = function (float $labelY, float $valueY, array $pairs) use ($word) {
        foreach ($pairs as [$x, $label, $value]) {
            $word($label, $x, $labelY, 24);
            $word($value, $x, $valueY);
        }
    };
    $word('勤務時間項目', 31.2, 51.8, 42);
    $block(66.5, 79.2, [[31.2, '所定時間', '168:00'], [102.0, '労働時間', '162:00'], [172.9, '時間外', '06:00'], [243.8, '深夜', '00:30'], [314.6, '法定休日', '00:00'], [385.5, '法定休日深夜', '00:00']]);
    $word('勤務日数・休憩項目', 31.2, 94.3, 63);
    $block(109.0, 121.7, [[31.2, '所定出勤日', '21'], [102.0, '出勤日数', '19.5日'], [172.9, '有休取得日数', '1.5日'], [243.8, '有休残日数', '24.5日']]);
    $word('勤務区分項目', 31.2, 136.8, 42);
    $counts = ['出勤' => 19, '欠勤' => 0, '有給休暇' => 1, '午前半休' => 1, '午後半休' => 0, '遅刻' => 0, '早退' => 0, '代休' => 0, '夏季休暇' => 0];
    $x = 31.2;
    foreach ($counts as $label => $count) {
        $block(151.5, 164.2, [[$x, $label, (string) $count]]);
        $x += 56.7;
    }

    $columns = ['日付' => [40.4, 50.4], '勤務区分' => [75.8, 95.8], '開始' => [121.2, 131.2], '終了' => [158.9, 168.9],
        '休憩時間' => [191.6, 211.6], '労働時間' => [229.3, 249.3], '時間外' => [269.5, 284.5], '深夜' => [309.7, 319.7],
        '法定休日' => [342.4, 362.4], '法定休日深夜' => [377.8, 407.8], 'メモ' => [452.5, 462.5], '確定' => [527.9, 537.9]];
    foreach ($columns as $label => [$x1, $x2]) {
        $words[] = [$label, $x1, 182.7, $x2, 187.7];
    }
    $centre = fn (string $column) => ($columns[$column][0] + $columns[$column][1]) / 2;
    $keys = ['勤務区分' => 'type', '開始' => 'start', '終了' => 'end', '休憩時間' => 'break', '労働時間' => 'worked',
        '時間外' => 'overtime', '深夜' => 'late', '法定休日' => 'legal', '法定休日深夜' => 'legal_late', '確定' => 'confirmed'];
    foreach (kintaiMonth() as $i => $day) {
        $y = 196.0 + 13.25 * $i;
        $words[] = [$day['day'], 33.7, $y, 57.2, $y + 5];
        foreach ($keys as $column => $key) {
            if ($day[$key] !== '') {
                $words[] = [$day[$key], $centre($column) - 8, $y, $centre($column) + 8, $y + 5];
            }
        }
        foreach (array_filter(explode(' ', $day['memo'])) as $j => $part) {
            $words[] = [$part, 440 + $j * 20, $y, 455 + $j * 20, $y + 5];
        }
    }

    $words[] = ['合計', 80.8, 606.7, 90.8, 611.7];
    $totals = [[153.9, '所定時間', 160.6, '168:00'], [191.6, '休憩時間', 201.4, '19:00'], [229.3, '労働時間', 239.1, '162:00'],
        [269.5, '時間外', 276.8, '06:00'], [309.7, '深夜', 314.5, '00:30'], [342.4, '法定休日', 352.2, '00:00'], [377.8, '法定休日深夜', 395.3, '00:00']];
    foreach ($totals as [$labelX, $label, $valueX, $value]) {
        $words[] = [$label, $labelX, 606.7, $labelX + 20, 611.7];
        $words[] = [$value, $valueX, 620.0, $valueX + 16, 625.0];
    }

    return $words;
}

/**
 * kintaiMonth() as rows of the standard export (profile kintai-standard), with a 合計 row.
 *
 * @return list<list<string>>
 */
function kintaiRows(string $name = '山田 花子', string $code = 'E001', bool $totals = true): array
{
    $rows = [['氏名', '社員番号', '日付', '勤務区分', '開始', '終了', '休憩時間', '労働時間', '時間外', '深夜', '法定休日', '法定休日深夜', 'メモ', '確定']];
    foreach (kintaiMonth() as $day) {
        $rows[] = [$name, $code, $day['date'], $day['type'], $day['start'], $day['end'], $day['break'], $day['worked'],
            $day['overtime'], $day['late'], $day['legal'], $day['legal_late'], $day['memo'], $day['confirmed']];
    }
    if ($totals) {
        $rows[] = [$name, $code, '合計', '', '', '', '19:00', '162:00', '06:00', '00:30', '00:00', '00:00', '', ''];
    }

    return $rows;
}

/** Write rows as a CSV file (UTF-8 with BOM, or Shift-JIS) and return its path. */
function kintaiCsv(array $rows, string $encoding = 'UTF-8', string $path = ''): string
{
    $path = $path ?: tempnam(sys_get_temp_dir(), 'kintai').'.csv';
    $handle = fopen('php://temp', 'w+');
    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    file_put_contents($path, $encoding === 'UTF-8' ? "\u{FEFF}".$csv : mb_convert_encoding($csv, 'SJIS-win', 'UTF-8'));

    return $path;
}

/** Write rows as an XLSX file and return its path. */
function kintaiXlsx(array $rows, string $path = ''): string
{
    $path = $path ?: tempnam(sys_get_temp_dir(), 'kintai').'.xlsx';
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    foreach ($rows as $r => $row) {
        foreach ($row as $c => $value) {
            $sheet->setCellValueExplicit([$c + 1, $r + 1], $value, DataType::TYPE_STRING);
        }
    }
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

/**
 * Synthetic copy of the 出勤簿（自動計算） layout (no personal data): year B3 / month B4,
 * header row 7, day numbers in A, Excel time values, 区分 出勤 / 有給 / -, 合計 row 39,
 * formula summary cells G3 (出勤日) and G4 (有給日), name cell I4 left empty.
 * January 2026: 1/2 10:00–24:00 (2:00 late night), 1/5 and 1/7 8 h, 1/6 paid leave.
 */
function shukkinboXlsx(): string
{
    $book = new Spreadsheet;
    $s = $book->getActiveSheet()->setTitle('出勤簿');
    $s->fromArray([['出勤簿（自動計算）']], null, 'A1');
    $s->fromArray([['年', 2026, '所定始業', null, 0.375, '出勤日', '=COUNTA(C8:C38)', '所属']], null, 'A3');
    $s->fromArray([['月', 1, '所定終業', null, 0.75, '有給日', '=COUNTIF(J8:J38,"有給")', '氏名']], null, 'A4');
    $s->fromArray([[null, null, '休憩時間', null, '1:00']], null, 'A5');
    $s->fromArray([['日', '曜日', '出勤', '退勤', '休憩', '実働', '時間外', '深夜', '休日', '区分']], null, 'A7');
    $weekday = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $days = [2 => [10 / 24, 1.0, 1 / 24, 13 / 24, 5 / 24, 2 / 24], 5 => [8 / 24, 17 / 24, 1 / 24, 8 / 24, 0, null], 7 => [9 / 24, 18 / 24, 1 / 24, 8 / 24, 0, null]];
    for ($d = 1; $d <= 31; $d++) {
        $row = 7 + $d;
        $s->setCellValue("A{$row}", $d);
        $s->setCellValue("B{$row}", $weekday[(int) date('w', mktime(0, 0, 0, 1, $d, 2026))]);
        if (isset($days[$d])) {
            foreach (['C', 'D', 'E', 'F', 'G', 'H'] as $i => $col) {
                if ($days[$d][$i] !== null) {
                    $s->setCellValue("{$col}{$row}", $days[$d][$i]);
                    $s->getStyle("{$col}{$row}")->getNumberFormat()->setFormatCode('h:mm');
                }
            }
        }
        $s->setCellValue("J{$row}", isset($days[$d]) ? '出勤' : ($d === 6 ? '有給' : '-'));
    }
    $s->fromArray([['合計', null, null, null, null, 29 / 24, 5 / 24, 2 / 24, 0]], null, 'A39');
    foreach (['F39', 'G39', 'H39', 'I39'] as $cell) {
        $s->getStyle($cell)->getNumberFormat()->setFormatCode('[h]:mm');
    }
    $s->setCellValue('A41', '1. 年月、所定時間を入力します');
    $path = tempnam(sys_get_temp_dir(), 'shukkinbo').'.xlsx';
    (new Xlsx($book))->save($path);   // formulas saved with their results

    return $path;
}

/**
 * Synthetic copy of the 作業報告書 layout (no personal data): year A1 / month C1, name A4
 * (below 名前), summary C4 / E4 / G4, header row 6, day numbers in A, 稼働時間 in decimal
 * hours, 区分 稼働 / 休日, no break column, plus a free column 作業内容 (H).
 * November 2025: 18 working days (11/3 and 11/24 are holidays), 10:00–19:00, 11/12 until 19:15.
 */
function sagyouHoukokushoXlsx(string $name = '田中'): string
{
    $book = new Spreadsheet;
    $s = $book->getActiveSheet()->setTitle("{$name}202511");
    $s->fromArray([[2025, '年', 11, '月度 勤務表 兼 作業報告書']], null, 'A1');
    $s->fromArray([['名前', null, '出勤日数（日）', null, '休日日数（日）', null, '稼働時間（時間）', null, '精算時間（時間）']], null, 'A3');
    $s->fromArray([[$name, null, '=COUNTIF(C7:C36,"稼働")', null, '=COUNTIF(C7:C36,"休日")', null, '=SUM(F7:F36)']], null, 'A4');
    $s->fromArray([['就業日', null, '区分', '始業時間', '終業時間', '稼働時間', '備考', '作業内容']], null, 'A6');
    $weekday = ['日', '月', '火', '水', '木', '金', '土'];
    for ($d = 1; $d <= 30; $d++) {
        $row = 6 + $d;
        $time = mktime(0, 0, 0, 11, $d, 2025);
        $working = (int) date('N', $time) <= 5 && ! in_array($d, [3, 24], true);
        $s->setCellValue("A{$row}", $d);
        $s->setCellValue("B{$row}", $weekday[(int) date('w', $time)]);
        $s->setCellValue("C{$row}", $working ? '稼働' : '休日');
        if ($working) {
            $s->setCellValue("D{$row}", 10 / 24);
            $s->setCellValue("E{$row}", $d === 12 ? (19.25 / 24) : (19 / 24));
            $s->setCellValue("F{$row}", $d === 12 ? 8.25 : 8.0);
            $s->getStyle("D{$row}:E{$row}")->getNumberFormat()->setFormatCode('h:mm');
            $s->getStyle("F{$row}")->getNumberFormat()->setFormatCode('0.0_);[Red]\(0.0\)');
            $s->setCellValue("H{$row}", 'システム開発');
        }
        if ($d === 12) {
            $s->setCellValue("G{$row}", '客先作業');
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'sagyou').'.xlsx';
    (new Xlsx($book))->save($path);

    return $path;
}

/** Templates as the setting page would save them for the two layouts above. */
function shukkinboTemplate(): ImportProfile
{
    return ImportProfile::create([
        'name' => '出勤簿（自動計算）', 'kind' => 'spreadsheet', 'header_row' => 7,
        'settings' => [
            'header_row' => 7, 'year_cell' => 'B3', 'month_cell' => 'B4', 'name_source' => 'cell', 'name_cell' => 'I4',
            'columns' => [
                ['key' => 'c1', 'title' => '日', 'letter' => 'A', 'type' => 'day', 'role' => 'work_day'],
                ['key' => 'c3', 'title' => '出勤', 'letter' => 'C', 'type' => 'time', 'role' => 'start_time'],
                ['key' => 'c4', 'title' => '退勤', 'letter' => 'D', 'type' => 'time', 'role' => 'end_time'],
                ['key' => 'c5', 'title' => '休憩', 'letter' => 'E', 'type' => 'duration', 'role' => 'break_minutes'],
                ['key' => 'c6', 'title' => '実働', 'letter' => 'F', 'type' => 'duration', 'role' => 'worked_minutes'],
                ['key' => 'c7', 'title' => '時間外', 'letter' => 'G', 'type' => 'duration', 'role' => 'overtime_minutes'],
                ['key' => 'c8', 'title' => '深夜', 'letter' => 'H', 'type' => 'duration', 'role' => 'late_night_minutes'],
                ['key' => 'c9', 'title' => '休日', 'letter' => 'I', 'type' => 'duration', 'role' => 'legal_holiday_minutes'],
                ['key' => 'c10', 'title' => '区分', 'letter' => 'J', 'type' => 'work_type', 'role' => 'work_type'],
            ],
            'work_types' => ['出勤' => '出勤', '有給' => '有給休暇', '-' => null],
            'summary' => [
                ['title' => '出勤日', 'cell' => 'G3', 'type' => 'days', 'role' => 'worked_days'],
                ['title' => '有給日', 'cell' => 'G4', 'type' => 'days', 'role' => 'paid_leave_taken_days'],
            ],
            'totals_label' => '合計',
        ],
        'match' => ['anchors' => ['出勤簿（自動計算）'], 'headers' => ['日', '曜日', '出勤', '退勤', '休憩', '実働', '時間外', '深夜', '休日', '区分']],
    ]);
}

function sagyouTemplate(): ImportProfile
{
    return ImportProfile::create([
        'name' => '作業報告書', 'kind' => 'spreadsheet', 'header_row' => 6,
        'settings' => [
            'header_row' => 6, 'year_cell' => 'A1', 'month_cell' => 'C1', 'name_source' => 'cell', 'name_cell' => 'A4', 'break_default' => 60,
            'columns' => [
                ['key' => 'c1', 'title' => '就業日', 'letter' => 'A', 'type' => 'day', 'role' => 'work_day'],
                ['key' => 'c3', 'title' => '区分', 'letter' => 'C', 'type' => 'work_type', 'role' => 'work_type'],
                ['key' => 'c4', 'title' => '始業時間', 'letter' => 'D', 'type' => 'time', 'role' => 'start_time'],
                ['key' => 'c5', 'title' => '終業時間', 'letter' => 'E', 'type' => 'time', 'role' => 'end_time'],
                ['key' => 'c6', 'title' => '稼働時間', 'letter' => 'F', 'type' => 'duration', 'role' => 'worked_minutes', 'unit' => 'hours'],
                ['key' => 'c7', 'title' => '備考', 'letter' => 'G', 'type' => 'text', 'role' => 'memo'],
                ['key' => 'c8', 'title' => '作業内容', 'letter' => 'H', 'type' => 'text', 'role' => null],
            ],
            'work_types' => ['稼働' => '出勤', '休日' => null],
            'summary' => [
                ['title' => '出勤日数（日）', 'cell' => 'C4', 'type' => 'days', 'role' => 'worked_days'],
                ['title' => '休日日数（日）', 'cell' => 'E4', 'type' => 'days', 'role' => null],
                ['title' => '稼働時間（時間）', 'cell' => 'G4', 'type' => 'duration', 'unit' => 'hours', 'role' => 'worked_minutes'],
            ],
            'totals_label' => null,
        ],
        'match' => ['anchors' => ['月度勤務表兼作業報告書'], 'headers' => ['就業日', '区分', '始業時間', '終業時間', '稼働時間', '備考']],
    ]);
}
