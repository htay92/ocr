<?php

namespace App\Validation\Rules;

use App\Validation\RuleResult;
use App\Validation\ValidationContext;

/** V-08: sum of 8% lines equals the 8% taxable amount within 1 yen, the same for 10% (warning). */
class LinesMatchTaxableAmounts extends Rule
{
    protected function code(): string
    {
        return 'V-08';
    }

    public function check(ValidationContext $context): RuleResult
    {
        $doc = $context->document;
        if ($doc->lines === [] || $doc->taxes === []) {
            return $this->skip('No line items or tax breakdown');
        }

        foreach ($doc->taxes as $row) {
            $sum = 0;
            foreach ($doc->lines as $line) {
                if ($line['tax_rate'] === $row['tax_rate']) {
                    $sum += $line['amount'] + $line['discount'];
                }
            }
            if (abs($sum - $row['taxable_amount']) > 1) {
                return $this->fail(RuleResult::WARNING, "{$row['tax_rate']}% lines add up to ".self::yen($sum).', taxable amount is '.self::yen($row['taxable_amount']));
            }
        }

        return $this->pass('Lines add up per tax rate');
    }
}
