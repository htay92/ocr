<?php

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\OcrRun;
use App\Models\Receipt;
use App\Models\ReceiptTax;
use App\Models\User;
use App\Models\ValidationResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('shows the upload screen with camera input and photo shrinking', function () {
    $this->get('/documents/upload')
        ->assertOk()
        ->assertSee('capture="environment"', false)
        ->assertSee('name="files[]" multiple', false)
        ->assertSee('id="shrink" checked', false)
        ->assertDontSee('Open on your phone');                            // PHONE_URL not set
});

it('shows the phone address as a QR code on this computer only', function () {
    config(['ocr.phone_url' => 'http://192.168.11.3:8080']);

    $this->get('http://localhost/documents/upload')
        ->assertSee('Open on your phone')
        ->assertSee('http://192.168.11.3:8080')
        ->assertSee('<svg', false);

    // Opened on the phone itself: no QR code.
    $this->get('http://192.168.11.3/documents/upload')->assertDontSee('Open on your phone');
});

it('answers an upload from the page script with JSON', function () {
    Queue::fake();

    $this->postJson('/documents', ['files' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]])
        ->assertOk()
        ->assertJson(['redirect' => route('documents.index'), 'count' => 2]);

    $this->get('/documents')->assertSee('2 files uploaded; they are being processed.');
    $this->postJson('/documents', ['files' => [UploadedFile::fake()->create('x.exe', 1, 'application/x-msdownload')]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'E-UPLOAD-01');
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
        ->assertSee('Read again');                                       // re-run is offered from the review screen too
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
        ->assertSee('Invoice')
        ->assertSeeInOrder(['V-02', 'Issue date not found', 'V-05'])   // critical first
        // The review form, filled with what was read.
        ->assertSee(['value="株式会社○○"', 'value="1001404"', 'value="102916"', 'value="T1234567890123"'], false)
        ->assertSee('<option value="exclusive" selected>', false)
        ->assertSeeInOrder(['taxes[0][taxable_amount]', 'value="1000000"', 'value="100000"', 'taxes[1][taxable_amount]', 'value="4000"', 'value="320"'], false)
        ->assertSee('value="商品A"', false)
        ->assertSee('Tax and total agree');                               // rule title

    $this->get('/documents')->assertSee(['株式会社○○', 'Invoice', '¥1,001,404']);
});

it('deletes a document with its file, runs, fields and checks', function () {
    Storage::disk('local')->put('documents/a.png', 'PNG');
    $document = Document::factory()->for($this->user)->status('needs_review')->create(['file_path' => 'documents/a.png']);
    $run = $document->ocrRuns()->create(['engine' => 'paddle', 'lines' => []]);
    $document->receipt()->create(['total' => 1280])->taxes()->create(['tax_rate' => 10, 'taxable_amount' => 1280, 'tax_amount' => 116]);
    $run->validationResults()->create(['document_id' => $document->id, 'rule_code' => 'V-01', 'level' => 'pass', 'message' => 'ok']);

    $this->get("/documents/{$document->id}")->assertSee('Delete');
    $this->delete("/documents/{$document->id}")
        ->assertRedirect('/documents')
        ->assertSessionHas('status', 'Document deleted.');

    expect(Document::count())->toBe(0)
        ->and(OcrRun::count())->toBe(0)
        ->and(Receipt::count())->toBe(0)
        ->and(ReceiptTax::count())->toBe(0)
        ->and(ValidationResult::count())->toBe(0);
    Storage::disk('local')->assertMissing('documents/a.png');
});

it('does not delete a document while it is processing, or someone else\'s', function () {
    $busy = Document::factory()->for($this->user)->status('processing')->create();
    $other = Document::factory()->status('extracted')->create();

    $this->get("/documents/{$busy->id}")->assertDontSee('Delete this document');
    $this->from("/documents/{$busy->id}")->delete("/documents/{$busy->id}")
        ->assertRedirect("/documents/{$busy->id}")
        ->assertSessionHasErrors('delete');
    $this->delete("/documents/{$other->id}")->assertNotFound();

    expect(Document::count())->toBe(2);
});

function reviewable(User $user): Document
{
    $document = Document::factory()->for($user)->status('needs_review')->create(['type' => 'receipt']);
    $run = $document->ocrRuns()->create(['engine' => 'paddle', 'lines' => [], 'avg_confidence' => 0.95, 'extracted' => ['fields' => ['total' => 1280], 'taxes' => [], 'lines' => []]]);
    $document->update(['selected_run_id' => $run->id]);
    $document->receipt()->create(['vendor_name' => 'ローソン', 'total' => 1280]);
    $run->validationResults()->create(['document_id' => $document->id, 'rule_code' => 'V-02', 'level' => 'critical', 'message' => 'no date']);

    return $document->fresh();
}

it('saves corrections typed as on paper and runs the checks again', function () {
    $document = reviewable($this->user);

    $this->put("/documents/{$document->id}", [
        'type' => 'receipt',
        'fields' => ['vendor_name' => 'ローソン 敷津西店', 'issue_date' => '2026-10-01', 'total' => '¥1,280'],
        'taxes' => [['tax_rate' => '8', 'taxable_amount' => '1,280', 'tax_amount' => '９４']],
        'lines' => [['name' => 'おにぎり', 'quantity' => '2', 'unit_price' => '640', 'amount' => '1,280', 'tax_rate' => '8'], ['name' => '', 'amount' => '']],
    ])->assertRedirect("/documents/{$document->id}")->assertSessionHas('status', 'Saved. The checks were run again.');

    $receipt = $document->fresh()->receipt;
    expect($receipt->vendor_name)->toBe('ローソン 敷津西店')
        ->and($receipt->taxes->first()->tax_amount)->toBe(94)
        ->and($receipt->lines)->toHaveCount(1)                       // the empty row is ignored
        ->and($receipt->corrected_fields)->toBe(['vendor_name', 'issue_date'])
        ->and($document->fresh()->checks()->pluck('level', 'rule_code')->only(['V-01', 'V-02', 'V-04', 'V-05'])->all())
        ->toBe(['V-01' => 'pass', 'V-02' => 'pass', 'V-04' => 'pass', 'V-05' => 'pass']);
});

it('shows input errors next to the field', function () {
    $document = reviewable($this->user);

    $this->from("/documents/{$document->id}")
        ->put("/documents/{$document->id}", ['type' => 'receipt', 'fields' => ['total' => 'abc', 'issue_date' => '10/1'], 'lines' => [['amount' => '100']]])
        ->assertRedirect("/documents/{$document->id}")
        ->assertSessionHasErrors(['fields.total', 'fields.issue_date', 'lines.0.name']);

    expect($document->fresh()->receipt->total)->toBe(1280);
});

it('asks for an acknowledgement before confirming with failing checks', function () {
    $document = reviewable($this->user);
    $form = ['type' => 'receipt', 'fields' => ['vendor_name' => 'ローソン', 'total' => '1280'], 'confirm' => '1'];   // still no date: V-02

    $this->get("/documents/{$document->id}")->assertSee('name="acknowledged"', false);
    $this->from("/documents/{$document->id}")->put("/documents/{$document->id}", $form)->assertSessionHasErrors('acknowledged');
    expect($document->fresh()->status)->toBe('needs_review');

    $this->put("/documents/{$document->id}", [...$form, 'acknowledged' => '1'])->assertSessionHas('status', 'Confirmed.');
    expect($document->fresh()->status)->toBe('confirmed');
});

it('locks a confirmed document until it is reopened', function () {
    $document = reviewable($this->user);
    $document->update(['status' => 'confirmed', 'confirmed_at' => now()]);

    $this->get("/documents/{$document->id}")
        ->assertSee('Edit again')
        ->assertDontSee('id="review-form"', false)
        ->assertDontSee('Delete this document');
    $this->from("/documents/{$document->id}")->put("/documents/{$document->id}", ['type' => 'receipt', 'fields' => ['total' => '1']])->assertSessionHasErrors('review');
    $this->delete("/documents/{$document->id}")->assertSessionHasErrors('delete');

    $this->post("/documents/{$document->id}/reopen")->assertSessionHas('status', 'Reopened for corrections.');
    expect($document->fresh()->status)->toBe('needs_review');
    $this->get("/documents/{$document->id}")->assertSee('id="review-form"', false);
});

it('serves the preprocessed page images to the owner, with the OCR boxes drawn over them', function () {
    Storage::disk('local')->put('documents/9/run-1-p1.jpg', 'JPEG');
    $document = reviewable($this->user);
    $run = $document->selectedRun;
    $run->update(['images' => [['page' => 1, 'path' => 'documents/9/run-1-p1.jpg', 'width' => 800, 'height' => 1200]],
        'lines' => [['text' => '合計 ¥1,280', 'confidence' => 0.5, 'bbox' => [[10, 20], [300, 20], [300, 60], [10, 60]], 'page' => 1]]]);

    $this->get("/documents/{$document->id}")
        ->assertSee('viewBox="0 0 800 1200"', false)
        ->assertSee('<rect x="10" y="20" width="290" height="40" class="box low">', false);
    expect($this->get("/documents/{$document->id}/runs/{$run->id}/pages/1")->assertOk()->streamedContent())->toBe('JPEG');
    $this->get("/documents/{$document->id}/runs/{$run->id}/pages/2")->assertNotFound();

    $other = Document::factory()->create();
    $this->get("/documents/{$other->id}/runs/{$run->id}/pages/1")->assertNotFound();
});

it('filters the list by date, vendor and amount, with the count and sum', function () {
    $make = function (string $vendor, string $date, int $total) {
        $document = Document::factory()->for($this->user)->status('extracted')->create(['type' => 'receipt', 'original_name' => "{$vendor}.jpg"]);
        $document->receipt()->create(['vendor_name' => $vendor, 'issue_date' => $date, 'total' => $total]);
    };
    $make('welcia 田無駅前店', '2025-11-08', 1020);
    $make('LAWSON 敷津西', '2026-10-01', 210);
    $make('ファミリーマート', '2026-10-03', 856);

    $this->get('/documents?date_from=2026-10-01&date_to=2026-10-31')
        ->assertSee(['LAWSON 敷津西', 'ファミリーマート'])->assertDontSee('welcia 田無駅前店')
        ->assertSee('2 documents')->assertSee('¥1,066');

    $this->get('/documents?vendor=welcia')->assertSee('welcia 田無駅前店')->assertDontSee('LAWSON 敷津西');
    $this->get('/documents?min_total=500&max_total=900')->assertSee('ファミリーマート')->assertDontSee('welcia 田無駅前店')->assertDontSee('LAWSON 敷津西');
    $this->get('/documents?min_total=x')->assertSessionHasErrors('min_total');
});
