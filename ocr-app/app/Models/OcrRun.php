<?php

namespace App\Models;

use App\Ocr\Data\OcrResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'critical_failures', 'elapsed_ms', 'error_code', 'extracted', 'images',
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'extracted' => 'array',
            'images' => 'array',
            'avg_confidence' => 'decimal:3',
        ];
    }

    /**
     * Attributes of one engine's run from its per-page results, merged into one list of lines.
     *
     * @param  list<OcrResult>  $results
     */
    public static function attributesFrom(string $engine, array $results): array
    {
        $lines = [];
        foreach ($results as $page => $result) {
            foreach ($result->lines as $line) {
                $lines[] = ['text' => $line->text, 'confidence' => $line->confidence, 'bbox' => $line->bbox, 'page' => $page + 1];
            }
        }

        return [
            'engine' => $engine,
            'engine_version' => $results[0]->engineVersion,
            'lines' => $lines,
            'avg_confidence' => $lines === [] ? null : array_sum(array_column($lines, 'confidence')) / count($lines),
            'elapsed_ms' => array_sum(array_map(fn (OcrResult $result) => $result->elapsedMs, $results)),
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

    /**
     * @return HasMany<ValidationResult, $this>
     */
    public function validationResults(): HasMany
    {
        return $this->hasMany(ValidationResult::class);
    }
}
