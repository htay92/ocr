<?php

namespace App\Attendance\Data;

use App\Attendance\Support\HolidayCalendar;

/**
 * One employee and period as read from a file (spec 3.7), before saving.
 *
 * columns are the format's own columns (free per company); a standard total is
 * only computed when the format has a column for it. Summary values missing from
 * the file are computed from the daily rows (save rule 5); summarySource lists
 * the ones that came from the file; summaryValues are the file's own summary
 * items with their titles.
 */
final class ParsedSheet
{
    public const MINUTE_KINDS = ['worked', 'overtime', 'late_night', 'legal_holiday', 'legal_holiday_late_night'];

    /** Work types that count as a day worked, and as paid leave (half days count 0.5, A-07). */
    public const WORKED = ['出勤' => 1.0, '遅刻' => 1.0, '早退' => 1.0, '午前半休' => 0.5, '午後半休' => 0.5];

    public const PAID_LEAVE = ['有給休暇' => 1.0, '午前半休' => 0.5, '午後半休' => 0.5];

    public const WORK_TYPES = ['出勤', '欠勤', '有給休暇', '午前半休', '午後半休', '遅刻', '早退', '代休', '夏季休暇'];

    /** @var list<string> summary keys that were read from the file */
    public readonly array $summarySource;

    /**
     * @param  array<string, mixed>  $summary  values read from the file (keys as attendance_sheets columns)
     * @param  list<ParsedRecord>  $records
     * @param  ?array<string, int>  $totals  the 合計 row, minutes per kind (only for A-05)
     * @param  list<string>  $warnings
     * @param  list<array{key: string, title: string, type: string, role: ?string, unit?: string}>  $columns
     * @param  list<array{title: string, value: mixed, type: string, role: ?string}>  $summaryValues
     */
    public function __construct(
        public readonly string $employeeName,
        public readonly ?string $employeeCode,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public array $summary,
        public readonly array $records,
        public readonly ?array $totals = null,
        public array $warnings = [],
        public readonly array $columns = [],
        public readonly array $summaryValues = [],
        public readonly bool $needsName = false,
    ) {
        $this->summarySource = array_keys($summary);
        $this->summary = array_merge($this->computedSummary(), $summary);
    }

    /** Whether the format has a column for this role (spec 3.7 field). Without columns: all. */
    public function has(string $role): bool
    {
        if ($this->columns === []) {
            return true;
        }
        foreach ($this->columns as $column) {
            if (($column['role'] ?? null) === $role) {
                return true;
            }
        }

        return false;
    }

    public function fromFile(string $key): bool
    {
        return in_array($key, $this->summarySource, true);
    }

    /** Sum of a kind over the days, or null when the format has no such column. */
    public function sum(string $kind): ?int
    {
        if (! $this->has("{$kind}_minutes")) {
            return null;
        }

        return array_sum(array_map(fn (ParsedRecord $record) => $record->minutes($kind) ?? 0, $this->records));
    }

    /** @return array<string, int> rows per work type */
    public function countedWorkTypes(): array
    {
        $counts = array_fill_keys(self::WORK_TYPES, 0);
        foreach ($this->records as $record) {
            if ($record->workType !== null) {
                $counts[$record->workType] = ($counts[$record->workType] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function countedDays(array $weights): float
    {
        return array_sum(array_map(fn (ParsedRecord $record) => $weights[$record->workType] ?? 0.0, $this->records));
    }

    /** A copy with the employee's name (and code) entered on import. */
    public function withEmployee(string $name, ?string $code): self
    {
        return new self($name, $code ?: $this->employeeCode, $this->periodStart, $this->periodEnd,
            array_intersect_key($this->summary, array_flip($this->summarySource)), $this->records, $this->totals,
            $this->warnings, $this->columns, $this->summaryValues, false);
    }

    /** Attributes for attendance_sheets. */
    public function toArray(): array
    {
        return [
            'employee_name' => $this->employeeName,
            'employee_code' => $this->employeeCode,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            ...$this->summary,
            'summary_source' => $this->summarySource,
            'columns' => $this->columns ?: null,
            'summary_values' => $this->summaryValues ?: null,
        ];
    }

    private function computedSummary(): array
    {
        $scheduledDays = HolidayCalendar::workingDays($this->periodStart, $this->periodEnd);
        $hasTypes = $this->has('work_type');
        $summary = [
            // No 所定時間 in the file: scheduled days × 8 hours.
            'scheduled_minutes' => $scheduledDays * 480,
            'scheduled_days' => $scheduledDays,
            'worked_days' => $hasTypes ? $this->countedDays(self::WORKED) : null,
            'paid_leave_taken_days' => $hasTypes ? $this->countedDays(self::PAID_LEAVE) : null,
            'paid_leave_remaining_days' => null,
            'work_type_counts' => $hasTypes ? $this->countedWorkTypes() : null,
        ];
        foreach (self::MINUTE_KINDS as $kind) {
            $summary["{$kind}_minutes"] = $this->sum($kind);
        }

        return $summary;
    }
}
