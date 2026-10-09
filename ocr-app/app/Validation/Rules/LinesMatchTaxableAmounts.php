<?php

namespace App\Validation\Rules;

use App\Validation\RuleResult;
use App\Validation\ValidationContext;

/**
 * V-08: sum of 8% lines equals the 8% taxable amount within 1 yen, the same for 10% (warning).
 * On a tax-exclusive document the lines may print tax-included prices (税込価格): taxable + tax.
 */
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
            return $this->skip(__('No line items or tax breakdown'));
        }

        foreach ($doc->taxes as $row) {
            $sum = 0;
            foreach ($doc->lines as $line) {
                if ($line['tax_rate'] === $row['tax_rate']) {
                    $sum += $line['amount'] + $line['discount'];
                }
            }
            $included = $doc->get('tax_mode') === 'exclusive' ? $row['taxable_amount'] + $row['tax_amount'] : null;
            if (abs($sum - $row['taxable_amount']) > 1 && ($included === null || abs($sum - $included) > 1)) {
                return $this->fail(RuleResult::WARNING, __(':rate% lines add up to :sum, but the taxable amount is :taxable', [
                    'rate' => $row['tax_rate'], 'sum' => self::yen($sum), 'taxable' => self::yen($row['taxable_amount']),
                ]));
            }
        }

        return $this->pass(__('Lines add up per tax rate'));
    }
}
