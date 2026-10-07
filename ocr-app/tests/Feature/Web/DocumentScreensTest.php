<?php

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('shows the upload screen with camera input', function () {
    $this->get('/documents/upload')
        ->assertOk()
        ->assertSee('capture="environment"', false)
        ->assertSee('name="files[]" multiple', false);
});

it('uploads several files from the browser', function () {
    Queue::fake();

    $this->post('/documents', ['files' => [
        UploadedFile::fake()->image('receipt.jpg'),
        UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf'),
    ]])
        ->assertRedirect('/documents')
        ->assertSessionHas('status', '2 files uploaded; they are being processed.');

    expect($this->user->documents()->count())->toBe(2);
    Queue::assertPushed(ProcessDocumentJob::class, 2);
});

it('shows upload errors with their code on the page', function () {
    $this->from('/documents/upload')
        ->post('/documents', ['files' => [UploadedFile::fake()->create('huge.pdf', 20 * 1024 + 1, 'application/pdf')]])
        ->assertRedirect('/documents/upload')
        ->assertSessionHasErrors(['code' => 'E-UPLOAD-02']);

    $this->followingRedirects()
        ->from('/documents/upload')
        ->post('/documents', ['files' => [UploadedFile::fake()->create('virus.exe', 1, 'application/x-msdownload')]])
        ->assertSee('E-UPLOAD-01');
});

it('lists only my documents, filtered by status, refreshing while busy', function () {
    Document::factory()->for($this->user)->status('extracted')->create(['original_name' => 'mine-done.png']);
    Document::factory()->for($this->user)->status('processing')->create(['original_name' => 'mine-busy.png']);
    Document::factory()->create(['original_name' => 'someone-else.png']);

    $this->get('/documents')
        ->assertOk()
        ->assertSee(['mine-done.png', 'mine-busy.png'])
        ->assertDontSee('someone-else.png')
        ->assertSee('http-equiv="refresh"', false);

    $this->get('/documents?status=extracted')
        ->assertSee('mine-done.png')
        ->assertDontSee('mine-busy.png')
        ->assertDontSee('http-equiv="refresh"', false);
});

it('shows the image beside the OCR lines and the run history', function () {
    Storage::disk('local')->put('documents/a.png', 'PNG');
    $document = Document::factory()->for($this->user)->status('extracted')->create(['file_path' => 'documents/a.png']);
    $run = $document->ocrRuns()->create([
        'engine' => 'paddle', 'engine_version' => '3.7.0 / PP-OCRv5', 'avg_confidence' => 0.7, 'elapsed_ms' => 34440,
        'lines' => [
            ['text' => 'ローソン', 'confidence' => 0.98, 'bbox' => null, 'page' => 1],
            ['text' => '合计 ¥1,280', 'confidence' => 0.42, 'bbox' => null, 'page' => 1],
        ],
    ]);
    $document->update(['selected_run_id' => $run->id]);

    $this->get("/documents/{$document->id}")
        ->assertOk()
        ->assertSee(route('documents.file', $document), false)
        ->assertSeeInOrder(['ローソン', '合计 ¥1,280'])
        ->assertSee('class="low"', false)
        ->assertSee('34.4 s')
        ->assertDontSee('Re-run with');
});

it('streams the original file inline to its owner only', function () {
    Storage::disk('local')->put('documents/a.png', 'PNGDATA');
    $mine = Document::factory()->for($this->user)->create(['file_path' => 'documents/a.png', 'original_name' => 'a.png']);
    $other = Document::factory()->create();

    $response = $this->get("/documents/{$mine->id}/file")->assertOk();
    expect($response->streamedContent())->toBe('PNGDATA')
        ->and($response->headers->get('content-disposition'))->toStartWith('inline');

    $this->get("/documents/{$other->id}")->assertNotFound();
    $this->get("/documents/{$other->id}/file")->assertNotFound();
});

it('re-runs a failed document from its page', function () {
    Http::fake(['ocr-paddle:8000/ocr' => Http::response(ocrResponse(validReceiptLines()))]);
    Storage::disk('local')->put('documents/a.png', file_get_contents(tempImage()));
    $document = Document::factory()->for($this->user)->status('failed')
        ->create(['file_path' => 'documents/a.png', 'error_message' => 'E-OCR-01: paddle: down']);

    $this->get("/documents/{$document->id}")->assertSee('E-OCR-01: paddle: down')->assertSee('Re-run with');

    $this->post("/documents/{$document->id}/rerun", ['engine' => 'paddle'])
        ->assertRedirect("/documents/{$document->id}");

    expect($document->fresh()->status)->toBe('extracted');
});

it('shows extracted fields, tax rows, line items and checks', function () {
    $document = Document::factory()->for($this->user)->status('needs_review')->create(['type' => 'invoice']);
    $run = $document->ocrRuns()->create(['engine' => 'paddle', 'lines' => [], 'avg_confidence' => 0.9]);
    $document->update(['selected_run_id' => $run->id]);
    $receipt = $document->receipt()->create([
        'vendor_name' => '株式会社○○', 'total' => 1001404, 'subtotal' => 1004000, 'withholding_tax' => 102916,
        'tax_mode' => 'exclusive', 'registration_number' => 'T1234567890123',
    ]);
    $receipt->taxes()->createMany([
        ['tax_rate' => 10, 'taxable_amount' => 1000000, 'tax_amount' => 100000],
        ['tax_rate' => 8, 'taxable_amount' => 4000, 'tax_amount' => 320],
    ]);
    $receipt->lines()->create(['line_no' => 1, 'name' => '商品A', 'quantity' => 1, 'unit_price' => 1000000, 'amount' => 1000000, 'tax_rate' => 10]);
    $run->validationResults()->createMany([
        ['document_id' => $document->id, 'rule_code' => 'V-05', 'level' => 'pass', 'message' => 'tax adds up'],
        ['document_id' => $document->id, 'rule_code' => 'V-02', 'level' => 'critical', 'message' => 'Issue date not found'],
    ]);

    $this->get("/documents/{$document->id}")
        ->assertOk()
        ->assertSee('請求書 (invoice)')
        ->assertSeeInOrder(['V-02', 'Issue date not found', 'V-05'])   // critical first
        ->assertSee(['株式会社○○', '¥1,001,404', '¥102,916', '外税 (tax excluded)', 'T1234567890123'])
        ->assertSee('missing')                                            // invoice number and date are required on invoices
        ->assertSeeInOrder(['10%', '¥1,000,000', '¥100,000', '8% (軽減)', '¥4,000', '¥320'])
        ->assertSee('商品A');

    $this->get('/documents')->assertSee(['株式会社○○', '請求書', '¥1,001,404']);
});
