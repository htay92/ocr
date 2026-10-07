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

it('runs OCR, saves the run and marks the document extracted', function () {
    Http::fake(['ocr-paddle:8000/ocr' => Http::response(ocrResponse())]);
    $document = storedDocument(tempImage());

    ProcessDocumentJob::dispatchSync($document);

    $document->refresh();
    $run = $document->selectedRun;
    expect($document->status)->toBe('extracted')
        ->and($document->route)->toBe('ocr')
        ->and($run->engine)->toBe('paddle')
        ->and($run->engine_version)->toBe('3.7.0 / PP-OCRv5')
        ->and($run->elapsed_ms)->toBe(2340)
        ->and((float) $run->avg_confidence)->toBe(0.97)
        ->and($run->text())->toBe("ローソン\n合計 ¥1,280")
        ->and($run->lines[1])->toMatchArray(['text' => '合計 ¥1,280', 'page' => 1]);
});

it('falls back to the next engine when the first one is down', function () {
    config(['ocr.engines.ndl.enabled' => true, 'ocr.retries' => 0]);
    Http::fake([
        'ocr-paddle:8000/*' => Http::failedConnection(),
        'ocr-ndl:8000/*' => Http::response(ocrResponse(overrides: ['engine' => 'ndl'])),
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
