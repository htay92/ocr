<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One engine run on a document (spec 3.3). Failed runs are kept too, with error_code.
 *
 * @property list<array{text: string, confidence: float, bbox: ?list<array{0: int, 1: int}>, page: int}>|null $lines
 */
class OcrRun extends Model
{
    /** Engine name used for the PDF text layer, which needs no OCR. */
    public const PDF_TEXT = 'pdf_text';

    protected $fillable = [
        'engine', 'engine_version', 'lines', 'avg_confidence',
        'critical_failures', 'elapsed_ms', 'error_code',
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'avg_confidence' => 'decimal:3',
        ];
    }

    public function succeeded(): bool
    {
        return $this->error_code === null;
    }

    public function text(): string
    {
        return implode("\n", array_column($this->lines ?? [], 'text'));
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
