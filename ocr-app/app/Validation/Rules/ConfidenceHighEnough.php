<?php

namespace App\Validation\Rules;

use App\Validation\RuleResult;
use App\Validation\ValidationContext;

/** V-09: average OCR confidence is at least ocr.confidence_min, 0.80 by default (warning). */
class ConfidenceHighEnough extends Rule
{
    protected function code(): string
    {
        return 'V-09';
    }

    public function check(ValidationContext $context): RuleResult
    {
        $confidence = $context->avgConfidence;
        $minimum = (float) config('ocr.confidence_min', 0.80);

        if ($confidence === null) {
            return $this->skip(__('No OCR confidence'));
        }

        $message = __('Average OCR confidence :confidence (minimum :min)', ['confidence' => number_format($confidence, 2), 'min' => number_format($minimum, 2)]);

        return $confidence >= $minimum ? $this->pass($message) : $this->fail(RuleResult::WARNING, $message);
    }
}
