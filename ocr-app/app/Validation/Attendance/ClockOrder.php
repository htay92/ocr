<?php

namespace App\Validation\Attendance;

use App\Attendance\Data\ParsedSheet;
use App\Validation\RuleResult;

/** A-01: clock-out after clock-in; an earlier clock-out is an overnight shift (critical). */
class ClockOrder extends AttendanceRule
{
    public function code(): string
    {
        return 'A-01';
    }

    public function check(ParsedSheet $sheet): RuleResult
    {
        if (! $sheet->has('start_time') || ! $sheet->has('end_time')) {
            return $this->skip(__('No clock-in / clock-out columns in this format'));
        }
        $bad = [];
        $overnight = [];
        foreach ($sheet->records as $record) {
            if (($record->start === null) !== ($record->end === null) || ($record->start !== null && $record->start === $record->end)) {
                $bad[] = $record->sourceRow;
            } elseif ($record->overnight()) {
                $overnight[] = $record->sourceRow;
            }
        }

        if ($bad !== []) {
            return $this->fail(RuleResult::CRITICAL, __('Clock-in or clock-out missing or equal (rows :rows)', ['rows' => self::rows($bad)]));
        }

        return $this->pass($overnight === []
            ? __('Clock-out is after clock-in on every day')
            : __('Clock-out is after clock-in; overnight shifts on rows :rows', ['rows' => self::rows($overnight)]));
    }
}
