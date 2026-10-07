<?php

namespace App\Ocr\Data;

/**
 * Engine-neutral OCR result: the spec 3.2 response as a value object.
 */
final readonly class OcrResult
{
    /**
     * @param  list<OcrLine>  $lines  top to bottom, then left to right
     * @param  array{width: int, height: int, rotation: int}  $image
     */
    public function __construct(
        public array $lines,
        public string $engine,
        public ?string $engineVersion,
        public int $elapsedMs,
        public array $image,
        public ?string $preprocessedImage = null,
    ) {}

    public static function fromResponse(array $response): self
    {
        return new self(
            lines: array_map(fn (array $line) => new OcrLine(
                text: $line['text'],
                bbox: $line['bbox'],
                confidence: (float) $line['confidence'],
            ), $response['lines'] ?? []),
            engine: $response['engine'],
            engineVersion: $response['engine_version'] ?? null,
            elapsedMs: (int) ($response['elapsed_ms'] ?? 0),
            image: $response['image'],
            preprocessedImage: $response['preprocessed_image'] ?? null,
        );
    }

    public function text(): string
    {
        return implode("\n", array_map(fn (OcrLine $line) => $line->text, $this->lines));
    }

    public function avgConfidence(): ?float
    {
        if ($this->lines === []) {
            return null;
        }

        $sum = array_sum(array_map(fn (OcrLine $line) => $line->confidence, $this->lines));

        return round($sum / count($this->lines), 3);
    }

    public function toArray(): array
    {
        return [
            'engine' => $this->engine,
            'engine_version' => $this->engineVersion,
            'elapsed_ms' => $this->elapsedMs,
            'image' => $this->image,
            'lines' => array_map(fn (OcrLine $line) => [
                'text' => $line->text,
                'confidence' => $line->confidence,
                'bbox' => $line->bbox,
            ], $this->lines),
            'preprocessed_image' => $this->preprocessedImage,
        ];
    }
}
