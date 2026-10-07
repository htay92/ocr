<?php

namespace App\Extraction\Contracts;

use App\Extraction\Data\ExtractedDocument;
use App\Models\OcrRun;

/**
 * Turns one OCR run into fields (spec 3.1). The rule extractor is the default;
 * an LLM extractor can implement the same contract (FR-17).
 */
interface Extractor
{
    /**
     * @param  string  $type  receipt, invoice or paid_receipt
     */
    public function extract(OcrRun $run, string $type): ExtractedDocument;
}
