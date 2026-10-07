<?php

use App\Ocr\Data\OcrLine;
use App\Ocr\Data\OcrResult;

it('maps a contract response to an engine-neutral result', function () {
    $result = OcrResult::fromResponse(ocrResponse());

    expect($result->engine)->toBe('paddle')
        ->and($result->engineVersion)->toBe('3.7.0 / PP-OCRv5')
        ->and($result->elapsedMs)->toBe(2340)
        ->and($result->image)->toBe(['width' => 1240, 'height' => 2480, 'rotation' => 0])
        ->and($result->lines)->toHaveCount(2)
        ->and($result->lines[1])->toBeInstanceOf(OcrLine::class)
        ->and($result->lines[1]->text)->toBe('合計 ¥1,280')
        ->and($result->lines[1]->confidence)->toBe(0.96)
        ->and($result->lines[1]->bbox)->toBe([[102, 150], [640, 150], [640, 190], [102, 190]]);
});

it('joins line texts and averages confidence', function () {
    $result = OcrResult::fromResponse(ocrResponse([['A', 0.9], ['B', 0.8], ['C', 0.7]]));

    expect($result->text())->toBe("A\nB\nC")
        ->and($result->avgConfidence())->toBe(0.8);
});

it('has no average confidence when nothing was read', function () {
    $result = OcrResult::fromResponse(ocrResponse([]));

    expect($result->text())->toBe('')
        ->and($result->avgConfidence())->toBeNull();
});

it('round-trips to the contract shape', function () {
    $response = ocrResponse();

    expect(OcrResult::fromResponse($response)->toArray())->toBe($response);
});
