<?php

namespace App\Validation;

/**
 * V-01..V-10 for receipts, invoices and paid receipts (spec 3.5).
 */
class ReceiptValidator implements Validator
{
    /** @var list<class-string<ValidationRule>> */
    private const RULES = [
        Rules\TotalExists::class,
        Rules\IssueDateValid::class,
        Rules\RegistrationNumberFormat::class,
        Rules\LinesMatchSubtotal::class,
        Rules\TaxesMatchTotal::class,
        Rules\TaxMatchesRate::class,
        Rules\ChangeMatchesCash::class,
        Rules\LinesMatchTaxableAmounts::class,
        Rules\ConfidenceHighEnough::class,
        Rules\NotDuplicate::class,
    ];

    public function validate(ValidationContext $context): array
    {
        return array_map(fn (string $rule) => app($rule)->check($context), self::RULES);
    }
}
