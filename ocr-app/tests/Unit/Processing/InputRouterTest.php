<?php

use App\Processing\InputRouter;
use App\Processing\ProcessingException;

it('sends images to OCR and spreadsheets to the importer', function (string $file, string $mime, string $route) {
    expect(app(InputRouter::class)->route($file, $mime))->toBe($route);
})->with([
    'jpeg' => ['/tmp/a.jpg', 'image/jpeg', 'ocr'],
    'png' => ['/tmp/a.png', 'image/png', 'ocr'],
    'heic' => ['/tmp/a.heic', 'image/heic', 'ocr'],
    'avif behind a .png name' => ['/tmp/a.png', 'image/avif', 'ocr'],
    'csv detected as text' => ['/tmp/a.csv', 'text/plain', 'spreadsheet'],
    'xlsx' => ['/tmp/a.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'spreadsheet'],
    'xls' => ['/tmp/a.xls', 'application/vnd.ms-excel', 'spreadsheet'],
]);

it('reads PDFs with a text layer directly and OCRs image-only PDFs', function () {
    $router = app(InputRouter::class);

    expect($router->route(pdfFile([['INVOICE INV-2026-001', 'Total 550,000']]), 'application/pdf'))->toBe('pdf_text')
        ->and($router->route(pdfFile([[]]), 'application/pdf'))->toBe('ocr');
})->skip(fn () => ! hasPoppler(), 'poppler-utils not installed');

it('reports a broken PDF as E-PDF-01', function () {
    $path = tempnam(sys_get_temp_dir(), 'bad').'.pdf';
    file_put_contents($path, '%PDF-1.4 this is not really a PDF');

    expect(fn () => app(InputRouter::class)->route($path, 'application/pdf'))
        ->toThrow(fn (ProcessingException $e) => expect($e->errorCode)->toBe('E-PDF-01'));
})->skip(fn () => ! hasPoppler(), 'poppler-utils not installed');

it('rejects other file types', function () {
    expect(fn () => app(InputRouter::class)->route('/tmp/a.txt', 'text/html'))
        ->toThrow(fn (ProcessingException $e) => expect($e->errorCode)->toBe('E-UPLOAD-01'));
});
