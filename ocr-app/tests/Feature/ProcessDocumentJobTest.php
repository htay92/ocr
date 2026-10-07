<?php

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake();
});

/** A pending document whose stored file is a copy of $source. */
function storedDocument(string $source, string $mime = 'image/png', string $extension = 'png'): Document
{
    $path = "documents/test.{$extension}";
    Storage::disk('local')->put($path, file_get_contents($source));

    return Document::factory()->create(['file_path' => $path, 'mime_type' => $mime, 'original_name' => "test.{$extension}"]);
}

it('runs OCR, extracts the receipt and marks the document extracted', function () {
    Http::fake(['ocr-paddle:8000/ocr' => Http::response(ocrResponse(validReceiptLines()))]);
    $document = storedDocument(tempImage());

    ProcessDocumentJob::dispatchSync($document);

    $document->refresh();
    $run = $document->selectedRun;
    expect($document->status)->toBe('extracted')
        ->and($document->route)->toBe('ocr')
        ->and($document->type)->toBe('receipt')
        ->and($run->engine)->toBe('paddle')
        ->and($run->engine_version)->toBe('3.7.0 / PP-OCRv5')
        ->and($run->elapsed_ms)->toBe(2340)
        ->and((float) $run->avg_confidence)->toBe(0.97)
        ->and($run->critical_failures)->toBe(0)
        ->and($run->lines[2])->toMatchArray(['text' => '合計 ¥1,280', 'page' => 1])
        ->and($document->receipt->only(['vendor_name', 'total']))->toBe(['vendor_name' => 'ローソン', 'total' => 1280])
        ->and($document->receipt->issue_date->format('Y-m-d'))->toBe('2026-10-01')
        ->and($document->checks()->pluck('level', 'rule_code')->all())
        ->toMatchArray(['V-01' => 'pass', 'V-02' => 'pass', 'V-09' => 'pass', 'V-10' => 'pass']);
});

it('falls back to the next engine when the first one fails a critical check (FR-09)', function () {
    config(['ocr.engines.ndl.enabled' => true]);
    Http::fake([
        'ocr-paddle:8000/*' => Http::response(ocrResponse([['ローソン', 0.6], ['合計 ¥1,28O', 0.5]])), // no date: V-02
        'ocr-ndl:8000/*' => Http::response(ocrResponse(validReceiptLines(), ['engine' => 'ndl'])),
    ]);
    $document = storedDocument(tempImage());

    ProcessDocumentJob::dispatchSync($document);

    $document->refresh();
    expect($document->status)->toBe('extracted')
        ->and($document->selectedRun->engine)->toBe('ndl')
        ->and($document->ocrRuns()->pluck('critical_failures', 'engine')->all())->toBe(['paddle' => 1, 'ndl' => 0]);
});

it('needs review when every engine fails a critical check, keeping the best run', function () {
    config(['ocr.engines.ndl.enabled' => true]);
    Http::fake([
        'ocr-paddle:8000/*' => Http::response(ocrResponse([['ローソン', 0.9], ['小計 ¥500', 0.9]])),               // V-01 and V-02
        'ocr-ndl:8000/*' => Http::response(ocrResponse([['ローソン', 0.9], ['合計 ¥1,280', 0.9]], ['engine' => 'ndl'])), // V-02 only
    ]);
    $document = storedDocument(tempImage());

    ProcessDocumentJob::dispatchSync($document);

    $document->refresh();
    expect($document->status)->toBe('needs_review')
        ->and($document->error_message)->toBe('E-OCR-02: critical checks failed (V-02); best result from ndl')
        ->and($document->selectedRun->engine)->toBe('ndl')
        ->and($document->receipt->total)->toBe(1280)
        ->and($document->checks()->firstWhere('rule_code', 'V-02')->level)->toBe('critical');
});

it('only classifies attendance sheets; the importer reads them later', function () {
    Http::fake(['*' => Http::response(ocrResponse([['【勤務データ】山田_2026年10月1日~2026年10月31日', 0.9], ['所定時間 労働時間', 0.9]]))]);
    $document = storedDocument(tempImage());

    ProcessDocumentJob::dispatchSync($document);

    expect($document->fresh())
        ->status->toBe('extracted')
        ->type->toBe('attendance')
        ->and($document->receipt()->exists())->toBeFalse();
});

it('falls back to the next engine when the first one is down', function () {
    config(['ocr.engines.ndl.enabled' => true, 'ocr.retries' => 0]);
    Http::fake([
        'ocr-paddle:8000/*' => Http::failedConnection(),
        'ocr-ndl:8000/*' => Http::response(ocrResponse(validReceiptLines(), ['engine' => 'ndl'])),
    ]);
    $document = storedDocument(tempImage());

    ProcessDocumentJob::dispatchSync($document);

    $document->refresh();
    expect($document->status)->toBe('extracted')
        ->and($document->ocrRuns()->pluck('error_code', 'engine')->all())->toBe(['paddle' => 'E-OCR-01', 'ndl' => null])
        ->and($document->selectedRun->engine)->toBe('ndl');
});

it('fails the document when every engine fails', function () {
    config(['ocr.retries' => 0]);
    Http::fake(['*' => Http::failedConnection()]);
    $document = storedDocument(tempImage());

    ProcessDocumentJob::dispatchSync($document);

    $document->refresh();
    expect($document->status)->toBe('failed')
        ->and($document->error_message)->toStartWith('E-OCR-01: paddle:')
        ->and($document->selected_run_id)->toBeNull()
        ->and($document->ocrRuns)->toHaveCount(1);
});

it('does not try other engines on an invalid image', function () {
    config(['ocr.engines.ndl.enabled' => true]);
    Http::fake(['*' => Http::response(['code' => 'invalid_image', 'message' => 'Unreadable image'], 400)]);
    $document = storedDocument(tempImage());

    ProcessDocumentJob::dispatchSync($document);

    expect($document->fresh()->status)->toBe('failed')
        ->and($document->fresh()->error_message)->toStartWith('invalid_image:');
    Http::assertSentCount(1);
});

it('reads a born-digital PDF from its text layer without OCR', function () {
    Http::fake();
    $document = storedDocument(pdfFile([['INVOICE INV-2026-001', 'Total 550,000']]), 'application/pdf', 'pdf');

    ProcessDocumentJob::dispatchSync($document);

    $document->refresh();
    expect($document->status)->toBe('extracted')
        ->and($document->route)->toBe('pdf_text')
        ->and($document->selectedRun->engine)->toBe('pdf_text')
        ->and($document->selectedRun->text())->toBe("INVOICE INV-2026-001\nTotal 550,000");
    Http::assertNothingSent();
})->skip(fn () => ! hasPoppler(), 'poppler-utils not installed');

it('OCRs every page of a scanned PDF and merges them into one run', function () {
    Http::fake(['*' => Http::sequence()
        ->push(ocrResponse([['page one', 0.9]]))
        ->push(ocrResponse([['page two', 0.7]]))]);
    $document = storedDocument(pdfFile([[], []]), 'application/pdf', 'pdf');

    ProcessDocumentJob::dispatchSync($document);

    $run = $document->fresh()->selectedRun;
    expect($run->lines)->toHaveCount(2)
        ->and(array_column($run->lines, 'page'))->toBe([1, 2])
        ->and($run->text())->toBe("page one\npage two")
        ->and((float) $run->avg_confidence)->toBe(0.8)
        ->and($run->elapsed_ms)->toBe(4680);
    Http::assertSentCount(2);
})->skip(fn () => ! hasPoppler(), 'poppler-utils not installed');

it('leaves spreadsheets for the attendance importer', function () {
    Http::fake();
    $csv = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($csv, "日付,勤務区分\n10/1,出勤\n");
    $document = storedDocument($csv, 'text/csv', 'csv');

    ProcessDocumentJob::dispatchSync($document);

    expect($document->fresh())
        ->status->toBe('failed')
        ->route->toBe('spreadsheet')
        ->error_message->toContain('milestone 6');
    Http::assertNothingSent();
});

it('marks the document failed when the job itself crashes', function () {
    $document = Document::factory()->status('processing')->create();

    (new ProcessDocumentJob($document))->failed(new RuntimeException('Worker timed out'));

    expect($document->fresh())
        ->status->toBe('failed')
        ->error_message->toBe('Worker timed out');
});

it('keeps the preprocessed page, what the run extracted and where each field was read', function () {
    $jpeg = base64_encode(file_get_contents(tempImage(120, 80)));
    Http::fake(['*' => Http::response(ocrResponse(validReceiptLines(), ['preprocessed_image' => $jpeg]))]);
    $document = storedDocument(tempImage());

    ProcessDocumentJob::dispatchSync($document);

    $document->refresh();
    $run = $document->selectedRun;
    expect($run->images)->toBe([['page' => 1, 'path' => "documents/{$document->id}/run-{$run->id}-p1.jpg", 'width' => 1240, 'height' => 2480]])
        ->and(Storage::disk('local')->get($run->images[0]['path']))->toBe(base64_decode($jpeg))
        ->and($run->extracted['fields']['total'])->toBe(1280)
        ->and($document->receipt->field_boxes['total'])->toBe(['page' => 1, 'box' => [102, 200, 640, 240]]);
    Http::assertSent(fn ($request) => collect($request->data())->firstWhere('name', 'return_image')['contents'] === 'true');
});
