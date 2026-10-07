<?php

namespace App\Attendance;

use App\Attendance\Data\ParsedRecord;
use App\Attendance\Data\ParsedSheet;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSheet;

/**
 * One shape for showing a sheet, before saving (preview) and after (document page).
 */
final class SheetView
{
    public static function fromParsed(ParsedSheet $sheet): array
    {
        return [
            'employee_name' => $sheet->employeeName,
            'employee_code' => $sheet->employeeCode,
            'period_start' => $sheet->periodStart,
            'period_end' => $sheet->periodEnd,
            'summary' => $sheet->summary,
            'computed' => array_values(array_diff(array_keys($sheet->summary), $sheet->summarySource)),
            'warnings' => $sheet->warnings,
            'records' => array_map(fn (ParsedRecord $r) => $r->toArray(), $sheet->records),
        ];
    }

    public static function fromModel(AttendanceSheet $sheet): array
    {
        $summary = $sheet->only([
            'scheduled_minutes', 'worked_minutes', 'overtime_minutes', 'late_night_minutes', 'legal_holiday_minutes',
            'legal_holiday_late_night_minutes', 'scheduled_days', 'worked_days', 'paid_leave_taken_days',
            'paid_leave_remaining_days', 'work_type_counts',
        ]);

        return [
            'employee_name' => $sheet->employee_name,
            'employee_code' => $sheet->employee_code,
            'period_start' => $sheet->period_start->format('Y-m-d'),
            'period_end' => $sheet->period_end->format('Y-m-d'),
            'summary' => $summary,
            'computed' => array_values(array_diff(array_keys($summary), $sheet->summary_source ?? [])),
            'warnings' => [],
            'records' => $sheet->records->map(fn (AttendanceRecord $r) => [
                ...$r->only(['day_type', 'holiday_name', 'work_type', 'break_minutes', 'worked_minutes', 'overtime_minutes',
                    'late_night_minutes', 'legal_holiday_minutes', 'legal_holiday_late_night_minutes', 'memo', 'confirmed', 'source_row']),
                'work_date' => $r->work_date->format('Y-m-d'),
                'start_time' => $r->start_time ? substr($r->start_time, 0, 5) : null,
                'end_time' => $r->end_time ? substr($r->end_time, 0, 5) : null,
            ])->all(),
        ];
    }
}
