<?php

namespace App\Services;

use App\Extraction\Data\ExtractedDocument;
use App\Models\Document;
use App\Models\Receipt;
use App\Validation\ReceiptValidator;
use App\Validation\RuleResult;
use App\Validation\ValidationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Review and correction (SC-04, UC-03, UC-04): save what the user fixed, check it
 * again with V-01..V-10, and confirm. A confirmed receipt is the ground truth the
 * engines' own readings (ocr_runs.extracted) are compared with (FR-18).
 */
class ReceiptCorrector
{
    public function __construct(private readonly ReceiptValidator $validator) {}

    /**
     * @param  array{type: string, fields: array<string, mixed>, taxes: list<array>, lines: list<array>}  $input
     * @return list<RuleResult>
     */
    public function save(Document $document, array $input): array
    {
        return DB::transaction(function () use ($document, $input) {
            $fields = array_merge(array_fill_keys(ExtractedDocument::FIELDS, null), array_intersect_key($input['fields'], array_flip(ExtractedDocument::FIELDS)));
            $read = $document->selectedRun?->extracted['fields'] ?? [];
            $previous = $document->receipt?->corrected_fields ?? [];

            $document->update(['type' => $input['type']]);
            $receipt = $document->receipt ?? $document->receipt()->make();
            $receipt->fill([
                ...$fields,
                // Fields that now differ from what the engine read (kept across saves).
                'corrected_fields' => array_values(array_filter(
                    ExtractedDocument::FIELDS,
                    fn (string $field) => in_array($field, $previous, true) || ! self::same($fields[$field], $read[$field] ?? null),
                )),
            ])->save();

            $receipt->taxes()->delete();
            $receipt->taxes()->createMany($input['taxes']);
            $receipt->lines()->delete();
            $receipt->lines()->createMany(array_map(
                fn (array $line, int $i) => ['line_no' => $i + 1, ...$line],
                array_values($input['lines']),
                array_keys(array_values($input['lines'])),
            ));

            return $this->validate($document->fresh(), $receipt->fresh());
        });
    }

    /**
     * @throws ValidationException when critical checks fail and the user has not acknowledged them
     */
    public function confirm(Document $document, bool $acknowledged): void
    {
        if (! $acknowledged && $document->checks()->where('level', RuleResult::CRITICAL)->exists()) {
            throw ValidationException::withMessages([
                'acknowledged' => __('Some checks still fail. Tick the box to confirm anyway.'),
            ]);
        }

        $document->transitionTo(Document::CONFIRMED, ['error_message' => null]);
    }

    public function reopen(Document $document): void
    {
        $document->transitionTo(Document::NEEDS_REVIEW);
    }

    /**
     * Run V-01..V-10 on the corrected receipt; the results replace the earlier manual ones
     * (stored without an OCR run) and are shown instead of the run's.
     *
     * @return list<RuleResult>
     */
    private function validate(Document $document, Receipt $receipt): array
    {
        $corrected = new ExtractedDocument(
            fields: collect(ExtractedDocument::FIELDS)->mapWithKeys(fn (string $field) => [
                $field => $receipt->{$field} instanceof \DateTimeInterface ? $receipt->{$field}->format('Y-m-d') : $receipt->{$field},
            ])->all(),
            taxes: $receipt->taxes->map->only(['tax_rate', 'taxable_amount', 'tax_amount'])->values()->all(),
            lines: $receipt->lines->map(fn ($line) => [
                'line_no' => $line->line_no, 'name' => $line->name, 'quantity' => (float) $line->quantity,
                'unit_price' => $line->unit_price, 'amount' => $line->amount, 'tax_rate' => $line->tax_rate, 'discount' => $line->discount,
            ])->values()->all(),
        );

        $run = $document->selectedRun;
        $results = $this->validator->validate(new ValidationContext(
            $corrected,
            $document->type,
            $run?->avg_confidence === null ? null : (float) $run->avg_confidence,
            $document->id,
        ));

        $document->validationResults()->whereNull('ocr_run_id')->delete();
        foreach ($results as $result) {
            if ($result->level !== RuleResult::SKIPPED) {
                $document->validationResults()->create([
                    'rule_code' => $result->code,
                    'level' => $result->level,
                    'message' => mb_strimwidth($result->message, 0, 255, '…'),
                ]);
            }
        }

        return $results;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return (string) ($a ?? '') === (string) ($b ?? '');
    }
}
