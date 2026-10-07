<?php

namespace App\Ocr\Data;

final readonly class OcrLine
{
    /**
     * @param  list<array{0: int, 1: int}>  $bbox  four corners in pixels of the preprocessed image, clockwise from top-left
     * @param  float  $confidence  0 to 1
     */
    public function __construct(
        public string $text,
        public array $bbox,
        public float $confidence,
    ) {}
}
