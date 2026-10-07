<?php

namespace App\Validation\Attendance;

use App\Attendance\Data\ParsedSheet;
use App\Validation\RuleResult;

/** A-04: no duplicate employee and date (critical). */
class NoDuplicateDates extends AttendanceRule
{
    public function code(): string
    {
        return 'A-04';
    }

    public function check(ParsedSheet $sheet): RuleResult
    {
        $seen = [];
        $duplicates = [];
        foreach ($sheet->records as $record) {
            if (isset($seen[$record->date])) {
                $duplicates[] = $record->sourceRow;
            }
            $seen[$record->date] = true;
        }

        return $duplicates === []
            ? $this->pass(__('No duplicate dates'))
            : $this->fail(RuleResult::CRITICAL, __('The same date appears twice (rows :rows)', ['rows' => self::rows($duplicates)]));
    }
}
