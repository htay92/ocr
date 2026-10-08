<?php

use App\Models\Document;
use App\Ocr\Engines\HttpOcrEngine;
use App\Ocr\OcrManager;
use Illuminate\Support\Facades\Http;

it('uses paddle as the default engine', function () {
    $engine = app(OcrManager::class)->driver();

    expect($engine)->toBeInstanceOf(HttpOcrEngine::class)
        ->and($engine->name())->toBe('paddle');
});

it('resolves an engine that exists only in config (NFR-10)', function () {
    config(['ocr.engines.tesseract' => ['driver' => 'http', 'url' => 'http://ocr-tesseract:8000/', 'enabled' => true]]);
    Http::fake(['ocr-tesseract:8000/ocr' => Http::response(ocrResponse(overrides: ['engine' => 'tesseract']))]);

    $result = app(OcrManager::class)->driver('tesseract')->recognize(tempImage());

    expect($result->engine)->toBe('tesseract');
});

it('rejects unknown and disabled engines', function (string $name, string $message) {
    expect(fn () => app(OcrManager::class)->driver($name))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown' => ['abbyy', 'not configured'],
    'ndl when OCR_NDL_ENABLED=false' => ['ndl', 'disabled'],
    'cloud by default (NFR-09)' => ['cloud', 'disabled'],
]);

it('runs Google Cloud Vision as a contract container once enabled', function () {
    config(['ocr.engines.cloud.enabled' => true]);
    Http::fake(['ocr-cloud:8000/ocr' => Http::response(ocrResponse(overrides: ['engine' => 'cloud']))]);

    $engine = app(OcrManager::class)->driver('cloud');

    expect($engine)->toBeInstanceOf(HttpOcrEngine::class)
        ->and($engine->recognize(tempImage())->engine)->toBe('cloud');
});

it('leaves out the cloud engine once its monthly limit is used up', function () {
    config(['ocr.engines.cloud.enabled' => true, 'ocr.engines.cloud.monthly_limit' => 2, 'ocr.fallback' => ['paddle', 'cloud']]);
    $document = Document::factory()->create();
    $document->ocrRuns()->create(['engine' => 'cloud', 'lines' => []]);
    $document->ocrRuns()->create(['engine' => 'cloud', 'error_code' => 'engine_error']);   // failed runs do not count
    $this->travelTo(now()->subMonth(), fn () => $document->ocrRuns()->create(['engine' => 'cloud', 'lines' => []]));

    expect(app(OcrManager::class)->fallbackOrder())->toBe(['paddle', 'cloud']);

    $document->ocrRuns()->create(['engine' => 'cloud', 'lines' => []]);
    expect(app(OcrManager::class)->fallbackOrder())->toBe(['paddle']);
});

it('orders fallback engines from the default, without duplicates or disabled ones', function () {
    config(['ocr.fallback' => ['paddle', 'ndl', 'paddle']]);
    expect(app(OcrManager::class)->fallbackOrder())->toBe(['paddle']);

    config(['ocr.engines.ndl.enabled' => true, 'ocr.default' => 'ndl']);
    expect(app(OcrManager::class)->fallbackOrder())->toBe(['ndl', 'paddle']);
});

it('reads the fallback order from a comma-separated env value', function () {
    expect(config('ocr.fallback'))->toBe(['paddle', 'ndl']);
});
