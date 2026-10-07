<?php

namespace App\Validation\Rules;

use App\Validation\RuleResult;
use App\Validation\ValidationContext;

/**
 * V-04: sum of line amounts plus discounts equals the subtotal, or the total when
 * there is no subtotal (critical when lines exist). Tax-exclusive documents
 * without a subtotal are compared with the total before tax.
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
            return $this->skip('No line items');
        }

        $sum = array_sum(array_map(fn (array $line) => $line['amount'] + $line['discount'], $lines));
        foreach ($targets as $label => $target) {
            if ($sum === $target) {
                return $this->pass(count($lines).' lines add up to the '.$label.' '.self::yen($target));
            }
        }

        $label = array_key_first($targets);

        return $this->fail(RuleResult::CRITICAL, count($lines).' lines add up to '.self::yen($sum).', '.$label.' is '.self::yen($targets[$label]));
    }
}
