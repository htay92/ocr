<?php

use App\Extraction\Support\Rows;

function box(int $x, int $top, int $bottom, int $width = 100): array
{
    return [[$x, $top], [$x + $width, $top], [$x + $width, $bottom], [$x, $bottom]];
}

it('joins a label and its value printed on the same line of the page', function () {
    // PaddleOCR returns "合计" and "￥33.000" as two lines, sometimes value first.
    $rows = Rows::fromLines([
        ['text' => '￥33.000', 'confidence' => 0.88, 'bbox' => box(400, 102, 138)],
        ['text' => '合计', 'confidence' => 0.70, 'bbox' => box(50, 100, 140)],
        ['text' => 'お預り', 'confidence' => 0.99, 'bbox' => box(50, 160, 200)],
    ]);

    expect(array_map(fn ($row) => $row->text, $rows))->toBe(['合計 ¥33,000', 'お預り'])
        ->and($rows[0]->confidence)->toBe(0.70)
        ->and($rows[0]->height)->toBe(40.0);
});

it('does not pull small lines into a tall heading beside them', function () {
    $rows = Rows::fromLines([
        ['text' => 'ローソン', 'confidence' => 0.9, 'bbox' => box(50, 100, 190, 300)],
        ['text' => 'TEL 03', 'confidence' => 0.9, 'bbox' => box(500, 150, 180)],
    ]);

    expect($rows)->toHaveCount(2);
});

it('keeps pages apart and leaves text-layer lines (no bbox) one row each', function () {
    $rows = Rows::fromLines([
        ['text' => 'page one', 'confidence' => 1.0, 'bbox' => null, 'page' => 1],
        ['text' => 'same height', 'confidence' => 1.0, 'bbox' => null, 'page' => 1],
        ['text' => 'page two', 'confidence' => 1.0, 'bbox' => box(0, 0, 10), 'page' => 2],
    ]);

    expect(array_map(fn ($row) => [$row->text, $row->page], $rows))
        ->toBe([['page one', 1], ['same height', 1], ['page two', 2]]);
});

it('splits two columns that share a line: label beside label, or a label after a value', function () {
    // Tei's sample 請求書４: recipient on the left, issuer on the right.
    $rows = Rows::fromLines([
        ['text' => '株式会社', 'confidence' => 0.9, 'bbox' => box(50, 100, 130)],
        ['text' => '御中', 'confidence' => 0.9, 'bbox' => box(200, 100, 130)],
        ['text' => '株式会社', 'confidence' => 0.9, 'bbox' => box(600, 100, 130)],
        ['text' => 'ご請求金額', 'confidence' => 0.9, 'bbox' => box(50, 200, 230)],
        ['text' => '¥1,001,404', 'confidence' => 0.9, 'bbox' => box(200, 200, 230)],
        ['text' => 'FAX:', 'confidence' => 0.9, 'bbox' => box(600, 200, 230)],
        ['text' => '1', 'confidence' => 0.9, 'bbox' => box(10, 300, 330, 20)],
        ['text' => '2025/7/1', 'confidence' => 0.9, 'bbox' => box(40, 300, 330)],
        ['text' => '商品A', 'confidence' => 0.9, 'bbox' => box(160, 300, 330)],
        ['text' => '1 式', 'confidence' => 0.9, 'bbox' => box(280, 300, 330)],
        ['text' => '¥1,000,000', 'confidence' => 0.9, 'bbox' => box(400, 300, 330)],
    ]);

    expect(array_map(fn ($row) => $row->text, $rows))->toBe([
        '株式会社 御中', '株式会社',
        'ご請求金額 ¥1,001,404', 'FAX:',
        '1 2025/7/1 商品A 1 式 ¥1,000,000',   // a table row stays whole
    ]);
});

it('keeps unit words with their table row', function () {
    // Tei's sample 領収書３: "▲△Δ▲ | 式 ¥50,000 | 10% | ¥50,000" and "△△△△ | 30 | 個 ¥2,000".
    $rows = Rows::fromLines([
        ['text' => '▲△Δ▲', 'confidence' => 0.9, 'bbox' => box(10, 100, 130)],
        ['text' => '式 ¥50,000', 'confidence' => 0.9, 'bbox' => box(200, 100, 130)],
        ['text' => '△△△△', 'confidence' => 0.9, 'bbox' => box(10, 200, 230)],
        ['text' => '30', 'confidence' => 0.9, 'bbox' => box(150, 200, 230, 30)],
        ['text' => '個 ¥2,000', 'confidence' => 0.9, 'bbox' => box(200, 200, 230)],
    ]);

    expect(array_map(fn ($row) => $row->text, $rows))->toBe(['▲△Δ▲ 式 ¥50,000', '△△△△ 30 個 ¥2,000']);
});
