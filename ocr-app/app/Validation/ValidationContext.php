<?php

namespace App\Validation;

use App\Extraction\Data\ExtractedDocument;

final readonly class ValidationContext
{
    /**
     * @param  ?float  $avgConfidence  mean OCR line confidence of the run (V-09)
     * @param  ?int  $documentId  excluded from the duplicate check (V-10)
     */
    public function __construct(
        public ExtractedDocument $document,
        public string $type,
        public ?float $avgConfidence = null,
        public ?int $documentId = null,
    ) {}
}
