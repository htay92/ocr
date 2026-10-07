<?php

namespace App\Http\Resources;

use App\Extraction\Data\ExtractedDocument;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'route' => $this->route,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'sha256' => $this->sha256,
            'error_message' => $this->error_message,
            'selected_run' => new OcrRunResource($this->whenLoaded('selectedRun')),
            'receipt' => $this->whenLoaded('receipt', fn () => $this->receipt === null ? null : [
                'fields' => collect(ExtractedDocument::FIELDS)->mapWithKeys(fn (string $field) => [
                    $field => $this->receipt->{$field} instanceof \DateTimeInterface ? $this->receipt->{$field}->format('Y-m-d') : $this->receipt->{$field},
                ]),
                'taxes' => $this->receipt->taxes->map->only(['tax_rate', 'taxable_amount', 'tax_amount'])->values(),
                'lines' => $this->receipt->lines->map(fn ($line) => [
                    'line_no' => $line->line_no, 'name' => $line->name, 'quantity' => (float) $line->quantity,
                    'unit_price' => $line->unit_price, 'amount' => $line->amount, 'tax_rate' => $line->tax_rate, 'discount' => $line->discount,
                ])->values(),
                'corrected_fields' => $this->receipt->corrected_fields ?? [],
            ]),
            'checks' => $this->when($this->relationLoaded('receipt'), fn () => $this->checks()->get()->map->only(['rule_code', 'level', 'message'])->values()),
            'file_url' => url("/api/documents/{$this->id}/file"),
            'confirmed_at' => $this->confirmed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
