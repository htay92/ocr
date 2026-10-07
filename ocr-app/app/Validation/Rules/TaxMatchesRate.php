<?php

namespace App\Validation\Rules;

use App\Validation\RuleResult;
use App\Validation\ValidationContext;

/** V-06: each tax amount matches its rate within 1 yen (warning). */
class TaxMatchesRate extends Rule
{
    protected function code(): string
    {
        return 'V-06';
    }

    public function check(ValidationContext $context): RuleResult
    {
        $doc = $context->document;
        if ($doc->taxes === []) {
            return $this->skip(__('No tax breakdown'));
        }

        foreach ($doc->taxes as $row) {
            $matches = false;
            foreach (self::modes($doc->get('tax_mode')) as $mode) {
                $matches = $matches || abs($row['tax_amount'] - self::taxOn($row['taxable_amount'], $row['tax_rate'], $mode)) <= 1;
            }
            if (! $matches) {
                return $this->fail(RuleResult::WARNING, __(':rate% tax :tax does not match the taxable amount :taxable', [
                    'rate' => $row['tax_rate'], 'tax' => self::yen($row['tax_amount']), 'taxable' => self::yen($row['taxable_amount']),
                ]));
            }
        }

        return $this->pass(__('Tax amounts match their rates'));
    }
}
