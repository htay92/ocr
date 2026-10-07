<?php

namespace App\Validation\Attendance;

use App\Attendance\Data\ParsedSheet;
use App\Validation\RuleResult;

/** A-03: work dates are valid, inside the period, and on the printed weekday (critical). */
class DatesInPeriod extends AttendanceRule
{
    private const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    public function code(): string
    {
        return 'A-03';
    }

    public function check(ParsedSheet $sheet): RuleResult
    {
        $outside = [];
        $weekday = [];
        foreach ($sheet->records as $record) {
            if ($record->date < $sheet->periodStart || $record->date > $sheet->periodEnd) {
                $outside[] = $record->sourceRow;
            }
            if ($record->printedWeekday !== null && $record->printedWeekday !== self::WEEKDAYS[(int) date('w', strtotime($record->date))]) {
                $weekday[] = $record->sourceRow;
            }
        }

        if ($outside !== []) {
            return $this->fail(RuleResult::CRITICAL, __('Dates outside :from – :to (rows :rows)', ['from' => $sheet->periodStart, 'to' => $sheet->periodEnd, 'rows' => self::rows($outside)]));
        }
        if ($weekday !== []) {
            return $this->fail(RuleResult::CRITICAL, __('The printed weekday does not match the date (rows :rows)', ['rows' => self::rows($weekday)]));
        }

        return $this->pass(__('All dates are inside :from – :to', ['from' => $sheet->periodStart, 'to' => $sheet->periodEnd]));
    }
}
