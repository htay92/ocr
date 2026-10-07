<?php

namespace App\Extraction\Support;

use Normalizer;

/**
 * Cleans OCR text before field patterns run (spec 3.4 "Normalization").
 * Every step works on one line and keeps its meaning; it never guesses words.
 */
final class JapaneseNormalizer
{
    /**
     * Simplified-Chinese and variant forms that PaddleOCR's CJK model outputs
     * for Japanese text (seen on real receipts: 合计, 消费税, 领收书, 8%对象).
     */
    private const OCR_FORMS = [
        '领' => '領', '收' => '収', '稅' => '税', '对' => '対', '减' => '減', '额' => '額',
        '计' => '計', '针' => '計', '费' => '費', '请' => '請', '书' => '書', '单' => '単', '价' => '価',
        '预' => '預', '释' => '釈', '账' => '帳', '现' => '現',
    ];

    /** Whole-word misreads seen on real documents. */
    private const OCR_WORDS = [
        '言十' => '計',          // 合計 read as 合言十
        '消管税' => '消費税',
        '源泉征収' => '源泉徴収',
    ];

    private const ERAS = ['令和' => 2018, 'R' => 2018, '平成' => 1988, 'H' => 1988];

    public static function normalize(string $text): string
    {
        // 1. NFKC: full-width digits/Latin → half-width, half-width kana → full-width.
        $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        $text = strtr(strtr($text, self::OCR_FORMS), self::OCR_WORDS);

        // 2. Yen signs: ￥ is ¥ after NFKC; a backslash or Y right before a digit is a yen sign too.
        $text = preg_replace('/(?:\\\\|(?<![A-Za-z])Y)(?=\s?\d)/u', '¥', $text);

        // 3. Inside numbers only.
        $text = self::fixNumbers($text);

        // 4. Era dates to Gregorian: 令和7年 → 2025年, 令和元年 → 2019年, R7.10.7 → 2025.10.7.
        $text = preg_replace_callback(
            '/(令和|平成|(?<![A-Za-z])[RH])\s?(\d{1,2}|元)(?=\s?[年.\/])/u',
            fn (array $m) => (string) (self::ERAS[$m[1]] + ($m[2] === '元' ? 1 : (int) $m[2])),
            $text,
        );

        // 5. Two-digit years: 26/10/07 → 2026/10/07.
        return preg_replace('/(?<![\d\/.])(\d{2})\/(\d{1,2})\/(\d{1,2})(?![\d\/])/u', '20$1/$2/$3', $text);
    }

    private static function fixNumbers(string $text): string
    {
        // Letters OCR reads instead of digits, inside a token made of digits: l,28O → 1,280.
        $text = preg_replace_callback('/(?<![A-Za-z])[0-9OolI|B,]{2,}(?![A-Za-z])/u', function (array $m) {
            return preg_match('/\d/', $m[0]) ? strtr($m[0], ['O' => '0', 'o' => '0', 'l' => '1', 'I' => '1', '|' => '1', 'B' => '8']) : $m[0];
        }, $text);

        // Dashes: ー, 一, − between digits or before an amount become "-".
        $text = preg_replace('/(?<=\d)[ー一−‐―](?=\d)|(?<![\p{L}\d])[ー−‐―](?=\s?¥?\d)/u', '-', $text);

        // Thousand separators: "1, 100" → "1,100"; "33.000" → "33,000" (exactly three digits, not a decimal).
        $text = preg_replace('/(?<=\d),\s+(?=\d{3}(?!\d))/u', ',', $text);

        return preg_replace('/(?<=\d)\.(?=\d{3}(?![\d.%]))/u', ',', $text);
    }
}
