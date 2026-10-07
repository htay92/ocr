<?php

use App\Extraction\Support\JapaneseNormalizer;

// Spec 3.4 "Normalization (JapaneseNormalizer), in order".
it('normalizes OCR text', function (string $input, string $expected) {
    expect(JapaneseNormalizer::normalize($input))->toBe($expected);
})->with([
    // 1. NFKC: full-width digits and Latin to half-width, half-width kana to full-width.
    'full-width digits' => ['２０２６年１０月７日', '2026年10月7日'],
    'full-width latin' => ['ＴＥＬ　０３', 'TEL 03'],
    'half-width kana' => ['ｺﾞｳｹｲ', 'ゴウケイ'],
    // 2. Yen signs before a digit.
    'full-width yen' => ['￥1,280', '¥1,280'],
    'backslash yen' => ['合計\\1,080', '合計¥1,080'],
    'Y as yen' => ['合計 Y1,280', '合計 ¥1,280'],
    'Y in a word stays' => ['YAMADA 2', 'YAMADA 2'],
    // 3. Inside numbers only.
    'O and l in an amount' => ['¥l,28O', '¥1,280'],
    'I and | in an amount' => ['I2|0円', '1210円'],
    'B in an amount' => ['¥1B0', '¥180'],
    'letters in words stay' => ['Book Off 10', 'Book Off 10'],
    'long dash in a phone number' => ['03ー1234ー5678', '03-1234-5678'],
    'minus sign before an amount' => ['値引 −50', '値引 -50'],
    'dot as thousand separator' => ['¥33.000', '¥33,000'],
    'space after a thousand comma' => ['1, 100円', '1,100円'],
    'decimal stays' => ['消費税(8.0%)', '消費税(8.0%)'],
    // 4. Era dates to Gregorian.
    'reiwa' => ['令和7年10月7日', '2025年10月7日'],
    'reiwa first year' => ['令和元年5月1日', '2019年5月1日'],
    'heisei' => ['平成31年4月30日', '2019年4月30日'],
    'R abbreviation' => ['R7.10.7', '2025.10.7'],
    // 5. Two-digit years.
    'two-digit year' => ['26/10/07 12:34', '2026/10/07 12:34'],
    // OCR confusions: Simplified-Chinese forms PaddleOCR outputs for Japanese.
    'simplified forms' => ['合计 消费税 领收书 8%对象 单价 金额 小针', '合計 消費税 領収書 8%対象 単価 金額 小計'],
    // Word-level misreads seen on Tei's samples.
    'split kanji 計' => ['合言十 ¥210', '合計 ¥210'],
    'misread 費' => ['外税商品消管税 117', '外税商品消費税 117'],
    'misread 徴' => ['源泉征収税 ¥102,916', '源泉徴収税 ¥102,916'],
]);

it('does not turn times, percentages or codes into amounts', function () {
    expect(JapaneseNormalizer::normalize('19:31 10% No.123 T1234567890123'))
        ->toBe('19:31 10% No.123 T1234567890123');
});
