<?php

namespace App\Processing;

use App\Extraction\Contracts\Extractor;
use App\Models\Document;
use App\Models\OcrRun;
use App\Models\Receipt;
use App\Validation\ReceiptValidator;
use App\Validation\RuleResult;
use App\Validation\ValidationContext;

/**
 * Spec 2.4 steps 5–7 for one OCR run: classify, extract, validate. Results are
 * stored per run, so runs of different engines can be compared.
 */
class DocumentAnalyzer
{
    public function __construct(
        private readonly DocumentClassifier $classifier,
        private readonly Extractor $extractor,
        private readonly ReceiptValidator $validator,
    ) {}

    public function analyse(Document $document, OcrRun $run): Analysis
    {
        $type = $this->classifier->classify($run->text());

        // Attendance sheets are parsed by the attendance importer (milestone 6); other documents have no fields.
        if (! in_array($type, DocumentClassifier::RECEIPT_TYPES, true)) {
            return new Analysis($run, $type);
        }

        $extracted = $this->extractor->extract($run, $type);
        $results = $this->validator->validate(new ValidationContext(
            $extracted,
            $type,
            $run->avg_confidence === null ? null : (float) $run->avg_confidence,
            $document->id,
        ));

        $run->validationResults()->delete();
        foreach ($results as $result) {
            if ($result->level !== RuleResult::SKIPPED) {
                $run->validationResults()->create([
                    'document_id' => $document->id,
                    'rule_code' => $result->code,
                    'level' => $result->level,
                    'message' => mb_strimwidth($result->message, 0, 255, '…'),
                ]);
            }
        }

        $analysis = new Analysis($run, $type, $extracted, $results);
        $run->update(['critical_failures' => $analysis->criticalFailures(), 'extracted' => $extracted->toArray()]);

        return $analysis;
    }

    /**
     * Make this run the document's result: type, receipt fields and selected run.
     *
     * @param  array<string, mixed>  $attributes  saved with the new status
     */
    public function apply(Document $document, Analysis $analysis, string $status, array $attributes = []): void
    {
        // A new reading replaces earlier corrections and their checks.
        $document->validationResults()->whereNull('ocr_run_id')->whereNull('attendance_sheet_id')->delete();

        if ($analysis->extracted !== null) {
            Receipt::saveExtracted($document, $analysis->extracted);
        } else {
            $document->receipt()->delete();
        }

        $document->transitionTo($status, [
            'type' => $analysis->type,
            'selected_run_id' => $analysis->run->id,
            ...$attributes,
        ]);
    }
}
