<?php

namespace App\Validation;

use App\Attendance\Data\ParsedSheet;

/**
 * A-01..A-08 for one attendance sheet (spec 3.5), run on the parsed file before it is saved.
 */
class AttendanceValidator
{
    private const RULES = [
        Attendance\ClockOrder::class,
        Attendance\WorkedMatchesClock::class,
        Attendance\DatesInPeriod::class,
        Attendance\NoDuplicateDates::class,
        Attendance\TotalsMatch::class,
        Attendance\EveryDateOnce::class,
        Attendance\DayCountsMatch::class,
        Attendance\ScheduledDaysMatch::class,
    ];

    /**
     * @return list<RuleResult>
     */
    public function validate(ParsedSheet $sheet): array
    {
        return array_map(fn (string $rule) => (new $rule)->check($sheet), self::RULES);
    }
}
