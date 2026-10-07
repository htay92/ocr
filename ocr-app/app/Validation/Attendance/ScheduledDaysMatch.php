<?php

namespace App\Validation\Attendance;

use App\Attendance\Data\ParsedSheet;
use App\Attendance\Support\HolidayCalendar;
use App\Validation\RuleResult;

/** A-08: scheduled days equal the weekdays in the period that are not public holidays (warning). */
class ScheduledDaysMatch extends AttendanceRule
{
    public function code(): string
    {
        return 'A-08';
    }

    public function check(ParsedSheet $sheet): RuleResult
    {
        if (! $sheet->fromFile('scheduled_days')) {
            return $this->skip(__('No scheduled days in the file'));
        }

        $expected = HolidayCalendar::workingDays($sheet->periodStart, $sheet->periodEnd);
        $printed = (int) $sheet->summary['scheduled_days'];

        return $printed === $expected
            ? $this->pass(__('Scheduled days :days = weekdays without public holidays', ['days' => $printed]))
            : $this->fail(RuleResult::WARNING, __('Scheduled days :printed, but the period has :expected weekdays without public holidays', ['printed' => $printed, 'expected' => $expected]));
    }
}
