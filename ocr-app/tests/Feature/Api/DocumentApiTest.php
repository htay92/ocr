<?php

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
});

it('requires login', function () {
    $this->postJson('/api/documents')->assertUnauthorized();
    $this->getJson('/api/documents')->assertUnauthorized();
});

it('stores several uploads privately and queues one job each', function () {
    Queue::fake();
    Sanctum::actingAs($this->user);

    $response = $this->postJson('/api/documents', ['files' => [
        UploadedFile::fake()->image('レシート.jpg', 400, 600),
        UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
        UploadedFile::fake()->create('kintai.csv', 1, 'text/csv'),
        UploadedFile::fake()->create('web-image.png', 10, 'image/avif'),
    ]]);

    $response->assertStatus(202)->assertJsonCount(4, 'data');
    $documents = Document::orderBy('id')->get();
    expect($documents->pluck('status')->unique()->all())->toBe(['pending'])
        ->and($documents[0]->original_name)->toBe('レシート.jpg')
        ->and($documents[0]->mime_type)->toBe('image/jpeg')
        ->and($documents[0]->sha256)->toBe(hash('sha256', Storage::disk('local')->get($documents[0]->file_path)))
        ->and($documents[0]->file_path)->toStartWith('documents/'.now()->format('Y/m').'/');
    Storage::disk('local')->assertExists($documents->pluck('file_path')->all());
    Queue::assertPushed(ProcessDocumentJob::class, 4);
});

it('rejects unsupported files with E-UPLOAD-01', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/documents', ['files' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'E-UPLOAD-01');
    expect(Document::count())->toBe(0);
});

it('rejects files over 20 MB with E-UPLOAD-02', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/documents', ['files' => [UploadedFile::fake()->create('huge.pdf', 20 * 1024 + 1, 'application/pdf')]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'E-UPLOAD-02');
});

it('shows a document with its selected run, runs and original file', function () {
    Sanctum::actingAs($this->user);
    Storage::disk('local')->put('documents/a.png', 'PNGDATA');
    $document = Document::factory()->for($this->user)->status('extracted')->create(['file_path' => 'documents/a.png', 'original_name' => 'a.png']);
    $run = $document->ocrRuns()->create(['engine' => 'paddle', 'lines' => [['text' => '合計 ¥1,280', 'confidence' => 0.96, 'bbox' => null, 'page' => 1]], 'avg_confidence' => 0.96]);
    $document->update(['selected_run_id' => $run->id]);

    $this->getJson("/api/documents/{$document->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'extracted')
        ->assertJsonPath('data.selected_run.text', '合計 ¥1,280')
        ->assertJsonPath('data.selected_run.avg_confidence', 0.96);
    $this->getJson("/api/documents/{$document->id}/runs")->assertOk()->assertJsonCount(1, 'data');
    expect($this->get("/api/documents/{$document->id}/file")->assertOk()->streamedContent())->toBe('PNGDATA');
});

it('hides other users\' documents', function () {
    Sanctum::actingAs($this->user);
    $document = Document::factory()->create();

    $this->getJson("/api/documents/{$document->id}")->assertNotFound();
    $this->getJson("/api/documents/{$document->id}/file")->assertNotFound();
    $this->postJson("/api/documents/{$document->id}/rerun")->assertNotFound();
    $this->getJson('/api/documents')->assertJsonCount(0, 'data');
});

it('lists documents filtered by status', function () {
    Sanctum::actingAs($this->user);
    Document::factory()->for($this->user)->status('failed')->create();
    Document::factory()->for($this->user)->status('extracted')->count(2)->create();

    $this->getJson('/api/documents?status=extracted')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/documents?status=nonsense')->assertUnprocessable();
});

it('re-runs a failed document with a chosen engine', function () {
    Sanctum::actingAs($this->user);
    Http::fake(['ocr-paddle:8000/ocr' => Http::response(ocrResponse(validReceiptLines()))]);
    Storage::disk('local')->put('documents/a.png', file_get_contents(tempImage()));
    $document = Document::factory()->for($this->user)->status('failed')
        ->create(['file_path' => 'documents/a.png', 'error_message' => 'E-OCR-01: paddle: down']);

    $this->postJson("/api/documents/{$document->id}/rerun", ['engine' => 'paddle'])->assertStatus(202);

    expect($document->fresh())
        ->status->toBe('extracted')
        ->error_message->toBeNull()
        ->and($document->ocrRuns()->count())->toBe(1);
});

it('only re-runs failed documents, with enabled engines', function () {
    Sanctum::actingAs($this->user);
    $extracted = Document::factory()->for($this->user)->status('extracted')->create();
    $failed = Document::factory()->for($this->user)->status('failed')->create();

    $this->postJson("/api/documents/{$extracted->id}/rerun")->assertStatus(409);
    $this->postJson("/api/documents/{$failed->id}/rerun", ['engine' => 'cloud'])->assertUnprocessable();
});

it('lists engines with their health', function () {
    Sanctum::actingAs($this->user);
    Http::fake(['ocr-paddle:8000/health' => Http::response(['engine' => 'paddle', 'version' => '3.7.0 / PP-OCRv5', 'ready' => true])]);

    $this->getJson('/api/engines')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'paddle')
        ->assertJsonPath('data.0.default', true)
        ->assertJsonPath('data.0.health.ready', true)
        ->assertJsonPath('data.1.enabled', false)
        ->assertJsonPath('fallback', ['paddle']);
});

it('deletes a document through the API', function () {
    Sanctum::actingAs($this->user);
    Storage::disk('local')->put('documents/a.png', 'PNG');
    $document = Document::factory()->for($this->user)->status('failed')->create(['file_path' => 'documents/a.png']);
    $busy = Document::factory()->for($this->user)->status('processing')->create();

    $this->deleteJson("/api/documents/{$document->id}")->assertNoContent();
    $this->deleteJson("/api/documents/{$busy->id}")->assertStatus(409);

    expect(Document::pluck('id')->all())->toBe([$busy->id]);
    Storage::disk('local')->assertMissing('documents/a.png');
});
