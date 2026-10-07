<?php

namespace App\Extraction\Support;

/**
 * One visual line of a page: the OCR lines that sit side by side, left to right.
 */
final readonly class Row
{
    public function __construct(
        public string $text,
        public float $confidence,
        public int $page = 1,
        public ?float $top = null,
        public ?float $height = null,
    ) {}
}
