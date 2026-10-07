<?php

namespace App\Validation\Rules;

use App\Validation\RuleResult;
use App\Validation\ValidationContext;

/** V-01: total exists and is greater than 0 (critical). */
class TotalExists extends Rule
{
    protected function code(): string
    {
        return 'V-01';
    }

    public function check(ValidationContext $context): RuleResult
    {
        $total = $context->document->get('total');

        return $total !== null && $total > 0
            ? $this->pass('Total '.self::yen($total))
            : $this->fail(RuleResult::CRITICAL, $total === null ? 'Total not found' : 'Total is '.self::yen($total));
    }
}
