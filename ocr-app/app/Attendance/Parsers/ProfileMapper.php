<?php

namespace App\Attendance\Parsers;

use App\Attendance\Data\ParsedRecord;
use App\Attendance\Data\ParsedSheet;
use App\Attendance\Support\TimeValue;
use App\Models\ImportProfile;
use App\Processing\ProcessingException;
use Normalizer;

/**
 * Applies an import profile to spreadsheet rows (spec 3.7): columns by name or
 * number, one sheet per employee (by 社員番号, else 氏名), the 合計 row kept for
 * A-05, and summary values computed from the daily rows (save rules 4–6).
 */
class ProfileMapper
{
    private const CONFIRMED_MARKS = ['✓', '✔', '済', '〇', '○', '◯', '1', 'true', 'TRUE', 'yes', 'Y'];

    /**
     * @param  array<int, list<string>>  $rows  file row number => cells
     * @return array{sheets: list<ParsedSheet>, unknown_columns: list<string>}
     *
     * @throws ProcessingException E-IMP-02 when the date column is missing
     */
    public function map(array $rows, ImportProfile $profile, string $fileName): array
    {
        $headerRow = max(1, (int) $profile->header_row);
        $header = array_map(fn ($cell) => self::clean($cell), $rows[$headerRow] ?? []);
        $columns = $this->columns($header, $profile->column_map ?? []);

        if (! isset($columns['work_date'])) {
            $wanted = $profile->column_map['work_date'] ?? '日付';
            throw new ProcessingException('E-IMP-02', __('The date column ":column" was not found in row :row.', ['column' => $wanted, 'row' => $headerRow]));
        }

        $used = array_values($columns);
        $unknown = array_values(array_filter($header, fn ($name, $i) => $name !== '' && ! in_array($i, $used, true), ARRAY_FILTER_USE_BOTH));
        $year = preg_match('/(20\d{2})/', $fileName, $m) ? (int) $m[1] : (int) date('Y');

        $groups = [];
        foreach ($rows as $number => $cells) {
            if ($number <= $headerRow || implode('', $cells) === '') {
                continue;
            }
            $cell = fn (string $field) => isset($columns[$field]) ? trim((string) ($cells[$columns[$field]] ?? '')) : '';
            $name = $cell('employee_name') ?: pathinfo($fileName, PATHINFO_FILENAME);
            $key = $cell('employee_code') ?: $name;
            $groups[$key] ??= ['name' => $name, 'code' => $cell('employee_code') ?: null, 'rows' => [], 'totals' => null, 'warnings' => []];

            if (self::clean($cell('work_date')) === '合計') {
                $groups[$key]['totals'] = $this->totals($cell);

                continue;
            }
            $groups[$key]['rows'][] = [$number, $cell];
        }

        $sheets = [];
        foreach ($groups as $group) {
            $records = [];
            $warnings = [];
            foreach ($group['rows'] as [$number, $cell]) {
                $record = $this->record($number, $cell, $year, $warnings);
                if ($record !== null) {
                    $records[] = $record;
                }
            }
            if ($records === []) {
                continue;
            }
            usort($records, fn ($a, $b) => $a->date <=> $b->date);
            $sheets[] = new ParsedSheet($group['name'], $group['code'], $records[0]->date, end($records)->date, [], $records, $group['totals'], $warnings);
        }

        return ['sheets' => $sheets, 'unknown_columns' => $unknown];
    }

    /**
     * System field => column index (0-based), from names or numbers in the profile.
     *
     * @return array<string, int>
     */
    private function columns(array $header, array $map): array
    {
        $columns = [];
        foreach ($map as $field => $wanted) {
            if ($wanted === null || $wanted === '') {
                continue;
            }
            $index = is_int($wanted) || ctype_digit((string) $wanted) ? (int) $wanted - 1 : array_search(self::clean((string) $wanted), $header, true);
            if ($index !== false && $index >= 0) {
                $columns[$field] = $index;
            }
        }

        return $columns;
    }

    private function record(int $number, callable $cell, int $year, array &$warnings): ?ParsedRecord
    {
        $date = TimeValue::date($cell('work_date'), $year);
        if ($date === null) {
            $warnings[] = __('Row :row: the date ":value" cannot be read.', ['row' => $number, 'value' => $cell('work_date')]);

            return null;
        }

        $clock = function (string $field, string $label) use ($cell, $number, &$warnings) {
            $value = $cell($field);
            $clock = TimeValue::clock($value);
            if ($value !== '' && $clock === null) {
                $warnings[] = __('Row :row: :column ":value" cannot be read.', ['row' => $number, 'column' => $label, 'value' => $value]);
            }

            return $clock;
        };
        $minutes = function (string $field, string $label) use ($cell, $number, &$warnings) {
            $value = TimeValue::minutes($cell($field));
            if ($value === null) {
                $warnings[] = __('Row :row: :column ":value" cannot be read.', ['row' => $number, 'column' => $label, 'value' => $cell($field)]);
            }

            return $value ?? 0;
        };

        $workType = $cell('work_type') ?: null;
        if ($workType !== null && ! in_array($workType, ParsedSheet::WORK_TYPES, true)) {
            $warnings[] = __('Row :row: unknown work type ":value".', ['row' => $number, 'value' => $workType]);
        }

        return new ParsedRecord(
            date: $date,
            workType: $workType,
            start: $clock('start_time', '開始'),
            end: $clock('end_time', '終了'),
            breakMinutes: $minutes('break_minutes', '休憩時間'),
            workedMinutes: $minutes('worked_minutes', '労働時間'),
            overtimeMinutes: $minutes('overtime_minutes', '時間外'),
            lateNightMinutes: $minutes('late_night_minutes', '深夜'),
            legalHolidayMinutes: $minutes('legal_holiday_minutes', '法定休日'),
            legalHolidayLateNightMinutes: $minutes('legal_holiday_late_night_minutes', '法定休日深夜'),
            memo: $cell('memo') ?: null,
            confirmed: in_array($cell('confirmed'), self::CONFIRMED_MARKS, true),
            sourceRow: $number,
            printedWeekday: TimeValue::printedWeekday($cell('work_date')),
        );
    }

    /** The 合計 row, minutes per kind (only for A-05). */
    private function totals(callable $cell): array
    {
        $totals = [];
        foreach (['break', 'worked', 'overtime', 'late_night', 'legal_holiday', 'legal_holiday_late_night'] as $kind) {
            $totals[$kind] = TimeValue::minutes($cell("{$kind}_minutes")) ?? 0;
        }

        return $totals;
    }

    private static function clean(?string $value): string
    {
        return trim(Normalizer::normalize((string) $value, Normalizer::FORM_KC) ?: (string) $value, " \t\n\r\0\x0B\u{FEFF}");
    }
}
