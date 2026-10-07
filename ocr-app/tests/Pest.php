<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Unit tests also boot the app, so config(), Http::fake() and Sleep::fake() work.
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Unit');

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

/**
 * A temporary PDF. Each page is a list of text lines (a born-digital PDF with a
 * text layer), or an empty list for a page with only a drawn box, like a scan.
 *
 * @param  list<list<string>>  $pages
 */
function pdfFile(array $pages): string
{
    $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $kids = [];

    foreach ($pages as $lines) {
        $stream = $lines === []
            ? '0 0 0 RG 4 w 72 72 400 600 re S'
            : "BT /F1 14 Tf 72 720 Td 18 TL\n".implode('', array_map(fn ($line) => '('.addcslashes($line, '()\\').") Tj T*\n", $lines)).'ET';
        $pageId = count($objects) + 1;
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents '.($pageId + 1).' 0 R /Resources << /Font << /F1 3 0 R >> >> >>';
        $objects[$pageId + 1] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        $kids[] = "{$pageId} 0 R";
    }
    $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $id => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
    }
    $xref = strlen($pdf);
    $size = count($objects) + 1;
    $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer << /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

    $path = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
    file_put_contents($path, $pdf);
    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

function hasPoppler(): bool
{
    return trim((string) shell_exec('command -v pdftotext')) !== '' && trim((string) shell_exec('command -v pdftoppm')) !== '';
}

/**
 * OCR lines of a receipt that passes every critical check (V-01 total, V-02 date).
 *
 * @return list<array{0: string, 1: float}>
 */
function validReceiptLines(): array
{
    return [['ローソン', 0.98], ['2026年10月1日 12:00', 0.97], ['合計 ¥1,280', 0.96]];
}
