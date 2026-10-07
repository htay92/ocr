<?php

use App\Models\Document;
use App\Services\ReceiptCorrector;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => Carbon::setTestNow('2026-10-07 12:00:00'));

/** An invoice as the OCR read it: 請求書４ with a blank date and the 8% line misread. */
function readInvoice(): Document
{
    $document = Document::factory()->status('needs_review')->create(['type' => 'invoice']);
    $run = $document->ocrRuns()->create(['engine' => 'paddle', 'lines' => [], 'avg_confidence' => 0.92, 'extracted' => [
        'fields' => ['vendor_name' => '株式会社', 'total' => 1001404, 'issue_date' => null],
        'taxes' => [], 'lines' => [],
    ]]);
    $document->update(['selected_run_id' => $run->id]);
    $document->receipt()->create(['vendor_name' => '株式会社', 'total' => 1001404]);
    $run->validationResults()->create(['document_id' => $document->id, 'rule_code' => 'V-02', 'level' => 'critical', 'message' => 'no date']);

    return $document->fresh();
}

function correctedInput(array $fields = []): array
{
    return [
        'type' => 'invoice',
        'fields' => array_merge([
            'vendor_name' => '株式会社○○', 'registration_number' => 'T1234567890123', 'issue_date' => '2025-07-31',
            'invoice_number' => 'INV-1', 'recipient_name' => '株式会社△△', 'subtotal' => 1004000, 'total' => 1001404,
            'withholding_tax' => 102916, 'tax_mode' => 'exclusive',
        ], $fields),
        'taxes' => [
            ['tax_rate' => 10, 'taxable_amount' => 1000000, 'tax_amount' => 100000],
            ['tax_rate' => 8, 'taxable_amount' => 4000, 'tax_amount' => 320],
        ],
        'lines' => [
            ['name' => '商品A', 'quantity' => 1, 'unit_price' => 1000000, 'amount' => 1000000, 'tax_rate' => 10, 'discount' => 0],
            ['name' => '商品B', 'quantity' => 2, 'unit_price' => 2000, 'amount' => 4000, 'tax_rate' => 8, 'discount' => 0],
        ],
    ];
}

it('saves corrected fields, tax rows and lines, and records what was changed', function () {
    $document = readInvoice();

    app(ReceiptCorrector::class)->save($document, correctedInput());

    $receipt = $document->fresh()->receipt;
    expect($receipt->vendor_name)->toBe('株式会社○○')
        ->and($receipt->issue_date->format('Y-m-d'))->toBe('2025-07-31')
        ->and($receipt->taxes->pluck('tax_amount')->all())->toBe([100000, 320])
        ->and($receipt->lines->pluck('name', 'line_no')->all())->toBe([1 => '商品A', 2 => '商品B'])
        // total was read correctly; everything else was typed in
        ->and($receipt->corrected_fields)->toBe(['vendor_name', 'registration_number', 'issue_date', 'invoice_number', 'recipient_name', 'subtotal', 'withholding_tax', 'tax_mode']);
});

it('re-validates after saving, and the new checks replace the run\'s', function () {
    $document = readInvoice();
    expect($document->checks()->pluck('level', 'rule_code')->all())->toBe(['V-02' => 'critical']);

    app(ReceiptCorrector::class)->save($document, correctedInput());

    $checks = $document->fresh()->checks()->pluck('level', 'rule_code')->all();
    expect($checks)->toMatchArray(['V-01' => 'pass', 'V-02' => 'pass', 'V-03' => 'pass', 'V-04' => 'pass', 'V-05' => 'pass'])
        ->and($document->fresh()->checks()->whereNotNull('ocr_run_id')->count())->toBe(0);
});

it('confirms only with an acknowledgement while critical checks fail', function () {
    $document = readInvoice();
    $corrector = app(ReceiptCorrector::class);
    $corrector->save($document, correctedInput(['registration_number' => null]));   // V-03 critical on invoices

    expect(fn () => $corrector->confirm($document->fresh(), false))->toThrow(ValidationException::class);
    expect($document->fresh()->status)->toBe('needs_review');

    $corrector->confirm($document->fresh(), true);

    expect($document->fresh())
        ->status->toBe('confirmed')
        ->confirmed_at->not->toBeNull()
        ->error_message->toBeNull();
});

it('confirms without asking when every critical check passes, and reopens', function () {
    $document = readInvoice();
    $corrector = app(ReceiptCorrector::class);
    $corrector->save($document, correctedInput());

    $corrector->confirm($document->fresh(), false);
    expect($document->fresh()->status)->toBe('confirmed');

    $corrector->reopen($document->fresh());
    expect($document->fresh()->status)->toBe('needs_review');
});

it('creates the receipt when the document was not recognized as one', function () {
    $document = Document::factory()->status('extracted')->create(['type' => 'other']);

    app(ReceiptCorrector::class)->save($document, correctedInput());

    expect($document->fresh()->type)->toBe('invoice')
        ->and($document->fresh()->receipt->total)->toBe(1001404);
});
