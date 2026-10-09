<?php

namespace App\Validation\Rules;

use App\Validation\RuleResult;
use App\Validation\ValidationContext;

/**
 * V-05 (critical when tax rows exist). Inclusive: sum of taxable amounts equals
 * the total. Exclusive: subtotal plus tax, minus withholding tax, equals the total
 * (or the taxable amounts plus tax, when a service charge is added after the subtotal).
 * Charges outside consumption tax (宿泊税, 入湯税…) may make up the difference.
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
            return $this->skip(__('No tax breakdown'));
        }

        $taxable = array_sum(array_column($doc->taxes, 'taxable_amount'));
        $tax = $doc->taxTotal();
        $withholding = (int) $doc->get('withholding_tax');
        // Without a printed subtotal, the tax-exclusive taxable amounts are the subtotal.
        $subtotal = $doc->get('subtotal') ?? $taxable;

        $failures = [];
        foreach (self::modes($doc->get('tax_mode')) as $mode) {
            if ($mode === 'inclusive') {
                $values = ['taxable' => self::yen($taxable), 'total' => self::yen($total)];
                if ($taxable === $total) {
                    return $this->pass(__('Taxable amounts :taxable = total :total', $values));
                }
                $nonTaxable = self::nonTaxable($doc->lines);
                if ($nonTaxable > 0 && $taxable + $nonTaxable === $total) {
                    return $this->pass(__('Taxable amounts :taxable + non-taxable :other = total :total', [...$values, 'other' => self::yen($nonTaxable)]));
                }
                $failures[] = __('Taxable amounts :taxable ≠ total :total', $values);
            } else {
                $sum = $subtotal + $tax - $withholding;
                $values = ['subtotal' => self::yen($subtotal), 'tax' => self::yen($tax), 'withholding' => self::yen($withholding), 'sum' => self::yen($sum), 'total' => self::yen($total)];
                // A service charge sits between the subtotal and the taxable amount: taxable + tax counts too.
                if ($sum !== $total && $taxable !== $subtotal && $taxable + $tax - $withholding === $total) {
                    return $this->pass(__('Taxable amounts :taxable + tax :tax = total :total', ['taxable' => self::yen($taxable), 'tax' => self::yen($tax), 'total' => self::yen($total)]));
                }
                if ($sum === $total) {
                    return $this->pass($withholding
                        ? __('Subtotal :subtotal + tax :tax − withholding :withholding = total :total', $values)
                        : __('Subtotal :subtotal + tax :tax = total :total', $values));
                }
                $failures[] = $withholding
                    ? __('Subtotal :subtotal + tax :tax − withholding :withholding = :sum ≠ total :total', $values)
                    : __('Subtotal :subtotal + tax :tax = :sum ≠ total :total', $values);
            }
        }

        return $this->fail(RuleResult::CRITICAL, $failures[0]);
    }
}
