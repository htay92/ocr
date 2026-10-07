<?php

namespace App\Validation\Attendance;

use App\Attendance\Data\ParsedSheet;
use App\Validation\RuleResult;
use DateTimeImmutable;

/** A-06: every date of the period appears exactly once (critical). */
class EveryDateOnce extends AttendanceRule
{
    public function code(): string
    {
        return 'A-06';
    }

    public function check(ParsedSheet $sheet): RuleResult
    {
        $dates = array_count_values(array_map(fn ($record) => $record->date, $sheet->records));
        $missing = [];
        $days = 0;
        for ($day = new DateTimeImmutable($sheet->periodStart); $day <= new DateTimeImmutable($sheet->periodEnd); $day = $day->modify('+1 day')) {
            $days++;
            if (! isset($dates[$day->format('Y-m-d')])) {
                $missing[] = $day->format('n/j');
            }
        }

        return $missing === []
            ? $this->pass(__('All :count days of the period are present', ['count' => $days]))
            : $this->fail(RuleResult::CRITICAL, __('Missing days: :days', ['days' => self::rows($missing)]));
    }
}
