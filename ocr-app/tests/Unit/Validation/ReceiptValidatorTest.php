<?php

use App\Extraction\Data\ExtractedDocument;
use App\Models\Document;
use App\Validation\ReceiptValidator;
use App\Validation\RuleResult;
use App\Validation\ValidationContext;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow('2026-10-07 12:00:00'));

/** The level of one rule for a document. */
function level(string $code, array $fields = [], array $taxes = [], array $lines = [], string $type = 'receipt', ?float $confidence = 0.95, ?int $documentId = null): string
{
    $results = (new ReceiptValidator)->validate(new ValidationContext(
        new ExtractedDocument($fields, $taxes, $lines), $type, $confidence, $documentId,
    ));

    return collect($results)->firstWhere('code', $code)->level;
}

function tax(int $rate, int $taxable, int $tax): array
{
    return ['tax_rate' => $rate, 'taxable_amount' => $taxable, 'tax_amount' => $tax];
}

function line(int $amount, int $rate = 10, int $discount = 0): array
{
    return ['line_no' => 1, 'name' => 'x', 'quantity' => 1.0, 'unit_price' => null, 'amount' => $amount, 'tax_rate' => $rate, 'discount' => $discount];
}

it('V-01: total exists and is greater than 0 (critical)', function (?int $total, string $level) {
    expect(level('V-01', ['total' => $total]))->toBe($level);
})->with([[1280, 'pass'], [0, 'critical'], [null, 'critical']]);

it('V-02: issue date is valid, not in the future, not older than 10 years (critical)', function (?string $date, string $level) {
    expect(level('V-02', ['issue_date' => $date]))->toBe($level);
})->with([
    ['2026-10-07', 'pass'],
    ['2016-10-08', 'pass'],
    ['2026-10-08', 'critical'],   // future
    ['2016-10-06', 'critical'],   // older than 10 years
    [null, 'critical'],
]);

it('V-03: registration number is T + 13 digits (critical for invoices, warning otherwise)', function (?string $number, string $type, string $level) {
    expect(level('V-03', ['registration_number' => $number], type: $type))->toBe($level);
})->with([
    ['T1234567890123', 'invoice', 'pass'],
    ['T12345', 'invoice', 'critical'],
    [null, 'invoice', 'critical'],          // required on invoices (spec 1.4)
    ['T12345', 'receipt', 'warning'],
    [null, 'receipt', 'skipped'],           // optional on receipts: no data, no check
]);

it('V-04: line amounts plus discounts equal the subtotal, or the total (critical when lines exist)', function (array $fields, array $lines, string $level) {
    $taxes = is_array($fields['taxes'] ?? null) ? $fields['taxes'] : (isset($fields['taxes']) ? [tax(8, 1465, 117)] : []);
    unset($fields['taxes']);
    expect(level('V-04', $fields, $taxes, $lines))->toBe($level);
})->with([
    'subtotal' => [['subtotal' => 560, 'total' => 600], [line(150), line(260), line(200, discount: -50)], 'pass'],
    'total when no subtotal' => [['total' => 410], [line(150), line(260)], 'pass'],
    'mismatch' => [['subtotal' => 560], [line(150), line(260)], 'critical'],
    'no lines' => [['subtotal' => 560], [], 'skipped'],
    // Tei's sample レシート５: tax-exclusive items, no 小計: 1,371 + 47 + 47 = 1,582 − 117.
    // Tei's sample 請求書３: no 税抜/外税 word printed, 815,000 = 896,500 − 81,500.
    'unknown tax mode, total before tax' => [['total' => 896500, 'taxes' => [tax(10, 815000, 81500)]], [line(120000), line(175000), line(200000), line(160000), line(160000)], 'pass'],
    'exclusive without subtotal' => [['tax_mode' => 'exclusive', 'total' => 1582, 'taxes' => true], [line(1371, 8), line(47, 8), line(47, 8)], 'pass'],
]);

it('V-05: inclusive taxable sum equals the total; exclusive subtotal + tax − withholding equals the total', function (array $fields, array $taxes, string $level) {
    expect(level('V-05', $fields, $taxes, type: 'invoice'))->toBe($level);
})->with([
    'inclusive' => [['tax_mode' => 'inclusive', 'total' => 1100], [tax(8, 1100, 81)], 'pass'],
    'inclusive mismatch' => [['tax_mode' => 'inclusive', 'total' => 15], [tax(8, 210, 15)], 'critical'],
    // Tei's sample 請求書４: 1,004,000 + 100,320 − 102,916 = 1,001,404.
    'exclusive with withholding' => [
        ['tax_mode' => 'exclusive', 'subtotal' => 1004000, 'withholding_tax' => 102916, 'total' => 1001404],
        [tax(10, 1000000, 100000), tax(8, 4000, 320)], 'pass',
    ],
    'exclusive without subtotal uses the taxable base' => [['tax_mode' => 'exclusive', 'total' => 1582], [tax(8, 1465, 117)], 'pass'],
    'exclusive mismatch' => [['tax_mode' => 'exclusive', 'subtotal' => 300000, 'total' => 333000], [tax(10, 300000, 30000)], 'critical'],
    'no tax rows' => [['total' => 1000], [], 'skipped'],
]);

it('V-06: tax amount matches its rate within 1 yen (warning)', function (string $mode, array $taxes, string $level) {
    expect(level('V-06', ['tax_mode' => $mode], $taxes))->toBe($level);
})->with([
    ['inclusive', [tax(8, 1100, 81)], 'pass'],          // 1100 × 8 / 108 = 81.48
    ['exclusive', [tax(10, 300000, 30000)], 'pass'],
    ['exclusive', [tax(10, 300000, 3000)], 'warning'],
]);

it('V-07: cash received minus total equals change (warning)', function (array $fields, string $level) {
    expect(level('V-07', $fields))->toBe($level);
})->with([
    [['total' => 1080, 'cash_received' => 2000, 'change' => 920], 'pass'],
    [['total' => 1080, 'cash_received' => 2000, 'change' => 900], 'warning'],
    [['total' => 1080], 'skipped'],
]);

it('V-08: lines per rate add up to the taxable amount of that rate (warning)', function (array $lines, string $level) {
    expect(level('V-08', ['tax_mode' => 'inclusive'], [tax(8, 300, 22), tax(10, 260, 23)], $lines))->toBe($level);
})->with([
    [[line(150, 8), line(200, 8, -50), line(260, 10)], 'pass'],
    [[line(150, 8), line(260, 10)], 'warning'],
    [[], 'skipped'],
]);

it('V-09: average OCR confidence is 0.80 or higher (warning)', function (?float $confidence, string $level) {
    expect(level('V-09', confidence: $confidence))->toBe($level);
})->with([[0.92, 'pass'], [0.79, 'warning'], [null, 'skipped']]);

it('V-10: no other document with the same vendor, date and total (warning)', function () {
    $other = Document::factory()->create();
    $other->receipt()->create(['vendor_name' => 'LAWSON', 'issue_date' => '2026-10-01', 'total' => 210]);
    $mine = Document::factory()->create();

    $fields = ['vendor_name' => 'LAWSON', 'issue_date' => '2026-10-01', 'total' => 210];
    expect(level('V-10', $fields, documentId: $mine->id))->toBe('warning')
        ->and(level('V-10', $fields, documentId: $other->id))->toBe('pass')
        ->and(level('V-10', [...$fields, 'total' => 211], documentId: $mine->id))->toBe('pass')
        ->and(level('V-10', ['total' => 210], documentId: $mine->id))->toBe('skipped');
});

it('counts only critical failures', function () {
    $results = (new ReceiptValidator)->validate(new ValidationContext(new ExtractedDocument(['total' => null]), 'receipt', 0.5));

    expect(RuleResult::criticalCount($results))->toBe(2)    // V-01 total, V-02 date
        ->and(collect($results)->pluck('code')->all())->toBe(['V-01', 'V-02', 'V-03', 'V-04', 'V-05', 'V-06', 'V-07', 'V-08', 'V-09', 'V-10', 'V-11']);
});

it('V-11: the image is large enough to read (warning)', function (?array $size, string $level) {
    $results = (new ReceiptValidator)->validate(new ValidationContext(new ExtractedDocument(['total' => 1280]), 'invoice', 0.9, imageSize: $size));
    $result = collect($results)->firstWhere('code', 'V-11');

    expect($result->level)->toBe($level);
    if ($level === 'warning') {
        expect($result->message)->toContain('375×533');
    }
})->with([
    'thumbnail' => [[375, 533], 'warning'],
    'A4 at 150 dpi' => [[1240, 1754], 'pass'],
    'no image (PDF text, spreadsheet)' => [null, 'skipped'],
]);

it('V-04: tax-included line prices may add up to the total of a tax-exclusive document', function () {
    $fields = ['subtotal' => 7000, 'total' => 7700, 'tax_mode' => 'exclusive'];

    expect(level('V-04', $fields, [tax(10, 7000, 700)], [line(7700)], 'invoice'))->toBe('pass')
        ->and(level('V-04', $fields, [tax(10, 7000, 700)], [line(7000)], 'invoice'))->toBe('pass')
        ->and(level('V-04', $fields, [tax(10, 7000, 700)], [line(7500)], 'invoice'))->toBe('critical');
});

it('V-08: tax-included line prices match taxable amount plus tax on a tax-exclusive document', function () {
    expect(level('V-08', ['tax_mode' => 'exclusive'], [tax(10, 7000, 700)], [line(7700)], 'invoice'))->toBe('pass')
        ->and(level('V-08', ['tax_mode' => 'inclusive'], [tax(10, 7000, 700)], [line(7700)], 'invoice'))->toBe('warning');
});
