<?php

namespace App\Validation\Rules;

use App\Validation\RuleResult;
use App\Validation\ValidationContext;

/**
 * V-05 (critical when tax rows exist). Inclusive: sum of taxable amounts equals
 * the total. Exclusive: subtotal plus tax, minus withholding tax, equals the total.
 */
class TaxesMatchTotal extends Rule
{
    protected function code(): string
    {
        return 'V-05';
    }

    public function check(ValidationContext $context): RuleResult
    {
        $doc = $context->document;
        $total = $doc->get('total');

        if ($doc->taxes === [] || $total === null) {
            return $this->skip('No tax breakdown');
        }

        $taxable = array_sum(array_column($doc->taxes, 'taxable_amount'));
        $tax = $doc->taxTotal();
        $withholding = (int) $doc->get('withholding_tax');
        // Without a printed subtotal, the tax-exclusive taxable amounts are the subtotal.
        $subtotal = $doc->get('subtotal') ?? $taxable;

        $checks = [];
        foreach (self::modes($doc->get('tax_mode')) as $mode) {
            if ($mode === 'inclusive') {
                $checks[] = [$taxable === $total, 'Taxable amounts '.self::yen($taxable).' = total '.self::yen($total)];
            } else {
                $sum = $subtotal + $tax - $withholding;
                $formula = self::yen($subtotal).' + tax '.self::yen($tax).($withholding ? ' − withholding '.self::yen($withholding) : '');
                $checks[] = [$sum === $total, $formula.' = '.self::yen($sum).', total '.self::yen($total)];
            }
        }

        foreach ($checks as [$ok, $message]) {
            if ($ok) {
                return $this->pass($message);
            }
        }

        return $this->fail(RuleResult::CRITICAL, str_replace(' = total', ' ≠ total', $checks[0][1]));
    }
}
