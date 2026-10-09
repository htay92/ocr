<?php

use App\Processing\DocumentClassifier;
use App\Validation\ReceiptValidator;
use App\Validation\RuleResult;
use App\Validation\ValidationContext;
use Illuminate\Support\Carbon;

/*
 * Common Japanese receipt, invoice and paid-receipt layouts (FR-06, FR-07, V-01..V-10),
 * as a PDF text layer or a clean OCR reading would give them. Shop names are chains
 * or invented; numbers, T-numbers and people are fake.
 */

beforeEach(fn () => Carbon::setTestNow('2026-10-20 12:00:00'));

/**
 * Classify, extract and validate; check the type, fields, taxes, items and critical rules.
 *
 * @param  array{type: string, fields?: array, taxes?: list<array>, items?: int, item?: array{0: int, 1: array}, critical?: list<string>}  $expect
 */
function readsAs(string $text, array $expect): void
{
    $type = (new DocumentClassifier)->classify($text);
    expect($type)->toBe($expect['type']);

    $doc = extractText($text, $type);
    expect($doc->fields)->toMatchArray($expect['fields'] ?? []);
    if (isset($expect['taxes'])) {
        expect($doc->taxes)->toEqualCanonicalizing($expect['taxes']);
    }
    if (isset($expect['items'])) {
        expect($doc->lines)->toHaveCount($expect['items']);
    }
    if (isset($expect['item'])) {
        [$index, $item] = $expect['item'];
        expect($doc->lines[$index])->toMatchArray($item);
    }

    $results = (new ReceiptValidator)->validate(new ValidationContext($doc, $type, 0.95));
    $critical = array_values(array_map(fn ($r) => $r->code, array_filter($results, fn ($r) => $r->level === RuleResult::CRITICAL)));
    expect($critical)->toBe($expect['critical'] ?? []);
}

function taxRow(int $rate, int $taxable, int $tax): array
{
    return ['tax_rate' => $rate, 'taxable_amount' => $taxable, 'tax_amount' => $tax];
}

// --- レシート (till receipts) -------------------------------------------------------

it('reads a convenience-store receipt: ※ reduced-rate items, tax per rate, register and staff numbers', function () {
    readsAs(<<<'TXT'
    セブン-イレブン
    新宿西口店
    東京都新宿区西新宿1-1-1
    電話：03-1234-5678
    登録番号T1234567890123
    2026年10月 7日(水) 12:34 レジ#2
    領収書
    ※おにぎり 鮭 ¥150
    ※緑茶 500ml ¥140
    ボールペン ¥165
    小計 (3点) ¥455
    (8%対象 ¥290)
    (内消費税等 ¥21)
    (10%対象 ¥165)
    (内消費税等 ¥15)
    合計 ¥455
    お預り ¥1,000
    お釣 ¥545
    責No.012
    TXT, [
        'type' => 'receipt',
        'fields' => ['issue_date' => '2026-10-07', 'registration_number' => 'T1234567890123', 'vendor_phone' => '03-1234-5678',
            'subtotal' => 455, 'total' => 455, 'cash_received' => 1000, 'change' => 545, 'tax_mode' => 'inclusive'],
        'taxes' => [taxRow(8, 290, 21), taxRow(10, 165, 15)],
        'items' => 3,
        'item' => [0, ['name' => 'おにぎり 鮭', 'amount' => 150, 'tax_rate' => 8]],
    ]);
});

it('reads a tax-exclusive supermarket receipt with a quantity row, a discount and points', function () {
    readsAs(<<<'TXT'
    スーパーサンプル 中野店
    TEL 03-0000-0000
    登録番号 T2345678901234
    2026/10/06 18:20
    ※牛乳 ¥198
    ※バナナ ¥396
    2個 × @198
    ※国産豚こま ¥580
    値引 -¥50
    キッチンペーパー ¥298
    小計 ¥1,422
    外税8%対象額 ¥1,124
    外税8% ¥89
    外税10%対象額 ¥298
    外税10% ¥29
    合計 ¥1,540
    今回ポイント 14P
    お預り ¥2,000
    お釣 ¥460
    TXT, [
        'type' => 'receipt',
        'fields' => ['issue_date' => '2026-10-06', 'subtotal' => 1422, 'total' => 1540, 'tax_mode' => 'exclusive', 'change' => 460],
        'taxes' => [taxRow(8, 1124, 89), taxRow(10, 298, 29)],
        'items' => 4,
        'item' => [1, ['name' => 'バナナ', 'quantity' => 2.0, 'unit_price' => 198, 'amount' => 396, 'tax_rate' => 8]],
    ]);
});

it('reads a restaurant bill with a service charge, tax added on top and card payment', function () {
    readsAs(<<<'TXT'
    ダイニング 和
    東京都渋谷区道玄坂1-2-3
    TEL 03-1111-2222
    登録番号 T3456789012345
    2026年10月5日 19:45
    テーブル 5 人数 2名
    生ビール ¥1,200
    焼き鳥盛合せ ¥980
    サラダ ¥720
    小計 ¥2,900
    サービス料(10%) ¥290
    10%対象 ¥3,190
    消費税(10%) ¥319
    合計 ¥3,509
    お支払 クレジット ¥3,509
    TXT, [
        'type' => 'receipt',
        'fields' => ['issue_date' => '2026-10-05', 'subtotal' => 2900, 'total' => 3509, 'tax_mode' => 'exclusive', 'payment_method' => 'card'],
        'taxes' => [taxRow(10, 3190, 319)],
        'items' => 3,
    ]);
});

it('reads a transit-IC payment without taking the card balance as the total', function () {
    readsAs(<<<'TXT'
    ファミリーマート 新橋駅前店
    登録番号 T4567890123456
    2026/10/04(土) 08:15
    ※サンドイッチ ¥298
    ※カフェラテ ¥180
    合計 ¥478
    (8%対象 ¥478 内消費税等 ¥35)
    交通系IC支払 ¥478
    残高 ¥2,522
    TXT, [
        'type' => 'receipt',
        'fields' => ['issue_date' => '2026-10-04', 'total' => 478, 'payment_method' => 'e_money'],
        'taxes' => [taxRow(8, 478, 35)],
        'items' => 2,
    ]);
});

it('reads a fuel receipt with a decimal quantity in litres', function () {
    readsAs(<<<'TXT'
    ENEOS 中野サービスステーション
    登録番号 T5678901234567
    2026年10月3日 10:05
    レギュラー 30.00L × @170 ¥5,100
    小計 ¥5,100
    (10%対象 ¥5,100 内消費税 ¥463)
    合計 ¥5,100
    クレジット ¥5,100
    TXT, [
        'type' => 'receipt',
        'fields' => ['issue_date' => '2026-10-03', 'total' => 5100, 'payment_method' => 'card'],
        'taxes' => [taxRow(10, 5100, 463)],
        'items' => 1,
        'item' => [0, ['name' => 'レギュラー', 'quantity' => 30.0, 'unit_price' => 170, 'amount' => 5100]],
    ]);
});

it('reads a return receipt as a negative total and flags it (V-01, V-05)', function () {
    readsAs(<<<'TXT'
    ローソン 大手町店
    登録番号 T6789012345678
    2026/10/02 13:00
    返品
    ※弁当 -¥550
    ※お茶 -¥150
    合計 -¥700
    (8%対象 -¥700 内消費税等 -¥51)
    返金 ¥700
    TXT, [
        'type' => 'receipt',
        'fields' => ['total' => -700],
        'item' => [0, ['name' => '弁当', 'amount' => -550]],
        'critical' => ['V-01', 'V-05'],             // a return always needs a person to look at it
    ]);
});

it('reads full-width digits, 円 amounts and a 令和 date', function () {
    readsAs(<<<'TXT'
    喫茶　さくら
    令和8年10月7日
    ブレンドコーヒー　４８０円
    チーズケーキ　５２０円
    合計　１，０００円
    (内消費税　９０円)
    お預り　１，０００円
    お釣り　０円
    TXT, [
        'type' => 'receipt',
        'fields' => ['issue_date' => '2026-10-07', 'total' => 1000, 'cash_received' => 1000],
        'taxes' => [taxRow(10, 1000, 90)],
        'items' => 2,
    ]);
});

it('reads a taxi receipt without taking the car number as a phone number or an amount', function () {
    readsAs(<<<'TXT'
    領収書
    東京タクシー株式会社
    登録番号 T3333333333333
    車番 品川500 あ 12-34
    乗務員 1234
    2026/10/05 23:10
    乗車 23:10 降車 23:35
    運賃 ¥3,200
    迎車料金 ¥400
    合計 ¥3,600
    (内消費税 ¥327)
    クレジット ¥3,600
    TXT, [
        'type' => 'receipt',
        'fields' => ['vendor_name' => '東京タクシー株式会社', 'vendor_phone' => null, 'issue_date' => '2026-10-05', 'total' => 3600, 'payment_method' => 'card'],
        'taxes' => [taxRow(10, 3600, 327)],
        'items' => 2,
    ]);
});

it('takes the exit date of a parking receipt and does not read the parking time as an item', function () {
    readsAs(<<<'TXT'
    領収書
    タイムズ新宿第5
    登録番号 T4444444444444
    入庫 2026/10/06 09:00
    出庫 2026/10/07 09:30
    駐車時間 24時間30分
    駐車料金 ¥2,400
    合計 ¥2,400
    (内消費税等 ¥218)
    TXT, [
        'type' => 'receipt',
        'fields' => ['issue_date' => '2026-10-07', 'total' => 2400],
        'taxes' => [taxRow(10, 2400, 218)],
        'items' => 1,
        'item' => [0, ['name' => '駐車料金', 'amount' => 2400]],
    ]);
});

// --- 請求書 (invoices) ------------------------------------------------------------

it('reads a freelance invoice with withholding tax and a bank block', function () {
    readsAs(<<<'TXT'
    請求書
    株式会社サンプル 御中
    請求日 2026年9月30日
    請求書番号 INV-2026-009
    山田 太郎
    登録番号 T7890123456789
    下記の通りご請求申し上げます。
    ご請求金額 ¥99,790
    品名 数量 単価 金額
    Webサイト制作 1 式 ¥100,000 ¥100,000
    小計 ¥100,000
    消費税(10%) ¥10,000
    源泉徴収税額 -¥10,210
    合計 ¥99,790
    振込先 みずほ銀行 新宿支店 普通 1234567
    お支払期限 2026年10月31日
    TXT, [
        'type' => 'invoice',
        'fields' => ['recipient_name' => '株式会社サンプル', 'issue_date' => '2026-09-30', 'due_date' => '2026-10-31',
            'invoice_number' => 'INV-2026-009', 'subtotal' => 100000, 'withholding_tax' => 10210, 'total' => 99790],
        'taxes' => [taxRow(10, 100000, 10000)],
        'items' => 1,
        'item' => [0, ['name' => 'Webサイト制作', 'amount' => 100000]],
    ]);
});

it('reads an invoice with 8% and 10% items, a ※ legend and a per-rate breakdown', function () {
    readsAs(<<<'TXT'
    御請求書
    株式会社ABC商事 御中
    発行日 2026/10/01
    請求番号 2026-1001
    株式会社サンプル食品
    東京都中央区銀座1-1-1
    登録番号 T8901234567890
    ご請求金額（税込） ¥16,300
    品名 数量 単価 金額
    ※米 10kg 2 ¥5,000 ¥10,000
    コーヒーメーカー 1 ¥5,000 ¥5,000
    ※は軽減税率対象
    小計 ¥15,000
    8%対象 ¥10,000 消費税 ¥800
    10%対象 ¥5,000 消費税 ¥500
    合計 ¥16,300
    TXT, [
        'type' => 'invoice',
        'fields' => ['issue_date' => '2026-10-01', 'invoice_number' => '2026-1001', 'subtotal' => 15000, 'total' => 16300],
        'taxes' => [taxRow(8, 10000, 800), taxRow(10, 5000, 500)],
        'items' => 2,
        'item' => [0, ['quantity' => 2.0, 'unit_price' => 5000, 'amount' => 10000, 'tax_rate' => 8]],
    ]);
});

it('reads an online-shop 適格請求書 with 注文合計, ご請求額 and the order date', function () {
    readsAs(<<<'TXT'
    Amazon.co.jp
    適格請求書
    注文日 2026年10月1日
    注文番号 250-1234567-1234567
    請求書番号 JP12345ABCDE
    販売元 サンプル合同会社
    登録番号 T1111111111111
    お届け先 山田 太郎 様
    商品 数量 価格
    USBケーブル 1 ¥1,280
    文庫本 2 ¥1,540
    商品の小計（税込） ¥2,820
    配送料・手数料 ¥0
    注文合計 ¥2,820
    ご請求額 ¥2,820
    10%対象 ¥2,820 （消費税 ¥256）
    お支払い方法 クレジットカード
    TXT, [
        'type' => 'invoice',
        'fields' => ['issue_date' => '2026-10-01', 'invoice_number' => 'JP12345ABCDE', 'subtotal' => 2820, 'total' => 2820],
        'taxes' => [taxRow(10, 2820, 256)],
        'items' => 2,
        'item' => [1, ['name' => '文庫本', 'quantity' => 2.0, 'unit_price' => 770, 'amount' => 1540]],
    ]);
});

it('takes 今回ご請求額 on a statement, not the previous balance', function () {
    readsAs(<<<'TXT'
    ご請求書
    株式会社サンプル 御中
    請求日 2026年10月20日
    株式会社テスト商会
    登録番号 T6666666666666
    前回ご請求額 ¥55,000
    ご入金額 ¥55,000
    繰越金額 ¥0
    今回ご利用額 ¥30,000
    消費税(10%) ¥3,000
    今回ご請求額 ¥33,000
    TXT, [
        'type' => 'invoice',
        'fields' => ['issue_date' => '2026-10-20', 'total' => 33000],
        'taxes' => [taxRow(10, 30000, 3000)],
    ]);
});

it('flags an invoice without a registration number (V-03)', function () {
    readsAs("請求書\n株式会社サンプル 御中\n請求日 2026年10月1日\nご請求金額 ¥11,000\n品名 数量 金額\n作業費 1 ¥10,000\n小計 ¥10,000\n消費税(10%) ¥1,000\n合計 ¥11,000", [
        'type' => 'invoice',
        'fields' => ['registration_number' => null, 'total' => 11000],
        'critical' => ['V-03'],
    ]);
});

// --- 領収書 (paid receipts) ---------------------------------------------------------

it('reads a hand-written style paid receipt with 但し and a 収入印紙 note', function () {
    readsAs(<<<'TXT'
    領収書
    山田 太郎 様
    2026年10月1日
    ¥33,000-
    但し 飲食代として
    上記正に領収いたしました
    収入印紙
    株式会社サンプル
    東京都港区芝公園1-1-1
    登録番号 T2222222222222
    内訳 税抜金額 ¥30,000 消費税額 ¥3,000 (10%)
    TXT, [
        'type' => 'paid_receipt',
        'fields' => ['recipient_name' => '山田 太郎', 'issue_date' => '2026-10-01', 'total' => 33000, 'purpose' => '飲食代'],
        'taxes' => [taxRow(10, 30000, 3000)],
    ]);
});

it('reads a hotel receipt where 宿泊税 and 入湯税 are not consumption tax', function () {
    readsAs(<<<'TXT'
    領収書
    ホテルサンプル東京
    東京都千代田区丸の内1-1-1
    登録番号 T5555555555555
    山田 太郎 様
    2026年10月8日
    宿泊料 ¥22,000
    宿泊税 ¥400
    入湯税 ¥150
    合計 ¥22,550
    (10%対象 ¥22,000 内消費税 ¥2,000)
    TXT, [
        'type' => 'paid_receipt',
        'fields' => ['issue_date' => '2026-10-08', 'total' => 22550],
        'taxes' => [taxRow(10, 22000, 2000)],
        'items' => 3,
    ]);
});

// --- classification only ------------------------------------------------------------

it('classifies other common document headings', function (string $text, string $type) {
    expect((new DocumentClassifier)->classify($text))->toBe($type);
})->with([
    'card slip printed as 領収書' => ["クレジット売上票\n領収書\nご利用日 2026/10/01\nご利用金額 ¥5,000\nカード会社 VISA", 'receipt'],
    '納品書兼請求書' => ["納品書兼請求書\n株式会社A 御中\n合計 ¥10,000", 'invoice'],
    'quotation (not processed)' => ["御見積書\n株式会社A 御中\nお見積金額 ¥100,000\n合計 ¥100,000", 'other'],
    'invoice paid by credit card' => ["請求書\nお支払方法 クレジットカード\n合計 ¥5,500", 'invoice'],
]);
