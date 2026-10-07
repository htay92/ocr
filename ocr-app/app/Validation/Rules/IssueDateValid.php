<?php

namespace App\Validation\Rules;

use App\Validation\RuleResult;
use App\Validation\ValidationContext;
use Illuminate\Support\Carbon;

/** V-02: issue date exists, is a valid date, not in the future, not older than 10 years (critical). */
class IssueDateValid extends Rule
{
    protected function code(): string
    {
        return 'V-02';
    }

    public function check(ValidationContext $context): RuleResult
    {
        $value = $context->document->get('issue_date');
        if ($value === null) {
            return $this->fail(RuleResult::CRITICAL, 'Issue date not found');
        }

        $date = Carbon::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return $this->fail(RuleResult::CRITICAL, "Issue date {$value} is not a valid date");
        }
        if ($date->isAfter(today())) {
            return $this->fail(RuleResult::CRITICAL, "Issue date {$value} is in the future");
        }
        if ($date->isBefore(today()->subYears(10))) {
            return $this->fail(RuleResult::CRITICAL, "Issue date {$value} is older than 10 years");
        }

        return $this->pass("Issue date {$value}");
    }
}
