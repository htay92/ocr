<?php

use App\Ocr\Engines\HttpOcrEngine;
use App\Ocr\OcrException;
use App\Ocr\OcrManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(fn () => Sleep::fake());

function paddle(): HttpOcrEngine
{
    return app(OcrManager::class)->driver('paddle');
}

/** @return array<string, string> multipart field name => contents */
function multipart(Request $request): array
{
    return collect($request->data())->mapWithKeys(fn (array $part) => [$part['name'] => $part['contents']])->all();
}

it('posts the page to the engine and returns its lines', function () {
    Http::fake(['ocr-paddle:8000/ocr' => Http::response(ocrResponse())]);
    $path = tempImage();

    $result = paddle()->recognize($path, ['return_image' => true]);

    expect($result->text())->toBe("ローソン\n合計 ¥1,280");
    Http::assertSent(function (Request $request) use ($path) {
        $fields = multipart($request);

        return $request->url() === 'http://ocr-paddle:8000/ocr'
            && $request->isMultipart()
            && $fields['file'] === file_get_contents($path)
            && $fields['preprocess'] === 'true'
            && $fields['lang'] === 'ja'
            && $fields['return_image'] === 'true';
    });
});

it('retries 500 and 503 with the 10 s / 30 s backoff', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['code' => 'not_ready', 'message' => 'loading'], 503)
        ->push(['code' => 'engine_error', 'message' => 'boom'], 500)
        ->push(ocrResponse())]);

    $result = paddle()->recognize(tempImage());

    expect($result->lines)->toHaveCount(2);
    Http::assertSentCount(3);
    Sleep::assertSequence([Sleep::for(10)->seconds(), Sleep::for(30)->seconds()]);
});

it('gives up after the configured retries and keeps the contract error code', function () {
    config(['ocr.retries' => 2]);
    Http::fake(['*' => Http::response(['code' => 'engine_error', 'message' => 'model crashed'], 500)]);

    expect(fn () => paddle()->recognize(tempImage()))
        ->toThrow(fn (OcrException $e) => expect($e->errorCode)->toBe('engine_error')
            ->and($e->getMessage())->toContain('model crashed'));
    Http::assertSentCount(3);
});

it('does not retry an invalid image', function () {
    Http::fake(['*' => Http::response(['code' => 'invalid_image', 'message' => 'Unreadable image'], 400)]);

    expect(fn () => paddle()->recognize(tempImage()))
        ->toThrow(fn (OcrException $e) => expect($e->errorCode)->toBe('invalid_image'));
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('downscales once and resends when the engine says the image is too large', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['code' => 'too_large', 'message' => 'Image side is over 8000 px'], 413)
        ->push(ocrResponse())]);

    paddle()->recognize(tempImage(9000, 300));

    $sizes = Http::recorded()->map(fn (array $pair) => getimagesizefromstring(multipart($pair[0])['file']));
    expect($sizes[0][0])->toBe(9000)
        ->and([$sizes[1][0], $sizes[1][1]])->toBe([4000, 133]);
});

it('fails when the image is still too large after downscaling', function () {
    Http::fake(['*' => Http::response(['code' => 'too_large', 'message' => 'too big'], 413)]);

    expect(fn () => paddle()->recognize(tempImage(9000, 300)))
        ->toThrow(fn (OcrException $e) => expect($e->errorCode)->toBe('too_large'));
    Http::assertSentCount(2);
});

it('reports E-OCR-01 when the engine stays unreachable', function () {
    Http::fake(['*' => Http::failedConnection()]);

    expect(fn () => paddle()->recognize(tempImage()))
        ->toThrow(fn (OcrException $e) => expect($e->errorCode)->toBe('E-OCR-01'));
    Http::assertSentCount(4); // first try + 3 retries
    Sleep::assertSequence([Sleep::for(10)->seconds(), Sleep::for(30)->seconds(), Sleep::for(90)->seconds()]);
});

it('rejects a missing file without calling the engine', function () {
    Http::fake();

    expect(fn () => paddle()->recognize('/nope/missing.png'))
        ->toThrow(fn (OcrException $e) => expect($e->errorCode)->toBe('invalid_image'));
    Http::assertNothingSent();
});

it('reads the engine health', function () {
    Http::fake(['ocr-paddle:8000/health' => Http::response(['engine' => 'paddle', 'version' => '3.7.0 / PP-OCRv5', 'ready' => true])]);

    expect(paddle()->health())->toBe(['engine' => 'paddle', 'version' => '3.7.0 / PP-OCRv5', 'ready' => true]);
});

it('reports an unreachable engine as not ready', function () {
    Http::fake(['*' => Http::failedConnection()]);

    expect(paddle()->health())->toMatchArray(['engine' => 'paddle', 'ready' => false]);
});
