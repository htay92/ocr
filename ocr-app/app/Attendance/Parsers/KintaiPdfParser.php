<?php

namespace App\Attendance\Parsers;

use App\Attendance\Data\ParsedRecord;
use App\Attendance\Data\ParsedSheet;
use App\Attendance\Support\TimeValue;
use App\Processing\ProcessingException;
use Illuminate\Support\Facades\Process;

/**
 * The 勤務データ PDF (spec 3.4 "Attendance PDF parsing"). It is generated with a
 * text layer, so it is read from words and their positions, not OCR.
 */
class KintaiPdfParser
{
    private const TITLE = '/【勤務データ】(.+?)_(\d{4})年(\d{1,2})月(\d{1,2})日\s?[~〜～ˆ]\s?(\d{4})年(\d{1,2})月(\d{1,2})日/u';

    private const DATE = '/^\d{1,2}\/\d{1,2}\((月|火|水|木|金|土|日)\)$/u';

    /** Summary labels (spec 3.7) → attendance_sheets columns. */
    private const MINUTES = [
        '所定時間' => 'scheduled', '労働時間' => 'worked', '時間外' => 'overtime', '深夜' => 'late_night',
        '法定休日' => 'legal_holiday', '法定休日深夜' => 'legal_holiday_late_night', '休憩時間' => 'break',
    ];

    private const DAYS = ['所定出勤日' => 'scheduled_days', '出勤日数' => 'worked_days', '有休取得日数' => 'paid_leave_taken_days', '有休残日数' => 'paid_leave_remaining_days'];

    /** Daily table header (spec 3.4 step 4). */
    private const COLUMNS = [
        '日付' => 'date', '勤務区分' => 'work_type', '開始' => 'start', '終了' => 'end', '休憩時間' => 'break',
        '労働時間' => 'worked', '時間外' => 'overtime', '深夜' => 'late_night', '法定休日' => 'legal_holiday',
        '法定休日深夜' => 'legal_holiday_late_night', 'メモ' => 'memo', '確定' => 'confirmed',
    ];

    /**
     * @throws ProcessingException
     */
    public function parse(string $path): ParsedSheet
    {
        $result = Process::run(['pdftotext', '-bbox-layout', $path, '-']);
        if ($result->failed()) {
            throw new ProcessingException('E-PDF-01', 'PDF cannot be opened: '.trim($result->errorOutput()));
        }

        preg_match_all('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">([^<]*)<\/word>/u', $result->output(), $m, PREG_SET_ORDER);
        $words = array_map(fn ($w) => [html_entity_decode($w[5], ENT_QUOTES | ENT_XML1), (float) $w[1], (float) $w[2], (float) $w[3], (float) $w[4]], $m);

        return $this->parseWords($words);
    }

    /**
     * @param  list<array{0: string, 1: float, 2: float, 3: float, 4: float}>  $words  [text, xMin, yMin, xMax, yMax]
     *
     * @throws ProcessingException when this is not a 勤務データ sheet
     */
    public function parseWords(array $words): ParsedSheet
    {
        $lines = self::lines($words);

        // Step 2: title → employee name and period.
        $title = collect($lines)->map(fn ($line) => implode(' ', array_column($line, 0)))->first(fn ($text) => str_contains($text, '【勤務データ】'));
        if ($title === null || ! preg_match(self::TITLE, $title, $t)) {
            throw new ProcessingException('E-IMP-02', 'Not a 勤務データ sheet: the title 【勤務データ】name_start〜end was not found.');
        }
        $start = sprintf('%04d-%02d-%02d', $t[2], $t[3], $t[4]);
        $end = sprintf('%04d-%02d-%02d', $t[5], $t[6], $t[7]);
        $year = (int) $t[2];

        $header = self::findLine($lines, fn ($texts) => in_array('日付', $texts, true) && in_array('勤務区分', $texts, true));
        $totalsLine = self::findLine($lines, fn ($texts) => in_array('合計', $texts, true));
        if ($header === null) {
            throw new ProcessingException('E-IMP-02', 'The daily table header (日付, 勤務区分 …) was not found.');
        }

        // Step 3: summary blocks above the table.
        $summary = [];
        $counts = [];
        foreach (self::labelValuePairs(array_slice($lines, 0, $header)) as $label => $value) {
            match (true) {
                isset(self::MINUTES[$label]) && $label !== '休憩時間' => $summary[self::MINUTES[$label].'_minutes'] = TimeValue::minutes($value) ?? 0,
                $label === '所定出勤日' => $summary['scheduled_days'] = (int) TimeValue::days($value),
                isset(self::DAYS[$label]) => $summary[self::DAYS[$label]] = TimeValue::days($value),
                in_array($label, ParsedSheet::WORK_TYPES, true) => $counts[$label] = (int) $value,
                default => null,
            };
        }
        if ($counts !== []) {
            $summary['work_type_counts'] = array_merge(array_fill_keys(ParsedSheet::WORK_TYPES, 0), $counts);
        }

        // Step 4: daily rows, words assigned to the column whose x-range holds their centre.
        $columns = self::columns($lines[$header]);
        $records = [];
        $warnings = [];
        $last = $totalsLine ?? count($lines);
        for ($i = $header + 1; $i < $last; $i++) {
            $cells = [];
            foreach ($lines[$i] as [$text, $x1, , $x2]) {
                $column = self::columnAt($columns, ($x1 + $x2) / 2);
                $cells[$column] = isset($cells[$column]) ? $cells[$column].' '.$text : $text;
            }
            if (! preg_match(self::DATE, $cells['date'] ?? '')) {
                continue;
            }

            $date = TimeValue::date($cells['date'], $year);
            if ($date === null) {
                $warnings[] = __('Row :row: the date :value cannot be read.', ['row' => count($records) + 1, 'value' => $cells['date']]);

                continue;
            }
            $records[] = new ParsedRecord(
                date: $date,
                workType: ($cells['work_type'] ?? '') ?: null,
                start: TimeValue::clock($cells['start'] ?? null),
                end: TimeValue::clock($cells['end'] ?? null),
                breakMinutes: TimeValue::minutes($cells['break'] ?? null) ?? 0,
                workedMinutes: TimeValue::minutes($cells['worked'] ?? null) ?? 0,
                overtimeMinutes: TimeValue::minutes($cells['overtime'] ?? null) ?? 0,
                lateNightMinutes: TimeValue::minutes($cells['late_night'] ?? null) ?? 0,
                legalHolidayMinutes: TimeValue::minutes($cells['legal_holiday'] ?? null) ?? 0,
                legalHolidayLateNightMinutes: TimeValue::minutes($cells['legal_holiday_late_night'] ?? null) ?? 0,
                memo: ($cells['memo'] ?? '') ?: null,
                confirmed: trim($cells['confirmed'] ?? '') !== '',
                sourceRow: count($records) + 1,
                printedWeekday: TimeValue::printedWeekday($cells['date']),
            );
        }

        // Step 7: the 合計 row, kept only for A-05.
        $totals = null;
        if ($totalsLine !== null) {
            $totals = [];
            foreach (self::labelValuePairs(array_slice($lines, $totalsLine)) as $label => $value) {
                if (isset(self::MINUTES[$label])) {
                    $totals[self::MINUTES[$label]] = TimeValue::minutes($value) ?? 0;
                }
            }
        }

        $name = trim(preg_replace('/\s+/u', ' ', $t[1]));

        return new ParsedSheet($name, null, $start, $end, $summary, $records, $totals, $warnings);
    }

    /**
     * Words grouped into lines (same baseline), left to right.
     *
     * @return list<list<array>>
     */
    private static function lines(array $words): array
    {
        usort($words, fn ($a, $b) => [$a[2], $a[1]] <=> [$b[2], $b[1]]);
        $lines = [];
        foreach ($words as $word) {
            $last = array_key_last($lines);
            if ($last !== null && abs($lines[$last][0][2] - $word[2]) <= 2.0) {
                $lines[$last][] = $word;
            } else {
                $lines[] = [$word];
            }
        }

        return array_map(function (array $line) {
            usort($line, fn ($a, $b) => $a[1] <=> $b[1]);

            return $line;
        }, $lines);
    }

    private static function findLine(array $lines, callable $test): ?int
    {
        foreach ($lines as $i => $line) {
            if ($test(array_column($line, 0))) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Label rows followed by value rows (summary blocks and the 合計 row):
     * each value belongs to the label with the nearest x-position above it.
     *
     * @return array<string, string> label => value
     */
    private static function labelValuePairs(array $lines): array
    {
        $known = array_merge(array_keys(self::MINUTES), array_keys(self::DAYS), ParsedSheet::WORK_TYPES, ['合計']);
        $pairs = [];
        foreach ($lines as $i => $line) {
            $labels = array_values(array_filter($line, fn ($w) => in_array($w[0], $known, true) && $w[0] !== '合計'));
            if ($labels === [] || count($labels) !== count(array_filter($line, fn ($w) => $w[0] !== '合計')) || ! isset($lines[$i + 1])) {
                continue;
            }
            foreach ($lines[$i + 1] as [$value, $x]) {
                $nearest = collect($labels)->sortBy(fn ($label) => abs($label[1] - $x))->first();
                if ($nearest !== null && abs($nearest[1] - $x) < 30) {
                    $pairs[$nearest[0]] = $value;
                }
            }
        }

        return $pairs;
    }

    /**
     * Column x-ranges from the header row: boundaries halfway between label centres.
     *
     * @return list<array{0: string, 1: float}> [field, right boundary]
     */
    private static function columns(array $header): array
    {
        $centres = [];
        foreach ($header as [$text, $x1, , $x2]) {
            if (isset(self::COLUMNS[$text])) {
                $centres[] = [self::COLUMNS[$text], ($x1 + $x2) / 2];
            }
        }
        $columns = [];
        foreach ($centres as $i => [$field, $centre]) {
            $columns[] = [$field, isset($centres[$i + 1]) ? ($centre + $centres[$i + 1][1]) / 2 : INF];
        }

        return $columns;
    }

    private static function columnAt(array $columns, float $x): string
    {
        foreach ($columns as [$field, $right]) {
            if ($x < $right) {
                return $field;
            }
        }

        return end($columns)[0];
    }
}
