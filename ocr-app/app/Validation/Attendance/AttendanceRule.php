<?php

namespace App\Validation\Attendance;

use App\Attendance\Data\ParsedSheet;
use App\Validation\RuleResult;

abstract class AttendanceRule
{
    abstract public function code(): string;

    abstract public function check(ParsedSheet $sheet): RuleResult;

    protected function pass(string $message): RuleResult
    {
        return new RuleResult($this->code(), RuleResult::PASS, $message);
    }

    protected function fail(string $level, string $message): RuleResult
    {
        return new RuleResult($this->code(), $level, $message);
    }

    protected function skip(string $message): RuleResult
    {
        return new RuleResult($this->code(), RuleResult::SKIPPED, $message);
    }

    /** "Rows 3, 5, 9" with the file row numbers, at most 10. */
    protected static function rows(array $rows): string
    {
        $shown = array_slice($rows, 0, 10);

        return implode(', ', $shown).(count($rows) > 10 ? ' …' : '');
    }
}
