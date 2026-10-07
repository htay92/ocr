<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One uploaded file (spec 2.5, 3.3).
 *
 * @property int $id
 * @property string $status
 * @property ?string $route
 * @property string $file_path
 * @property string $mime_type
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    // Statuses (spec 2.4).
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const EXTRACTED = 'extracted';

    public const NEEDS_REVIEW = 'needs_review';

    public const CONFIRMED = 'confirmed';

    public const FAILED = 'failed';

    // Routes (spec 2.4 step 2).
    public const ROUTE_OCR = 'ocr';

    public const ROUTE_PDF_TEXT = 'pdf_text';

    public const ROUTE_SPREADSHEET = 'spreadsheet';

    /**
     * Allowed status changes, from the state diagram in spec 2.4. A job that
     * dies before it starts may also fail a pending document.
     */
    private const TRANSITIONS = [
        self::PENDING => [self::PROCESSING, self::FAILED],
        self::PROCESSING => [self::EXTRACTED, self::NEEDS_REVIEW, self::FAILED],
        self::FAILED => [self::PENDING],
        self::EXTRACTED => [self::CONFIRMED],
        self::NEEDS_REVIEW => [self::CONFIRMED],
        self::CONFIRMED => [],
    ];

    protected $fillable = [
        'type', 'status', 'original_name', 'file_path', 'mime_type', 'sha256',
        'route', 'selected_run_id', 'error_message', 'confirmed_at',
    ];

    protected $attributes = [
        'status' => self::PENDING,
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }

    /** Still queued or running; the screens refresh until this is false. */
    public function isBusy(): bool
    {
        return in_array($this->status, [self::PENDING, self::PROCESSING], true);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * @param  array<string, mixed>  $attributes  saved together with the new status
     */
    public function transitionTo(string $status, array $attributes = []): void
    {
        if (! $this->canTransitionTo($status)) {
            throw new LogicException("Document {$this->id} cannot move from {$this->status} to {$status}.");
        }

        if ($status === self::CONFIRMED) {
            $attributes['confirmed_at'] = now();
        }

        $this->update(['status' => $status, ...$attributes]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<OcrRun, $this>
     */
    public function ocrRuns(): HasMany
    {
        return $this->hasMany(OcrRun::class);
    }

    /**
     * @return BelongsTo<OcrRun, $this>
     */
    public function selectedRun(): BelongsTo
    {
        return $this->belongsTo(OcrRun::class, 'selected_run_id');
    }
}
