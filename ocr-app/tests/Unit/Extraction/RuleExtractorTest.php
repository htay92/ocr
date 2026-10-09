<?php

use App\Extraction\Data\ExtractedDocument;
use App\Extraction\RuleExtractor;
use App\Processing\DocumentClassifier;
use App\Validation\ReceiptValidator;
use App\Validation\RuleResult;
use App\Validation\ValidationContext;

/** OCR text as engine lines without boxes (like the PDF text layer). */
function extractText(string $text, string $type): ExtractedDocument
{
    $lines = array_map(fn (string $line) => ['text' => $line, 'confidence' => 0.9, 'bbox' => null], preg_split('/\R/u', $text));

    return (new RuleExtractor)->extractLines($lines, $type);
}

// --- レシート (receipt) ------------------------------------------------------

it('reads a phone-photo receipt where labels, values and the time sit on separate lines', function () {
    // PaddleOCR output for Tei's sample レシート６ (beard papa), trimmed.
    $doc = extractText(<<<'TXT'
    beard papa.
    ビアードハハ°ウィング新橋店
    港区新橋2丁目東口地下街1号
    電話03-3572-8211
    登録番号：T4120001071116
    2026年10月3日(土)
    18:53
    期限食比4S1100
    8%对象
    1,100円
    小計
    1,100円
    消費税（8.0%）
    81円)
    (内税消費税
    合計
    1，100円
    預かり
    TXT, 'receipt');

    expect($doc->fields)->toMatchArray([
        'vendor_name' => 'beard papa. ビアードハハ°ウィング新橋店',
        'vendor_phone' => '03-3572-8211',
        'registration_number' => 'T4120001071116',
        'issue_date' => '2026-10-03',
        'subtotal' => 1100,
        'total' => 1100,
        'tax_mode' => 'inclusive',
    ])->and($doc->taxes)->toBe([['tax_rate' => 8, 'taxable_amount' => 1100, 'tax_amount' => 81]]);
});

it('pairs tax with its base when the engine prints values before labels', function () {
    // PaddleOCR order for Tei's sample レシート４ (LAWSON): "¥15)" comes before "(内消費税等".
    $doc = extractText("LAWSON\n敷津西二丁目店\n2023年3月10日（金）08:10\n【領収証】\n210軽\n¥210\n合計\n¥15)\n(内消費税等\n¥210)\n(8%対象\n1個", 'receipt');

    // The total is not asserted: without boxes, "¥210" (before 合計) and "¥15" (after it) are ambiguous.
    // On real OCR the boxes put "¥210" on the 合計 row; otherwise V-05 flags the document.
    expect($doc->fields)->toMatchArray(['vendor_name' => 'LAWSON 敷津西二丁目店', 'issue_date' => '2023-03-10', 'tax_mode' => 'inclusive'])
        ->and($doc->taxes)->toBe([['tax_rate' => 8, 'taxable_amount' => 210, 'tax_amount' => 15]]);
});

it('infers the rate of a single tax-included amount from the total', function () {
    $doc = extractText("FamilyMart\nファミリーマート\n2024年4月24日(水) 13:45\nおにぎり 110\n(内)消費税等 78\n合計 ¥856", 'receipt');

    expect($doc->taxes)->toBe([['tax_rate' => 10, 'taxable_amount' => 856, 'tax_amount' => 78]])
        ->and($doc->get('tax_mode'))->toBe('inclusive');
});

it('infers an 8% rate from a tax-excluded base', function () {
    $doc = extractText("Takashimaya\n2018年06月11日(月) 12:47\n外税対象 1,465\n外税商品消費税等 117\n合計 1,582円\nお預 2,000", 'receipt');

    expect($doc->fields)->toMatchArray(['total' => 1582, 'tax_mode' => 'exclusive', 'cash_received' => 2000])
        ->and($doc->taxes)->toBe([['tax_rate' => 8, 'taxable_amount' => 1465, 'tax_amount' => 117]]);
});

it('reads the Sample Shop receipt with Simplified-Chinese forms and dot separators', function () {
    // PaddleOCR output for Tei's sample レシート１ (178 x 315 px), with label and value merged into rows.
    $doc = extractText("8ample 8hop\nミラ77ソョン専門店\nTe1.00-0000-0000\nご来店成にありかとうこきいます。\n2019年10月31日（木）19:31\nリクッ ￥11,000\nイアスカーフ\n1点 ￥22,000\n小针 2点￥33.000\n合计 ￥33.000\n（55消费税 ￥3,000)\nおり ￥40,000", 'receipt');

    expect($doc->fields)->toMatchArray(['issue_date' => '2019-10-31', 'total' => 33000]);
});

it('takes the largest total in the lower half when several rows match', function () {
    $doc = extractText("SHOP\n2026/10/07\n合計 ¥500\nパン ¥300\nおにぎり ¥200\n小計 ¥1,000\n合計 ¥1,080\nお預り ¥2,000\nお釣り ¥920", 'receipt');

    expect($doc->fields)->toMatchArray(['total' => 1080, 'cash_received' => 2000, 'change' => 920, 'payment_method' => 'cash']);
});

it('does not take a random price as the total', function () {
    $doc = extractText("SHOP\n2020/1/1\n食パン ¥111\n缶詰 ¥118\nお預かり ¥700", 'receipt');

    expect($doc->fields)->toMatchArray(['issue_date' => '2020-01-01', 'total' => null])
        ->and($doc->taxes)->toBe([]);
});

it('reads line items with reduced-rate marks, quantities and discounts', function () {
    $doc = extractText(<<<'TXT'
    スーパー○○
    2026年10月7日 12:34
    ※おにぎり 鮭 ¥150
    お茶 ¥260
    2個 × ¥130
    パン ¥200 ※
    値引 -¥50
    小計 ¥560
    (8%対象 ¥300 内消費税 ¥22)
    (10%対象 ¥260 内消費税 ¥23)
    合計 ¥560
    クレジット ¥560
    TXT, 'receipt');

    expect($doc->lines)->toBe([
        ['line_no' => 1, 'name' => 'おにぎり 鮭', 'quantity' => 1.0, 'unit_price' => null, 'amount' => 150, 'tax_rate' => 8, 'discount' => 0],
        ['line_no' => 2, 'name' => 'お茶', 'quantity' => 2.0, 'unit_price' => 130, 'amount' => 260, 'tax_rate' => 10, 'discount' => 0],
        ['line_no' => 3, 'name' => 'パン', 'quantity' => 1.0, 'unit_price' => null, 'amount' => 200, 'tax_rate' => 8, 'discount' => -50],
    ])->and($doc->get('payment_method'))->toBe('card');
});

it('reads the mark legend the other way round when ※ marks standard-rate items', function () {
    $doc = extractText("SHOP\n2026/10/07\n※印は標準税率対象\nおにぎり ¥150\n※タオル ¥500\n合計 ¥650", 'receipt');

    expect(array_column($doc->lines, 'tax_rate', 'name'))->toBe(['おにぎり' => 8, 'タオル' => 10]);
});

it('detects electronic money', function () {
    expect(extractText("SHOP\n合計 ¥300\n交通系IC ¥300", 'receipt')->get('payment_method'))->toBe('e_money');
});

it('picks the tallest line near the top as the vendor', function () {
    $box = fn (int $top, int $height) => [[10, $top], [300, $top], [300, $top + $height], [10, $top + $height]];
    $doc = (new RuleExtractor)->extractLines([
        ['text' => 'いつもご利用ありがとうございます', 'confidence' => 0.9, 'bbox' => $box(10, 20)],
        ['text' => 'ローソン', 'confidence' => 0.95, 'bbox' => $box(40, 60)],
        ['text' => '東京都港区1-2-3', 'confidence' => 0.9, 'bbox' => $box(110, 20)],
        ['text' => '合計 ¥500', 'confidence' => 0.8, 'bbox' => $box(900, 20)],
    ], 'receipt');

    expect($doc->fields)->toMatchArray(['vendor_name' => 'ローソン', 'vendor_address' => '東京都港区1-2-3', 'total' => 500])
        ->and($doc->fieldConfidence)->toMatchArray(['vendor_name' => 0.95, 'total' => 0.8]);
});

// --- 領収証 (paid receipt) ---------------------------------------------------

it('separates vendor and recipient on a paid receipt with 御中 on its own line', function () {
    // Shape of Tei's sample 領収書４.
    $doc = extractText("領 収 書\nNo. 123456-123\n発行日: 令和13年12月21日\n株式会社サンプル ○○支社\n御中\n株式会社 日本サンプル\n〒123-1234\n合計金額\n¥407,957,393-", 'paid_receipt');

    expect($doc->fields)->toMatchArray([
        'vendor_name' => '株式会社 日本サンプル',
        'recipient_name' => '株式会社サンプル ○○支社',
        'issue_date' => '2031-12-21',
        'invoice_number' => '123456-123',
        'total' => 407957393,
        'purpose' => null,
    ]);
});

it('reads 様, an unlabelled ¥X- amount and a 但し split across lines', function () {
    $doc = extractText("領収書\n発行日: 2022年4月1日\n山田 太郎 様\n¥5,500-\n但し\n書籍代として\n上記、正に領収いたしました\n株式会社○○○○", 'paid_receipt');

    expect($doc->fields)->toMatchArray([
        'vendor_name' => '株式会社○○○○',
        'recipient_name' => '山田 太郎',
        'issue_date' => '2022-04-01',
        'total' => 5500,
        'purpose' => '書籍代',
    ]);
});

it('reads Tei\'s sample 領収書２ as PaddleOCR returned it', function () {
    $doc = extractText("領収書\nNo.\n123456\n株式会社\n御中\n金額\n￥123,456\n但し\n領収日\n2021年4月30日\n上記正に領収いたしました\n株式会社×××支店\n内訳\n亍000-0000\n県市×-×-×\n收入\n税抜金額\n印紙", 'paid_receipt');

    expect($doc->fields)->toMatchArray([
        'total' => 123456,
        'issue_date' => '2021-04-30',
        'recipient_name' => '株式会社',
        'vendor_name' => '株式会社×××支店',
        'purpose' => null,
    ]);
});

// --- 請求書 (invoice) --------------------------------------------------------

it('extracts every invoice field from a table layout', function () {
    // PaddleOCR shape of Tei's sample 請求書４, with each table row merged.
    $doc = extractText(<<<'TXT'
    請求書
    請求日 年 月 日
    請求書番号
    株式会社○○
    御中
    株式会社○○
    登録番号T1234567890123
    ご請求金額
    ¥1,001,404
    お支払期限: 年 月 日
    No 納品日 商品名/品名 数量 単価 税率 金額(税抜)
    1 2025/7/1 商品A 1 式 ¥1,000,000 10% ¥1,000,000
    2 2025/7/1 商品B 2 個 ¥2,000 8% ¥4,000
    小計
    (税抜)
    ¥1,004,000
    10%対象
    ¥1,000,000
    消費税
    ¥100,000
    8%対象
    ¥4,000
    消費税
    ¥320
    源泉徴収税
    ¥102,916
    合計(税込)
    ¥1,001,404
    TXT, 'invoice');

    expect($doc->fields)->toMatchArray([
        'vendor_name' => '株式会社○○',
        'recipient_name' => '株式会社○○',
        'registration_number' => 'T1234567890123',
        'invoice_number' => null,   // printed but blank
        'issue_date' => null,       // printed but blank; not the item date
        'due_date' => null,
        'subtotal' => 1004000,
        'total' => 1001404,
        'withholding_tax' => 102916,
        'tax_mode' => 'exclusive',
    ])->and($doc->taxes)->toBe([
        ['tax_rate' => 10, 'taxable_amount' => 1000000, 'tax_amount' => 100000],
        ['tax_rate' => 8, 'taxable_amount' => 4000, 'tax_amount' => 320],
    ])->and($doc->lines)->toBe([
        ['line_no' => 1, 'name' => '商品A', 'quantity' => 1.0, 'unit_price' => 1000000, 'amount' => 1000000, 'tax_rate' => 10, 'discount' => 0],
        ['line_no' => 2, 'name' => '商品B', 'quantity' => 2.0, 'unit_price' => 2000, 'amount' => 4000, 'tax_rate' => 8, 'discount' => 0],
    ]);
});

it('reads tax-rate columns in either order, month-end due dates and the invoice number', function () {
    $doc = extractText("請求書\n発行日 2023/1/1\n請求番号 0000001\n○○○○株式会社 御中\n●●●●株式会社\nご請求金額(税込)\n¥119,800\n振込期日 2023年2月末\n税率区分 消費税 金額(税抜)\n10%対象 ¥5,000 ¥50,000\n8%対象 ¥60,000 ¥4,800\n小計 ¥110,000", 'invoice');

    expect($doc->fields)->toMatchArray([
        'vendor_name' => '●●●●株式会社',
        'recipient_name' => '○○○○株式会社',
        'invoice_number' => '0000001',
        'issue_date' => '2023-01-01',
        'due_date' => '2023-02-28',
        'subtotal' => 110000,
        'total' => 119800,
    ])->and($doc->taxes)->toBe([
        ['tax_rate' => 10, 'taxable_amount' => 50000, 'tax_amount' => 5000],
        ['tax_rate' => 8, 'taxable_amount' => 60000, 'tax_amount' => 4800],
    ]);
});

it('classifies a single consumption tax by the subtotal', function () {
    $doc = extractText("請求書\n請求日: 2025年4月30日\n請求書番号: S-202504-001\n株式会社○○ 御中\nアドビデザイン事務所 アドビ花子\n請求金額 330.000円\n小計 300.000円\n消費税 30,000円\n合計 330.000円", 'invoice');

    expect($doc->fields)->toMatchArray([
        'vendor_name' => 'アドビデザイン事務所 アドビ花子',
        'invoice_number' => 'S-202504-001',
        'issue_date' => '2025-04-30',
        'subtotal' => 300000,
        'total' => 330000,
        'tax_mode' => 'exclusive',
    ])->and($doc->taxes)->toBe([['tax_rate' => 10, 'taxable_amount' => 300000, 'tax_amount' => 30000]]);
});

it('restores the T of a registration number', function (string $text) {
    expect(extractText("請求書\n{$text}", 'invoice')->get('registration_number'))->toBe('T4120001071116');
})->with([
    'T dropped' => ['登録番号 4120001071116'],
    'T read as 7 (spec 3.4)' => ['登録番号 74120001071116'],
    'spaced' => ['登録番号: T 4120001071116'],
]);

it('does not take "No" inside a word as the invoice number label', function () {
    expect(extractText("請求書\nNov 2026\nNo A-17", 'invoice')->get('invoice_number'))->toBe('A-17');
});

it('records the confidence of the row each field came from', function () {
    $doc = (new RuleExtractor)->extractLines([
        ['text' => '合計', 'confidence' => 0.6, 'bbox' => null],
        ['text' => '¥1,080', 'confidence' => 0.95, 'bbox' => null],
    ], 'receipt');

    expect($doc->get('total'))->toBe(1080)
        ->and($doc->fieldConfidence['total'])->toBe(0.95);
});

it('reads a date with the time stuck to it', function () {
    // Tei's sample レシート２: "レヅ1 2020/1/115:01" is 2020/1/1 15:01.
    expect(extractText("SHOP\n合計 ¥625\nレヅ1 2020/1/115:01", 'receipt')->get('issue_date'))->toBe('2020-01-01');
});

it('ignores a total in the header and address lines when reading line items', function () {
    // Shape of Tei's sample 領収書３: 合計金額 printed above the item table.
    $doc = extractText(<<<'TXT'
    領収書
    発行日 2023/1/1
    東京都千代田区0-1-200ビル3F ●●●株式会社
    合計金額(税込)
    ¥119,800
    内容 軽減税率 数量 単位 単価(税抜) 税率 金額(税抜)
    ▲△Δ▲ 式 ¥50,000 10% ¥50,000
    △△△△ 30 個 ¥2,000 8% ¥60,000
    小計 ¥110,000
    TXT, 'paid_receipt');

    expect(array_column($doc->lines, 'amount'))->toBe([50000, 60000])
        ->and($doc->get('subtotal'))->toBe(110000)
        ->and($doc->get('total'))->toBe(119800);
});

it('finds withholding tax when the label is misread', function () {
    expect(extractText("請求書\n源泉税額 ¥10,210\n合計 ¥90,000", 'invoice')->get('withholding_tax'))->toBe(10210);
});

it('skips headings, register numbers and field labels when reading items', function () {
    // Shapes from Tei's samples レシート４ (LAWSON), 請求書１ and 請求書３.
    $doc = extractText("LAWSON\n2023年3月10日(金)08:10\nレ#288028 贵:河口\n【領収証】 210軽\nNo 1001\n票香号: 123456\nおにぎり ¥150\n合計 ¥150", 'receipt');

    expect(array_column($doc->lines, 'name'))->toBe(['おにぎり']);
});

it('takes the item name from the row above when the item row only has a tax mark', function () {
    // Tei's sample レシート５ (Takashimaya): category name, then "外 47 1点 47".
    $doc = extractText("Takashimaya\n2018年06月11日(月)12:47\n洋惣菜\n外 1,371 1点 1,371\n洋菓子\n外 47 1点 47\n外税対象 1,418\n合計 1,531円", 'receipt');

    expect(array_column($doc->lines, 'name', 'amount'))->toBe([1371 => '洋惣菜', 47 => '洋菓子']);
});

it('does not read amounts glued to letters or with leading zeros', function () {
    $doc = extractText("SHOP\n2026/10/07\n期限食比4S1100\n籍代 2L\nR全的 000040040000\nパン ¥200\n合計 ¥200", 'receipt');

    expect(array_column($doc->lines, 'amount', 'name'))->toBe(['パン' => 200]);
});

it('reads quantity, unit price and amount columns of invoice tables', function () {
    // Tei's samples 請求書３ ("医品名 100 1,200 120,000") and 請求書１ ("サブル1 1 式 10,000 10,000").
    $doc = extractText("請求書\n請求金額\n¥ 896,500\n医品名 100 1,200 120,000\nサブル1 1 式 10,000 10,000\n小計 ¥130,000", 'invoice');

    expect($doc->lines)->toBe([
        ['line_no' => 1, 'name' => '医品名', 'quantity' => 100.0, 'unit_price' => 1200, 'amount' => 120000, 'tax_rate' => 10, 'discount' => 0],
        ['line_no' => 2, 'name' => 'サブル1', 'quantity' => 1.0, 'unit_price' => 10000, 'amount' => 10000, 'tax_rate' => 10, 'discount' => 0],
    ]);
});

it('stops reading receipt items at the total, before payment lines', function () {
    // Tei's sample レシート４: "交通系マ ¥210" and a member number come after 合計.
    $doc = extractText("LAWSON\n2023年3月10日(金)08:10\n【領収証】 210軽\n合計 ¥210\n交通系マ ¥210\n会具D***********9041\n支払後残高 ¥751", 'receipt');

    expect($doc->lines)->toBe([]);
});

// --- low-resolution invoice: the 「ご請求金額」 label is unreadable ---------------

/** PaddleOCR on Tei's 375×533 px sample 請求書２: the big amount survives, its label and the 合計 row do not. */
const SMALL_INVOICE_PADDLE = ['請求書', '责行日', '2023/1/1', '号', '0000000', '0000株式会社御中', '7 100-0000', '干代201-2002A', '株式会社',
    'Fa10-0000', '二上げす', '注：支代', '全程（）', '发量：00-0000-0000', '￥119.800', 'C截行O支通口座23400', '达元', '….*0', '82年2', '手数料壮',
    'B付', '内0', '车数量', '从', '单（）', '税', '金（）', '2022/12/1', 'AAAA', '式', '10.000', '00.000', '2002/12/15', '△△△△', '*', '8', '信',
    '¥2,000', '8', 'w0,000', '校象', '小4', 'vira,00o', '区分', '调贯', '金税）', '消费积', 'V,800', '12%刘章', 'v5,000', '5020', '会计',
    '1ta,000', '格刻象', '4,800', '*80200', '指为'];

/** NDLOCR-Lite on the same file (it reads the yen sign as a backslash). */
const SMALL_INVOICE_NDL = ['請求書', '○○○○株式会社御中', 'TO: 000', '東京都千代雜誌(1--------100', 'TIのAN、 IN申し上げます。', 'ござすます。',
    '\119,800', 'as', 'CORTQOR WID CONDE', '1000', '111 mand', '● ●●株式会社', '}(120', '位成、 製稅額の稅物記念(〇〇〇〇〇', '12 (6-00-100',
    '121-121', '試込手数料ほ部はのご負相にてお飼いいたします。', '%(11111111', 'en', '消費現', 'AN', 'BW', 'NMW', '202/12/15', '2242', 'AAAA',
    'is', '****', '--', '35', '34', '11', '15', '******', '1,000', 'as', '14', '2,000', '16', '$8.830', '63,00', '00,000', 'asst', '200', 'ARE',
    '1,0', '$6.830', '200', 'Mo', '*****', '300', '1,0000', '0.50', 'mm', 'ax'];

it('takes an invoice total from the headline yen amount when the label is unreadable', function (array $lines) {
    expect(extractText(implode("\n", $lines), 'invoice')->get('total'))->toBe(119800);
})->with([
    'paddle' => [SMALL_INVOICE_PADDLE],
    'ndl' => [SMALL_INVOICE_NDL],
]);

it('still prefers the labelled invoice total', function () {
    $doc = extractText("請求書\nご請求金額\n¥99,000\n品名 数量 金額\nA 1 ¥90,000\n小計 ¥90,000\n消費税 ¥9,000\n合計 ¥99,000", 'invoice');

    expect($doc->get('total'))->toBe(99000);
});

it('does not take a lone yen amount from the lower half or on a receipt', function () {
    $lowerHalf = "請求書\n株式会社A御中\n品名\n数量\n単価\n金額\nA\n1\n¥5,000\n振込先 B銀行";
    expect(extractText($lowerHalf, 'invoice')->get('total'))->toBeNull()
        ->and(extractText("ローソン\n2026年10月1日\n¥1,280\nおにぎり", 'receipt')->get('total'))->toBeNull();
});

// --- online-shop invoice from a PDF text layer (no 合計 label) ------------------

/** pdftotext -layout of an online-shop 請求書兼領収書 (Nike.com); customer name, addresses and numbers masked. */
const ONLINE_INVOICE = <<<'TXT'
Nike.com
請求番号           JP0000000001                        発送先        100-0001 JP-13 千代田区 千代田
ご注文番号          C00000000001                                   １-１-１
発送日            2026/04/23                          支払先        Taro Yamada
100-0001 JP-13 Chiyoda Chiyoda
1-1-1
Sample Heights 1 101
請求書兼領収書
スタイル番号         品名                     販売個数           税込価格
HJ8485         ナイキ レボリューション 8 ウィメン
ズ ロード ランニングシューズ             1         ￥7,700
配送料                         1             ￥0
税抜金額:                                                     ￥7,000
消費税 10%:                                                   ￥700
支払方法:                      クレジットカード                       ￥7,700
ナイキジャパン 東京都港区赤坂9-7-1 ミッドタウンタワー
適格請求書発行事業者登録番号: T9010701007169
ご注意ください - こちらは返品先住所ではありません - 返品については、www.nike.com のヘルプ セクション「返品」をご覧ください。
Page 1 of 1
TXT;

it('classifies an online-shop 請求書兼領収書 as an invoice, although クレジット contains レジ', function () {
    expect(app(DocumentClassifier::class)->classify(ONLINE_INVOICE))->toBe('invoice');
});

it('reads an online-shop invoice without a 合計 label', function () {
    $doc = extractText(ONLINE_INVOICE, 'invoice');

    expect($doc->fields)->toMatchArray([
        'invoice_number' => 'JP0000000001',
        'issue_date' => '2026-04-23',                  // the only date; 支払先 (payer) is not a payment due date
        'registration_number' => 'T9010701007169',
        'subtotal' => 7000,
        'total' => 7700,                                // 税抜金額 + 消費税, and printed as the amount paid
    ])
        ->and($doc->taxes)->toBe([['tax_rate' => 10, 'taxable_amount' => 7000, 'tax_amount' => 700]])
        ->and($doc->lines)->toHaveCount(1)
        ->and($doc->lines[0])->toMatchArray([
            'name' => 'HJ8485 ナイキ レボリューション 8 ウィメンズ ロード ランニングシューズ',
            'quantity' => 1.0,
            'amount' => 7700,
        ]);

    $results = (new ReceiptValidator)->validate(new ValidationContext($doc, 'invoice', 1.0));
    expect(RuleResult::criticalCount($results))->toBe(0);
});

it('does not invent a total that is not printed', function () {
    // 税抜金額 + 消費税 = 7,700, but no amount 7,700 appears anywhere.
    $doc = extractText("請求書\n品名 数量 金額\nA 1 ¥3,000\nB 1 ¥4,000\n税抜金額 ¥7,000\n消費税 10% ¥700", 'invoice');

    expect($doc->get('total'))->toBeNull();
});
