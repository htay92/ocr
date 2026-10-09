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

    /** Charges outside consumption tax: lodging and bath taxes, stamps, advances (立替). */
    public const NON_TAXABLE = '/宿泊税|入湯税|印紙|非課税|不課税|立替/u';

    /** Sum of line items that carry no consumption tax. */
    protected static function nonTaxable(array $lines): int
    {
        return array_sum(array_map(fn (array $line) => preg_match(self::NON_TAXABLE, $line['name']) ? $line['amount'] + $line['discount'] : 0, $lines));
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
