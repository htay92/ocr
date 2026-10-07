<?php

namespace App\Attendance\Parsers;

use App\Attendance\Data\ParsedRecord;
use App\Attendance\Data\ParsedSheet;
use App\Attendance\Support\CellValue;
use App\Attendance\Support\Grid;
use App\Attendance\Template;
use App\Processing\ProcessingException;
use DateTimeImmutable;
use Normalizer;

/**
 * Reads a spreadsheet with a template (spec 3.7, extended):
 * - columns by the file's own title, else by letter;
 * - full dates, or day numbers with year and month from cells (or the file / sheet name);
 * - the format's own work-type words mapped to standard types;
 * - name and summary from cells; the 合計 row kept for A-05;
 * - one sheet per employee when the file has name or code columns.
 */
class TemplateMapper
{
    /**
     * @return array{sheets: list<ParsedSheet>, unknown_columns: list<string>, needs_name: bool}
     *
     * @throws ProcessingException E-IMP-02 when the date or day column, or the month, cannot be found
     */
    public function map(Grid $grid, Template $template, string $fileName, ?string $name = null, ?string $code = null): array
    {
        $header = array_map(fn ($v) => self::clean(Grid::text($v)), $grid->row($template->headerRow));
        $located = $this->locate($template, $header);

        $dateColumn = $located['work_date'] ?? $located['work_day'] ?? null;
        if ($dateColumn === null) {
            $wanted = ($template->column('work_date') ?? $template->column('work_day'))['title'] ?? '日付';
            throw new ProcessingException('E-IMP-02', __('The date column ":column" was not found in row :row.', ['column' => $wanted, 'row' => $template->headerRow]));
        }

        $dayMode = isset($located['work_day']) && ! isset($located['work_date']);
        [$year, $month] = $this->yearMonth($grid, $template, $fileName, $dayMode);

        $usedLetters = array_column($located['_columns'], 'letter');
        $unknown = array_values(array_filter($header, fn ($title, $letter) => $title !== '' && ! in_array($letter, $usedLetters, true), ARRAY_FILTER_USE_BOTH));

        $fileName = pathinfo($fileName, PATHINFO_FILENAME);
        $fixedName = $name ?: match ($template->nameSource) {
            'cell' => Grid::text($grid->cell($template->nameCell)) ?: null,
            'file' => $fileName,
            default => null,
        };
        $fixedCode = $code ?: (Grid::text($grid->cell($template->codeCell)) ?: null);
        $byColumn = isset($located['employee_name']) || isset($located['employee_code']);

        $groups = [];
        foreach ($grid->rows as $number => $cells) {
            if ($number <= $template->headerRow || $cells === []) {
                continue;
            }

            if ($template->totalsLabel !== null && in_array($template->totalsLabel, array_map(fn ($v) => self::clean(Grid::text($v)), $cells), true)) {
                $key = $byColumn ? ($this->text($cells, $located, 'employee_code') ?: $this->text($cells, $located, 'employee_name')) : '_';
                $groups[$key ?: '_']['totals'] = $this->totals($cells, $located, $grid, $number);

                continue;
            }

            $dateValue = $cells[$dateColumn['letter']] ?? null;
            $date = $dayMode
                ? (($day = CellValue::day($dateValue)) !== null && $year && $month && checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null)
                : CellValue::date($dateValue, $year);
            if ($date === null) {
                continue;   // not a day: notes, empty month-end rows, sub-headers
            }

            $employee = $byColumn ? ($this->text($cells, $located, 'employee_name') ?: $fixedName) : $fixedName;
            $employeeCode = $byColumn ? ($this->text($cells, $located, 'employee_code') ?: $fixedCode) : $fixedCode;
            $key = $byColumn ? ($employeeCode ?: $employee ?: '_') : '_';
            $groups[$key] ??= ['totals' => null];
            $groups[$key] += ['name' => $employee, 'code' => $employeeCode, 'rows' => [], 'warnings' => []];
            $groups[$key]['rows'][] = $this->record($date, $cells, $located, $template, $grid, $number, $year, $groups[$key]['warnings']);
        }

        $columns = array_map(fn ($c) => array_intersect_key($c, array_flip(['key', 'title', 'type', 'role', 'unit'])), $located['_columns']);
        [$summary, $summaryValues] = $this->summary($grid, $template);

        $sheets = [];
        foreach ($groups as $group) {
            $records = array_values(array_filter($group['rows'] ?? []));
            if ($records === []) {
                continue;
            }
            usort($records, fn ($a, $b) => $a->date <=> $b->date);
            [$start, $end] = $dayMode
                ? [sprintf('%04d-%02d-01', $year, $month), (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('Y-m-t')]
                : [$records[0]->date, end($records)->date];

            $sheets[] = new ParsedSheet(
                employeeName: (string) ($group['name'] ?? ''),
                employeeCode: $group['code'] ?? null,
                periodStart: $start,
                periodEnd: $end,
                summary: $byColumn ? [] : $summary,
                records: $records,
                totals: $group['totals'],
                warnings: $group['warnings'],
                columns: $columns,
                summaryValues: $byColumn ? [] : $summaryValues,
                needsName: ($group['name'] ?? null) === null,
            );
        }

        return [
            'sheets' => $sheets,
            'unknown_columns' => $unknown,
            'needs_name' => collect($sheets)->contains(fn (ParsedSheet $s) => $s->needsName),
        ];
    }

    /**
     * Template columns found in the file: role => column, plus '_columns' in file order.
     * A column is found by its title in the header row, else by its letter.
     */
    private function locate(Template $template, array $header): array
    {
        $located = ['_columns' => []];
        foreach ($template->columns as $column) {
            $letter = null;
            $title = self::clean($column['title']);
            if ($title !== '' && ($found = array_search($title, $header, true)) !== false) {
                $letter = $found;
            } elseif (! empty($column['letter'])) {
                $letter = strtoupper($column['letter']);
            }
            if ($letter === null) {
                continue;
            }
            $column['letter'] = $letter;
            $column['title'] = $column['title'] !== '' ? $column['title'] : ($header[$letter] ?? $letter);
            $located['_columns'][] = $column;
            if ($column['role'] !== null) {
                $located[$column['role']] = $column;
            }
        }
        usort($located['_columns'], fn ($a, $b) => Grid::index($a['letter']) <=> Grid::index($b['letter']));

        return $located;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function yearMonth(Grid $grid, Template $template, string $fileName, bool $dayMode): array
    {
        $year = CellValue::number($grid->cell($template->yearCell));
        $month = CellValue::number($grid->cell($template->monthCell));

        // Fallbacks: "202511" or "2025年11月" in the sheet or file name.
        foreach ([$grid->sheetName, $fileName] as $text) {
            if (($year === null || $month === null) && preg_match('/(20\d{2})[年_\-\/]?(0[1-9]|1[0-2])(?!\d)/u', (string) $text, $m)) {
                $year ??= (float) $m[1];
                $month ??= (float) $m[2];
            }
        }
        if ($year === null && preg_match('/(20\d{2})/', $fileName, $m)) {
            $year = (float) $m[1];
        }

        if ($dayMode && ($year === null || $month === null || $month < 1 || $month > 12)) {
            throw new ProcessingException('E-IMP-02', __('The year and month of the sheet were not found. Set their cells in the template.'));
        }

        return [$year === null ? (int) date('Y') : (int) $year, $month === null ? null : (int) $month];
    }

    private function record(string $date, array $cells, array $located, Template $template, Grid $grid, int $number, ?int $year, array &$warnings): ParsedRecord
    {
        $values = [];
        foreach ($located['_columns'] as $column) {
            $raw = $cells[$column['letter']] ?? null;
            $value = CellValue::normalized($raw, $column['type'], $column['unit'] ?? 'auto', $grid->format($column['letter'].$number), $year);
            if ($raw !== null && Grid::text($raw) !== '' && $value === null && in_array($column['type'], ['time', 'duration', 'date', 'day', 'number'], true)) {
                $warnings[] = __('Row :row: :column ":value" cannot be read.', ['row' => $number, 'column' => $column['title'], 'value' => Grid::text($raw)]);
            }
            // Work types keep the file's own word (稼働); the standard type (出勤) is the role's value.
            $values[$column['key']] = $column['type'] === 'work_type' ? (Grid::text($raw) ?: null) : $value;
        }

        $role = fn (string $role) => isset($located[$role]) ? $values[$located[$role]['key']] : null;
        $workType = isset($located['work_type']) ? $this->workType(Grid::text($cells[$located['work_type']['letter']] ?? null), $template, $number, $warnings) : null;
        $start = $role('start_time');
        $end = $role('end_time');
        $break = $role('break_minutes');
        if (! isset($located['break_minutes']) && $start !== null && $end !== null && $template->breakDefault !== null) {
            $break = $template->breakDefault;
        }

        return new ParsedRecord(
            date: $date,
            workType: $workType,
            start: $start,
            end: $end,
            breakMinutes: isset($located['break_minutes']) ? ($break ?? 0) : $break,
            workedMinutes: isset($located['worked_minutes']) ? ($role('worked_minutes') ?? 0) : null,
            overtimeMinutes: isset($located['overtime_minutes']) ? ($role('overtime_minutes') ?? 0) : null,
            lateNightMinutes: isset($located['late_night_minutes']) ? ($role('late_night_minutes') ?? 0) : null,
            legalHolidayMinutes: isset($located['legal_holiday_minutes']) ? ($role('legal_holiday_minutes') ?? 0) : null,
            legalHolidayLateNightMinutes: isset($located['legal_holiday_late_night_minutes']) ? ($role('legal_holiday_late_night_minutes') ?? 0) : null,
            memo: $role('memo'),
            confirmed: (bool) $role('confirmed'),
            sourceRow: $number,
            printedWeekday: null,
            values: $values,
        );
    }

    /** The format's own work-type word → a standard type (or none). */
    private function workType(string $value, Template $template, int $number, array &$warnings): ?string
    {
        if ($value === '') {
            return null;
        }
        if (array_key_exists($value, $template->workTypes)) {
            return $template->workTypes[$value] ?: null;
        }
        if (in_array($value, ParsedSheet::WORK_TYPES, true)) {
            return $value;
        }
        $warnings[] = __('Row :row: unknown work type ":value".', ['row' => $number, 'value' => $value]);

        return null;
    }

    /** The 合計 row: minutes per kind, for the columns that have a role. */
    private function totals(array $cells, array $located, Grid $grid, int $number): array
    {
        $totals = [];
        foreach (['break', 'worked', 'overtime', 'late_night', 'legal_holiday', 'legal_holiday_late_night'] as $kind) {
            if (isset($located["{$kind}_minutes"])) {
                $column = $located["{$kind}_minutes"];
                $totals[$kind] = CellValue::duration($cells[$column['letter']] ?? null, $column['unit'] ?? 'auto', $grid->format($column['letter'].$number)) ?? 0;
            }
        }

        return $totals;
    }

    /**
     * Summary cells: standard keys for items with a role, and every item with its title.
     *
     * @return array{0: array<string, mixed>, 1: list<array>}
     */
    private function summary(Grid $grid, Template $template): array
    {
        $summary = [];
        $values = [];
        foreach ($template->summary as $item) {
            $raw = $grid->cell($item['cell'] ?? null);
            if ($raw === null || Grid::text($raw) === '') {
                continue;
            }
            $value = match ($item['type'] ?? 'number') {
                'duration' => CellValue::duration($raw, $item['unit'] ?? 'auto', $grid->format((string) $item['cell'])),
                'days', 'number' => CellValue::number($raw),
                default => Grid::text($raw),
            };
            $values[] = ['title' => $item['title'], 'value' => $value, 'type' => $item['type'] ?? 'number', 'role' => $item['role'] ?? null];
            $role = $item['role'] ?? null;
            if ($role !== null && $value !== null && in_array($role, Template::SUMMARY_ROLES, true)) {
                $summary[$role] = $role === 'scheduled_days' ? (int) $value : $value;
            }
        }

        return [$summary, $values];
    }

    private function text(array $cells, array $located, string $role): ?string
    {
        return isset($located[$role]) ? (Grid::text($cells[$located[$role]['letter']] ?? null) ?: null) : null;
    }

    private static function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', '', Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value), "\u{FEFF}");
    }
}
