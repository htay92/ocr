<?php

namespace App\Validation\Rules;

use App\Processing\DocumentClassifier;
use App\Validation\RuleResult;
use App\Validation\ValidationContext;

/**
 * V-03: registration number is T + 13 digits. Critical for invoices, where it is
 * required (spec 1.4), a warning otherwise; skipped when a receipt has none.
 */
class RegistrationNumberFormat extends Rule
{
    protected function code(): string
    {
        return 'V-03';
    }

    public function check(ValidationContext $context): RuleResult
    {
        $number = $context->document->get('registration_number');
        $level = $context->type === DocumentClassifier::INVOICE ? RuleResult::CRITICAL : RuleResult::WARNING;

        if ($number === null) {
            return $context->type === DocumentClassifier::INVOICE
                ? $this->fail($level, __('Registration number (T + 13 digits) not found; required on invoices'))
                : $this->skip(__('No registration number'));
        }

        return preg_match('/^T\d{13}$/', $number)
            ? $this->pass(__('Registration number :number', ['number' => $number]))
            : $this->fail($level, __('Registration number :number is not T + 13 digits', ['number' => $number]));
    }
}
