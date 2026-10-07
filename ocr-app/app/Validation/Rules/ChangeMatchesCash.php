<?php

namespace App\Validation\Rules;

use App\Validation\RuleResult;
use App\Validation\ValidationContext;

/** V-07: cash received minus total equals change (warning). */
class ChangeMatchesCash extends Rule
{
    protected function code(): string
    {
        return 'V-07';
    }

    public function check(ValidationContext $context): RuleResult
    {
        [$cash, $change, $total] = [$context->document->get('cash_received'), $context->document->get('change'), $context->document->get('total')];

        if ($cash === null || $change === null || $total === null) {
            return $this->skip('No cash and change');
        }

        return $cash - $total === $change
            ? $this->pass(self::yen($cash).' − '.self::yen($total).' = change '.self::yen($change))
            : $this->fail(RuleResult::WARNING, self::yen($cash).' − '.self::yen($total).' = '.self::yen($cash - $total).', change is '.self::yen($change));
    }
}
