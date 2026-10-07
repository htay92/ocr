<?php

use App\Ocr\Engines\HttpOcrEngine;
use App\Ocr\OcrException;
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
    'ndl until its container exists' => ['ndl', 'disabled'],
    'cloud by default (NFR-09)' => ['cloud', 'disabled'],
]);

it('refuses to run the cloud engine before it is implemented', function () {
    config(['ocr.engines.cloud.enabled' => true]);

    expect(fn () => app(OcrManager::class)->driver('cloud')->recognize(tempImage()))
        ->toThrow(OcrException::class);
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
