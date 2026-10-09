<?php

namespace App\Validation\Rules;

use App\Validation\RuleResult;
use App\Validation\ValidationContext;

/**
 * V-04: sum of line amounts plus discounts equals the subtotal, or the total when
 * there is no subtotal (critical when lines exist). Tax-exclusive documents
 * without a subtotal are compared with the total before tax; with a subtotal,
 * lines may also add up to the total (prices printed tax-included, 税込価格).
 */
class LinesMatchSubtotal extends Rule
{
    protected function code(): string
    {
        return 'V-04';
    }

    public function check(ValidationContext $context): RuleResult
    {
        $doc = $context->document;
        $lines = $doc->lines;
        $total = $doc->get('total');

        // What the lines can add up to: the subtotal; otherwise the total, or for tax-exclusive
        // documents (or when the mode is not printed) the total before tax.
        $targets = [];
        if ($doc->get('subtotal') !== null) {
            $targets['subtotal'] = $doc->get('subtotal');
            // Tables with tax-included prices (税込価格) add up to the total, not the subtotal before tax.
            if ($total !== null && $doc->get('tax_mode') === 'exclusive') {
                $targets['total'] = $total;
            }
        } elseif ($total !== null) {
            if ($doc->get('tax_mode') !== 'exclusive') {
                $targets['total'] = $total;
            }
            if ($doc->get('tax_mode') !== 'inclusive' && $doc->taxes !== []) {
                $targets['total before tax'] = $total - $doc->taxTotal() + (int) $doc->get('withholding_tax');
            }
            $targets = $targets ?: ['total' => $total];
        }

        if ($lines === [] || $targets === []) {
            return $this->skip(__('No line items'));
        }

        $sum = array_sum(array_map(fn (array $line) => $line['amount'] + $line['discount'], $lines));
        foreach ($targets as $label => $target) {
            if ($sum === $target) {
                return $this->pass(__(':count lines add up to the :target :amount', ['count' => count($lines), 'target' => __($label), 'amount' => self::yen($target)]));
            }
        }

        $label = array_key_first($targets);

        return $this->fail(RuleResult::CRITICAL, __(':count lines add up to :sum, but the :target is :amount', [
            'count' => count($lines), 'sum' => self::yen($sum), 'target' => __($label), 'amount' => self::yen($targets[$label]),
        ]));
    }
}
