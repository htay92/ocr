<?php

namespace App\Attendance\Data;

use App\Attendance\Support\HolidayCalendar;

/**
 * One employee and period as read from a file (spec 3.7), before saving.
 * Summary values missing from the file are computed from the daily rows
 * (save rule 5); summarySource lists the ones that came from the file.
 */
final class ParsedSheet
{
    public const MINUTE_KINDS = ['worked', 'overtime', 'late_night', 'legal_holiday', 'legal_holiday_late_night'];

    /** Work types that count as a day worked, and as paid leave (half days count 0.5, A-07). */
    public const WORKED = ['出勤' => 1.0, '遅刻' => 1.0, '早退' => 1.0, '午前半休' => 0.5, '午後半休' => 0.5];

    public const PAID_LEAVE = ['有給休暇' => 1.0, '午前半休' => 0.5, '午後半休' => 0.5];

    public const WORK_TYPES = ['出勤', '欠勤', '有給休暇', '午前半休', '午後半休', '遅刻', '早退', '代休', '夏季休暇'];

    /**
     * @param  array<string, mixed>  $summary  values read from the file (keys as attendance_sheets columns)
     * @param  list<ParsedRecord>  $records
     * @param  ?array<string, int>  $totals  the 合計 row, minutes per kind (only for A-05)
     * @param  list<string>  $warnings
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
    ) {
        $this->summarySource = array_keys($summary);
        $this->summary = array_merge($this->computedSummary(), $summary);
    }

    /** @var list<string> summary keys that were read from the file */
    public readonly array $summarySource;

    public function fromFile(string $key): bool
    {
        return in_array($key, $this->summarySource, true);
    }

    public function sum(string $kind): int
    {
        return array_sum(array_map(fn (ParsedRecord $record) => $record->minutes($kind), $this->records));
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
        ];
    }

    private function computedSummary(): array
    {
        $scheduledDays = HolidayCalendar::workingDays($this->periodStart, $this->periodEnd);
        $summary = [
            // No 所定時間 in the file: scheduled days × 8 hours.
            'scheduled_minutes' => $scheduledDays * 480,
            'scheduled_days' => $scheduledDays,
            'worked_days' => $this->countedDays(self::WORKED),
            'paid_leave_taken_days' => $this->countedDays(self::PAID_LEAVE),
            'paid_leave_remaining_days' => null,
            'work_type_counts' => $this->countedWorkTypes(),
        ];
        foreach (self::MINUTE_KINDS as $kind) {
            $summary["{$kind}_minutes"] = $this->sum($kind);
        }

        return $summary;
    }
}
