<?php

namespace App\Validation\Attendance;

use App\Attendance\Data\ParsedSheet;
use App\Validation\RuleResult;

/** A-02: worked minutes = clock-out − clock-in − break, within 1 minute (warning). */
class WorkedMatchesClock extends AttendanceRule
{
    public function code(): string
    {
        return 'A-02';
    }

    public function check(ParsedSheet $sheet): RuleResult
    {
        $bad = [];
        $checked = 0;
        foreach ($sheet->records as $record) {
            if ($record->start === null || $record->end === null) {
                continue;
            }
            $checked++;
            $span = (self::minutes($record->end) - self::minutes($record->start) + 1440) % 1440;
            if (abs($span - $record->breakMinutes - $record->workedMinutes) > 1) {
                $bad[] = $record->sourceRow;
            }
        }

        if ($checked === 0) {
            return $this->skip(__('No clock times'));
        }

        return $bad === []
            ? $this->pass(__('Worked time matches clock times on :count days', ['count' => $checked]))
            : $this->fail(RuleResult::WARNING, __('Worked time does not match clock-out − clock-in − break (rows :rows)', ['rows' => self::rows($bad)]));
    }

    private static function minutes(string $clock): int
    {
        [$hours, $minutes] = explode(':', $clock);

        return (int) $hours * 60 + (int) $minutes;
    }
}
