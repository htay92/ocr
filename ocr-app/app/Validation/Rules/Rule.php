<?php

namespace App\Validation\Rules;

use App\Validation\RuleResult;
use App\Validation\ValidationRule;

abstract class Rule implements ValidationRule
{
    abstract protected function code(): string;

    protected function pass(string $message): RuleResult
    {
        return new RuleResult($this->code(), RuleResult::PASS, $message);
    }

    protected function fail(string $level, string $message): RuleResult
    {
        return new RuleResult($this->code(), $level, $message);
    }

    protected function skip(string $message): RuleResult
    {
        return new RuleResult($this->code(), RuleResult::SKIPPED, $message);
    }

    protected static function yen(int|float|null $amount): string
    {
        return $amount === null ? '—' : '¥'.number_format($amount);
    }

    /** Tax on a base: inclusive base × r / (100 + r), exclusive base × r / 100. */
    protected static function taxOn(int $base, int $rate, string $mode): float
    {
        return $mode === 'inclusive' ? $base * $rate / (100 + $rate) : $base * $rate / 100;
    }

    /**
     * Modes to check: the stated one, or both when the document does not say.
     *
     * @return list<string>
     */
    protected static function modes(?string $mode): array
    {
        return $mode ? [$mode] : ['inclusive', 'exclusive'];
    }
}
