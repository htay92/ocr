<?php

namespace App\Validation;

use App\Extraction\Data\ExtractedDocument;

final readonly class ValidationContext
{
    /**
     * @param  ?float  $avgConfidence  mean OCR line confidence of the run (V-09)
     * @param  ?int  $documentId  excluded from the duplicate check (V-10)
     * @param  ?array{0: int, 1: int}  $imageSize  width and height of the uploaded image (V-11); null when no OCR image
     */
    public function __construct(
        public ExtractedDocument $document,
        public string $type,
        public ?float $avgConfidence = null,
        public ?int $documentId = null,
        public ?array $imageSize = null,
    ) {}
}
