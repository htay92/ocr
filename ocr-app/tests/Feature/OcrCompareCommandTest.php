<?php

use App\Models\OcrRun;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['ocr.engines.ndl.enabled' => true]);
    $this->dir = sys_get_temp_dir().'/compare-'.uniqid();
    mkdir($this->dir);
    copy(tempImage(), "{$this->dir}/レシート１.png");
    file_put_contents("{$this->dir}/expected.json", json_encode(['レシート１.png' => ['type' => 'receipt', 'fields' => [
        'vendor_name' => 'ローソン', 'issue_date' => '2026-10-01', 'total' => 1280, 'registration_number' => null,
    ]]]));
});

afterEach(fn () => array_map('unlink', glob("{$this->dir}/*")) && rmdir($this->dir));

it('compares the engines on sample files against the ground truth', function () {
    // paddle loses the total's amount (V-01 critical); ndl reads everything.
    Http::fake([
        'ocr-paddle:8000/*' => Http::response(ocrResponse([['ローソン', 0.98], ['2026年10月1日 12:00', 0.97], ['合計 ¥', 0.6]])),
        'ocr-ndl:8000/*' => Http::response(ocrResponse(validReceiptLines(), ['engine' => 'ndl', 'engine_version' => 'ndlocr-lite 636d1cf', 'elapsed_ms' => 900])),
    ]);

    $this->artisan('ocr:compare', ['paths' => [$this->dir]])
        ->expectsOutputToContain('レシート１.png')
        ->expectsTable(['engine', 'files', 'no critical', 'key fields (NFR-01)', 'avg s', 'errors'], [
            ['paddle', 1, '0/1', '3/4 (75%)', '2.3', 0],
            ['ndl', 1, '1/1', '4/4 (100%)', '0.9', 0],
        ])
        ->assertSuccessful();

    $this->artisan('ocr:compare', ['paths' => [$this->dir]])
        ->expectsOutputToContain('Fallback: 1 file(s) with critical failures on paddle pass on a later engine: レシート１.png')
        ->assertSuccessful();

    expect(OcrRun::count())->toBe(0);                                        // nothing is saved
});

it('reports engine errors and keeps going', function () {
    Http::fake([
        'ocr-paddle:8000/*' => Http::response(['code' => 'invalid_image', 'message' => 'bad'], 400),
        'ocr-ndl:8000/*' => Http::response(ocrResponse(validReceiptLines(), ['engine' => 'ndl'])),
    ]);

    $this->artisan('ocr:compare', ['paths' => ["{$this->dir}/レシート１.png"], '--engines' => 'paddle,ndl'])
        ->expectsOutputToContain('[invalid_image]')
        ->assertSuccessful();
});

it('needs files', function () {
    $this->artisan('ocr:compare', ['paths' => [$this->dir.'/none']])->assertFailed();
});
