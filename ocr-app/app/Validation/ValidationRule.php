<?php

namespace App\Validation;

interface ValidationRule
{
    public function check(ValidationContext $context): RuleResult;
}
