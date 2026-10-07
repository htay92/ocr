<?php

namespace App\Validation;

/**
 * Runs a set of validation rules (spec 3.1, 3.5): V-01..V-10 for receipts,
 * A-01..A-08 for attendance (milestone 6).
 */
interface Validator
{
    /**
     * @return list<RuleResult> one per rule, in rule order
     */
    public function validate(ValidationContext $context): array;
}
