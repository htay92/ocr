<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

it('prints the lines an engine read', function () {
    Http::fake(['*' => Http::response(ocrResponse())]);

    $this->artisan('ocr:run', ['path' => tempImage()])
        ->expectsOutputToContain('paddle 3.7.0 / PP-OCRv5')
        ->expectsOutputToContain('合計 ¥1,280')
        ->assertSuccessful();
});

it('prints the contract JSON', function () {
    Http::fake(['*' => Http::response(ocrResponse())]);

    $this->artisan('ocr:run', ['path' => tempImage(), '--json' => true])
        ->expectsOutputToContain('"engine_version": "3.7.0 / PP-OCRv5"')
        ->assertSuccessful();
});

it('shows the error code when the engine fails', function () {
    Sleep::fake();
    Http::fake(['*' => Http::failedConnection()]);

    $this->artisan('ocr:run', ['path' => tempImage()])
        ->expectsOutputToContain('E-OCR-01')
        ->assertFailed();
});
