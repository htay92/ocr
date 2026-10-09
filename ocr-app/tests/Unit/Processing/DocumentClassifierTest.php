<?php

use App\Processing\DocumentClassifier;

// FR-06. Shapes from Tei's sample documents (PaddleOCR output).
it('classifies a document from its OCR text', function (string $text, string $type) {
    expect((new DocumentClassifier)->classify($text))->toBe($type);
})->with([
    'invoice' => ["請求書\n株式会社○○ 御中\nご請求金額 ¥119,800", 'invoice'],
    'paid receipt to a company' => ["領 収 書\n株式会社○○○○ 御中\n金額 ¥123,456\n但し\n上記正に領収いたしました", 'paid_receipt'],
    'paid receipt to a person' => ["領収書\n山田太郎 様\n¥5,500-", 'paid_receipt'],
    // Till receipts print 領収証 too; till words decide.
    'till receipt with 領収証 heading' => ["LAWSON\n【領収証】\n合計 ¥210\n点数 1個\n上記正に領収いたしました", 'receipt'],
    'till receipt with change' => ["SHOP\n領収書\n合計 ¥625\nお預かり ¥700\nお釣り ¥75", 'receipt'],
    'till receipt without heading' => ["FamilyMart\n合計 ¥856", 'receipt'],
    'simplified forms from PaddleOCR' => ["8ample 8hop\n小针\n合计\n¥33.000", 'receipt'],
    // 様 / 御中 misread, but the "¥5,500-" amount style is only used on paid receipts.
    'paid receipt with misread 様' => ["領収書 日:2022年4月10\n山田太郎 绿\n¥5,500-\n籍代", 'paid_receipt'],
    'paid receipt with 合計金額 and misread 御中' => ["No.: 123456-123\n領収書\n式会社ル支社 中\n合計金額 ¥407,957,393-", 'paid_receipt'],
    'attendance sheet' => ["【勤務データ】山田 花子_2026年10月1日~2026年10月31日\n勤務時間項目\n所定時間 労働時間", 'attendance'],
    'other' => ['会議メモ 10/6', 'other'],
]);
