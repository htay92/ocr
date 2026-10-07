<?php

namespace App\Models;

use App\Support\Labels;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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
        // Extended in milestone 5: re-run from the review screen, and reopen a confirmed document.
        self::EXTRACTED => [self::CONFIRMED, self::PENDING],
        self::NEEDS_REVIEW => [self::CONFIRMED, self::PENDING],
        self::CONFIRMED => [self::NEEDS_REVIEW],
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

    /**
     * Not while a job is writing to it, and not once confirmed: confirmed
     * documents are tax records kept for 7 years (NFR-06); reopen first.
     */
    public function canBeDeleted(): bool
    {
        return ! in_array($this->status, [self::PROCESSING, self::CONFIRMED], true);
    }

    /** Fields can be corrected (SC-04) once processing has finished, until confirmed. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::EXTRACTED, self::NEEDS_REVIEW], true);
    }

    /**
     * Delete the document with its stored file; OCR runs, fields, line items
     * and checks go with it (foreign keys cascade).
     */
    public function deleteWithFile(): void
    {
        if (! $this->canBeDeleted()) {
            throw new LogicException("Document {$this->id} is being processed and cannot be deleted.");
        }

        $this->delete();
        Storage::disk('local')->delete($this->file_path);
        Storage::disk('local')->deleteDirectory("documents/{$this->id}");   // preprocessed page images
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
     * List filters (SC-02, spec 2.3): status, type, date_from, date_to, vendor, min_total, max_total.
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type));

        $receipt = array_filter(array_intersect_key($filters, array_flip(['date_from', 'date_to', 'vendor', 'min_total', 'max_total'])), fn ($v) => $v !== null && $v !== '');
        if ($receipt !== []) {
            $query->whereHas('receipt', fn ($q) => $q
                ->when($receipt['date_from'] ?? null, fn ($q, $from) => $q->whereDate('issue_date', '>=', $from))
                ->when($receipt['date_to'] ?? null, fn ($q, $to) => $q->whereDate('issue_date', '<=', $to))
                ->when($receipt['vendor'] ?? null, fn ($q, $vendor) => $q->whereRaw('LOWER(vendor_name) LIKE ?', ['%'.mb_strtolower($vendor).'%']))
                ->when(isset($receipt['min_total']), fn ($q) => $q->where('total', '>=', (int) $receipt['min_total']))
                ->when(isset($receipt['max_total']), fn ($q) => $q->where('total', '<=', (int) $receipt['max_total'])));
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function filterRules(): array
    {
        return [
            'status' => ['nullable', Rule::in(array_keys(self::TRANSITIONS))],
            'type' => ['nullable', Rule::in(array_keys(Labels::TYPES))],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'vendor' => ['nullable', 'string', 'max:100'],
            'min_total' => ['nullable', 'integer', 'min:0'],
            'max_total' => ['nullable', 'integer', 'min:0'],
        ];
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
     * @return HasOne<Receipt, $this>
     */
    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    /**
     * Validation results of every run.
     *
     * @return HasMany<ValidationResult, $this>
     */
    public function validationResults(): HasMany
    {
        return $this->hasMany(ValidationResult::class);
    }

    /**
     * The current checks: those of the user's last correction when there is one
     * (stored without an OCR run), otherwise those of the selected run. Query it
     * directly; it cannot be eager-loaded.
     *
     * @return HasMany<ValidationResult, $this>
     */
    public function checks(): HasMany
    {
        $manual = $this->validationResults()->whereNull('ocr_run_id')->exists();

        return $this->validationResults()
            ->when($manual, fn ($query) => $query->whereNull('ocr_run_id'), fn ($query) => $query->where('ocr_run_id', $this->selected_run_id))
            ->orderBy('rule_code');
    }

    /**
     * @return BelongsTo<OcrRun, $this>
     */
    public function selectedRun(): BelongsTo
    {
        return $this->belongsTo(OcrRun::class, 'selected_run_id');
    }
}
