<?php

namespace App\Validation\Attendance;

use App\Attendance\Data\ParsedSheet;
use App\Validation\RuleResult;

/**
 * A-07: counts per work type, days worked and paid leave taken match the daily
 * rows; half-day leave counts 0.5 (warning). Skipped when the file has no counts.
 */
class DayCountsMatch extends AttendanceRule
{
    public function code(): string
    {
        return 'A-07';
    }

    public function check(ParsedSheet $sheet): RuleResult
    {
        $differences = [];
        if ($sheet->fromFile('work_type_counts')) {
            foreach ($sheet->countedWorkTypes() as $type => $count) {
                $printed = $sheet->summary['work_type_counts'][$type] ?? 0;
                if ($printed !== $count) {
                    $differences[] = "{$type} {$printed} ≠ {$count}";
                }
            }
        }
        foreach (['worked_days' => [ParsedSheet::WORKED, '出勤日数'], 'paid_leave_taken_days' => [ParsedSheet::PAID_LEAVE, '有休取得日数']] as $key => [$weights, $label]) {
            if ($sheet->fromFile($key) && abs((float) $sheet->summary[$key] - $sheet->countedDays($weights)) > 0.01) {
                $differences[] = "{$label} {$sheet->summary[$key]} ≠ ".$sheet->countedDays($weights);
            }
        }

        if (! $sheet->fromFile('work_type_counts') && ! $sheet->fromFile('worked_days') && ! $sheet->fromFile('paid_leave_taken_days')) {
            return $this->skip(__('No day counts in the file'));
        }

        return $differences === []
            ? $this->pass(__('Day counts match the daily rows'))
            : $this->fail(RuleResult::WARNING, __('Day counts differ from the daily rows: :list', ['list' => implode(', ', $differences)]));
    }
}
