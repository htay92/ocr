<?php

namespace App\Processing;

use App\Extraction\Data\ExtractedDocument;
use App\Models\OcrRun;
use App\Validation\RuleResult;

/**
 * What one OCR run tells about a document: its type, the extracted fields and
 * how many critical checks failed.
 */
final readonly class Analysis
{
    /**
     * @param  list<RuleResult>  $results
     */
    public function __construct(
        public OcrRun $run,
        public string $type,
        public ?ExtractedDocument $extracted = null,
        public array $results = [],
    ) {}

    public function criticalFailures(): int
    {
        return RuleResult::criticalCount($this->results);
    }

    /**
     * @return list<string> codes of the failed critical rules, e.g. ["V-01", "V-05"]
     */
    public function criticalCodes(): array
    {
        return array_values(array_map(
            fn (RuleResult $result) => $result->code,
            array_filter($this->results, fn (RuleResult $result) => $result->level === RuleResult::CRITICAL),
        ));
    }
}
