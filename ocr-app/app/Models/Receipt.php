<?php

namespace App\Models;

use App\Extraction\Data\ExtractedDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Extracted and corrected header fields of a receipt, invoice or paid receipt (spec 3.3).
 */
class Receipt extends Model
{
    /**
     * Required items per document type (spec 1.4); shown as missing on the review screens.
     */
    public const REQUIRED = [
        'receipt' => ['vendor_name', 'issue_date', 'total'],
        'invoice' => ['vendor_name', 'registration_number', 'issue_date', 'invoice_number', 'recipient_name', 'total'],
        'paid_receipt' => ['vendor_name', 'issue_date', 'total'],
    ];

    protected $fillable = [...ExtractedDocument::FIELDS, 'field_confidence'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'field_confidence' => 'array',
        ];
    }

    /**
     * Replace the document's receipt with what an extractor read.
     */
    public static function saveExtracted(Document $document, ExtractedDocument $extracted): self
    {
        $document->receipt()->delete();

        $receipt = $document->receipt()->create([...$extracted->fields, 'field_confidence' => $extracted->fieldConfidence]);
        $receipt->taxes()->createMany($extracted->taxes);
        $receipt->lines()->createMany($extracted->lines);

        return $receipt;
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return HasMany<ReceiptTax, $this>
     */
    public function taxes(): HasMany
    {
        return $this->hasMany(ReceiptTax::class)->orderByDesc('tax_rate');
    }

    /**
     * @return HasMany<ReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ReceiptLine::class)->orderBy('line_no');
    }
}
