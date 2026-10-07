<?php

namespace App\Validation;

/**
 * The outcome of one validation rule (spec 3.5). A rule whose data is missing
 * is skipped, not failed; skipped results are not stored.
 */
final readonly class RuleResult
{
    public const PASS = 'pass';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    public const SKIPPED = 'skipped';

    public function __construct(
        public string $code,
        public string $level,
        public string $message,
    ) {}

    /**
     * @param  list<self>  $results
     */
    public static function criticalCount(array $results): int
    {
        return count(array_filter($results, fn (self $result) => $result->level === self::CRITICAL));
    }
}
