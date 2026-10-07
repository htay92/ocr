<?php

use Tests\TestCase;

// Unit tests also boot the app, so config(), Http::fake() and Sleep::fake() work.
pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * A POST /ocr response in the spec 3.2 contract shape.
 *
 * @param  list<array{0: string, 1: float}>  $lines  text and confidence, top to bottom
 */
function ocrResponse(array $lines = [['ローソン', 0.98], ['合計 ¥1,280', 0.96]], array $overrides = []): array
{
    return array_merge([
        'engine' => 'paddle',
        'engine_version' => '3.7.0 / PP-OCRv5',
        'elapsed_ms' => 2340,
        'image' => ['width' => 1240, 'height' => 2480, 'rotation' => 0],
        'lines' => array_map(fn (array $line, int $i) => [
            'text' => $line[0],
            'confidence' => $line[1],
            'bbox' => [[102, 100 + $i * 50], [640, 100 + $i * 50], [640, 140 + $i * 50], [102, 140 + $i * 50]],
        ], $lines, array_keys($lines)),
        'preprocessed_image' => null,
    ], $overrides);
}

/** A temporary PNG of the given size; deleted when the test ends. */
function tempImage(int $width = 200, int $height = 100): string
{
    $path = tempnam(sys_get_temp_dir(), 'ocr').'.png';
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imagepng($image, $path);
    register_shutdown_function(fn () => @unlink($path));

    return $path;
}
