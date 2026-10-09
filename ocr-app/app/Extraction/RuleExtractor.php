<?php

namespace App\Extraction;

use App\Extraction\Contracts\Extractor;
use App\Extraction\Data\ExtractedDocument;
use App\Extraction\Support\Row;
use App\Extraction\Support\Rows;
use App\Models\OcrRun;
use App\Processing\DocumentClassifier;

/**
 * Rule-based extraction for receipts, invoices and paid receipts (spec 3.4).
 *
 * Works on normalized visual rows. OCR engines still often put a label and its
 * value on separate rows (table cells, right-aligned amounts) and sometimes put
 * the value first, so amounts are also looked for on the next value-only rows,
 * and tax rows are paired by ratio, not by column position.
 */
class RuleExtractor implements Extractor
{
    /**
     * An amount: "1,100", "¥33,000", "154000", "1,100T" (T = tax mark). Not part
     * of a date, time, percentage, phone number, quantity or code ("4S1100",
     * "3F"), and never with a leading zero (account and slip numbers).
     */
    private const AMOUNT = '/(?<![\d,.\/:\-A-Za-z])(¥)?(?!0\d)(?>(\d{1,3}(?:[,.] ?\d{3})+)|(\d+))(?![\d,]|\.\d|[A-SU-Za-z]|\s*(?:%|:|\/|年|月|日|時|分|秒|点|個|名|件|式|-\d))/u';

    private const DATE_START = '/^(\d{4}[年\/.\-])/u';

    /** Document headings, never a vendor name. */
    private const HEADINGS = ['領収書', '領収証', 'レシート', 'お買上明細', '御請求書', '請求書', '納品書', '見積書', 'ご利用明細'];

    private const COMPANY = '/株式会社|有限会社|合同会社|合資会社|\(株\)|\(有\)|事務所|商店|ホテル|旅館|病院|クリニック|医院|薬局|Inc\b|Co\.|LLC/u';

    private const ADDRESS = '/〒\s?\d{3}-?\d{4}|(東京都|北海道|京都府|大阪府|\p{Han}{2,3}県)\p{Han}{1,6}(市|区|町|村|郡)/u';

    private const PHONE = '/(0\d{1,4}-\d{1,4}-\d{4})/u';

    private const GREETING = '/ありがとう|いらっしゃいませ|ご来店|またのお越し/u';

    /** Japanese consumption tax rates, in the order they are reported. */
    private const RATES = [10, 8];

    /** Row words that are never a line item. */
    private const NOT_ITEM = '/#\d|残高|ポイント|会員|利用可能|^No\.?\d|号:|^[^\d¥]{1,8}:\d|合計|小計|請求|金額|期限|期日|振込|口座|銀行|登録|TEL|電話|〒|番号|担当|件名|御中|様|消費税|対象|預|釣|点数|レジ|丁目|番地|源泉|内訳|税抜|税込|現金|クレジット|カード|支払|お買上|^Page\d+of\d+/u';

    /** Rows whose percentage is not a consumption-tax rate. */
    private const NOT_TAX_RATE = '/サービス|手数料|割引|値引|還元|ポイント|OFF|オフ/u';

    private const TOTAL_LABELS = [
        DocumentClassifier::RECEIPT => ['合計', '総合計', 'お買上計', 'お買上合計', 'お買上げ合計', '税込合計'],
        DocumentClassifier::INVOICE => ['ご請求金額', '御請求金額', '請求金額', '今回ご請求額', 'ご請求額', '請求額', '合計', '総合計', '税込合計'],
        DocumentClassifier::PAID_RECEIPT => ['領収金額', '合計金額', '合計', '金額'],
    ];

    // 前回 / 繰越 / 入金 / 残高: a statement's previous balance and payments, a card's remaining balance.
    private const TOTAL_EXCLUDE = ['小計', '点数', '預', '釣', '対象', '税抜', '消費税', '内税', '外税', '源泉', '前回', '繰越', '入金', '残高'];

    /** @var list<Row> */
    private array $rows = [];

    /** @var list<string> row texts without spaces, except between numbers */
    private array $t = [];

    /** @var array<string, int> field => row index it came from */
    private array $source = [];

    public function extract(OcrRun $run, string $type): ExtractedDocument
    {
        return $this->extractLines($run->lines ?? [], $type);
    }

    /**
     * @param  list<array{text: string, confidence: float, bbox: ?array, page?: int}>  $lines
     */
    public function extractLines(array $lines, string $type): ExtractedDocument
    {
        $this->rows = array_values(array_filter(Rows::fromLines($lines), fn (Row $row) => trim($row->text) !== ''));
        $this->t = array_map(fn (Row $row) => self::compact($row->text), $this->rows);
        $this->source = [];

        $receipt = $type === DocumentClassifier::RECEIPT;
        $invoice = $type === DocumentClassifier::INVOICE;
        $taxes = $this->taxes();

        $fields = [
            'vendor_name' => $receipt ? ($this->storeName() ?? $this->issuer()) : ($this->issuer() ?? $this->storeName()),
            'vendor_address' => $this->address(),
            'vendor_phone' => $this->phone(),
            'registration_number' => $this->registrationNumber(),
            'issue_date' => $this->date('issue_date', match ($type) {
                DocumentClassifier::INVOICE => ['請求日', '請求年月日', '発行日', '日付'],
                DocumentClassifier::PAID_RECEIPT => ['領収日', '発行日', '日付'],
                default => ['出庫', '精算日'],          // parking: paid at the exit, not the entry
            }),
            'invoice_number' => $receipt ? null : $this->code('invoice_number', ['請求書番号', '請求番号', '請求No', '伝票番号', 'No.', 'No']),
            'due_date' => $invoice ? $this->date('due_date', ['お支払期限', '支払期限', 'お支払期日', '支払期日', '振込期日', '振込期限', 'お振込期限'], fallback: false) : null,
            'recipient_name' => $receipt ? null : $this->recipient(),
            'subtotal' => $this->amount('subtotal', ['小計', '税抜合計', '税抜金額'], ['対象']),
            'total' => $this->total($type),
            'withholding_tax' => $this->amount('withholding_tax', ['源泉徴収税額', '源泉徴収税', '源泉所得税', '源泉税', '源泉']),
            'tax_mode' => $taxes['mode'],
            'payment_method' => $invoice ? null : $this->paymentMethod(),
            'cash_received' => $invoice ? null : $this->amount('cash_received', ['お預り', 'お預かり', '預り', '預かり', 'お預'], ['釣']),
            'change' => $invoice ? null : $this->amount('change', ['お釣り', 'お釣', 'おつり', '釣銭', '釣り']),
            'purpose' => $type === DocumentClassifier::PAID_RECEIPT ? $this->purpose() : null,
        ];

        // No total label (online-shop invoices print 税抜金額, 消費税 and the amount paid): the total is
        // subtotal + tax, but only when that amount is printed somewhere, never a computed-only value.
        if ($fields['total'] === null && $fields['subtotal'] !== null && $taxes['mode'] === 'exclusive' && $taxes['rows'] !== []) {
            $fields['total'] = $this->printedAmount('total', $fields['subtotal'] + array_sum(array_column($taxes['rows'], 'tax_amount')));
        }

        return new ExtractedDocument(
            fields: $fields,
            taxes: $taxes['rows'],
            lines: $this->lineItems($type),
            fieldConfidence: array_map(fn (int $i) => $this->rows[$i]->confidence, $this->source),
            fieldBoxes: array_filter(array_map(
                fn (int $i) => $this->rows[$i]->box === null ? null : ['page' => $this->rows[$i]->page, 'box' => $this->rows[$i]->box],
                $this->source,
            )),
        );
    }

    // --- header fields --------------------------------------------------------

    /**
     * The store name printed at the top of a till receipt: the tallest line in
     * the top 20% that is not a heading, greeting, date, phone or address line
     * (spec 3.4), plus a branch line ending in 店 right below it.
     */
    private function storeName(): ?string
    {
        $candidates = [];
        foreach ($this->topRows() as $i) {
            $letters = preg_replace('/[^\p{L}]/u', '', $this->t[$i]);
            if (mb_strlen($letters) < 2
                || in_array(preg_replace('/[【】\[\]()]/u', '', $letters), self::HEADINGS, true)
                || preg_match(self::GREETING, $this->t[$i])
                || preg_match(self::PHONE, $this->t[$i]) || preg_match(self::ADDRESS, $this->rows[$i]->text)
                || $this->parseDate($this->t[$i]) !== null
                || preg_match('/御中|様$|TEL|電話|登録番号|No\./u', $this->t[$i])
                || preg_match_all('/\d/u', $this->t[$i]) * 3 > mb_strlen($this->t[$i])) {
                continue;
            }
            $candidates[] = $i;
        }

        if ($candidates === []) {
            $registration = $this->firstRow('登録番号');

            return $registration > 0 ? $this->take('vendor_name', $registration - 1, trim($this->rows[$registration - 1]->text)) : null;
        }

        // Tallest wins; without boxes (text layer) the first one.
        $best = $candidates[0];
        foreach ($candidates as $i) {
            if (($this->rows[$i]->height ?? 0) > ($this->rows[$best]->height ?? 0)) {
                $best = $i;
            }
        }

        $name = trim($this->rows[$best]->text);
        if (! str_ends_with($this->t[$best], '店')) {
            foreach ([$best + 1, $best + 2] as $j) {
                if (isset($this->t[$j]) && str_ends_with($this->t[$j], '店') && ! preg_match('/\d/u', $this->t[$j])) {
                    $name .= ' '.trim($this->rows[$j]->text);
                    break;
                }
            }
        }

        return $this->take('vendor_name', $best, $name);
    }

    /**
     * The issuing company on invoices and paid receipts: a company line that is
     * not the recipient (御中 / 様) or a bank line; nearest to 登録番号 if present.
     */
    private function issuer(): ?string
    {
        $candidates = [];
        foreach ($this->rows as $i => $row) {
            $next = $this->t[$i + 1] ?? '';
            if (preg_match(self::COMPANY, $row->text)
                && ! preg_match('/御中|様|銀行|振込|口座|件名/u', $this->t[$i])
                && ! preg_match('/^(御中|様)/u', $next)) {
                $candidates[$i] = trim(preg_replace('/^(発行者|発行元|会社名|社名)\s?:?\s?/u', '', $row->text));
            }
        }

        if ($candidates === []) {
            return null;
        }

        $registration = $this->firstRow('登録番号');
        if ($registration !== null) {
            $nearest = null;
            foreach (array_keys($candidates) as $i) {
                if (abs($i - $registration) <= 3 && ($nearest === null || abs($i - $registration) < abs($nearest - $registration))) {
                    $nearest = $i;
                }
            }
            if ($nearest !== null) {
                return $this->take('vendor_name', $nearest, $candidates[$nearest]);
            }
        }

        // The issuer is usually printed below the recipient.
        $recipient = $this->firstRow('御中');
        foreach ($candidates as $i => $name) {
            if ($recipient === null || $i > $recipient) {
                return $this->take('vendor_name', $i, $name);
            }
        }

        $first = array_key_first($candidates);

        return $this->take('vendor_name', $first, $candidates[$first]);
    }

    /**
     * Who the document is addressed to: the name before 御中, or before a
     * trailing 様, or the line above a standalone 御中 / 様.
     */
    private function recipient(): ?string
    {
        foreach (['/^(.*?)\s*御中/u', '/^(.*?)\s*様[\s.。:]*$/u'] as $pattern) {
            foreach ($this->rows as $i => $row) {
                if (str_contains($this->t[$i], 'お客様') || ! preg_match($pattern, $row->text, $m)) {
                    continue;
                }

                $name = trim(preg_replace('/^(宛名|お名前|請求先)\s?:?\s?/u', '', $m[1]));
                $from = $i;
                if ($name === '' && $i > 0) {
                    $name = trim($this->rows[$i - 1]->text);
                    $from = $i - 1;
                }
                if (preg_match('/\p{L}/u', $name)) {
                    return $this->take('recipient_name', $from, $name);
                }
            }
        }

        return null;
    }

    private function address(): ?string
    {
        foreach ($this->rows as $i => $row) {
            if (! preg_match(self::ADDRESS, $row->text) || preg_match('/御中|様/u', $this->t[$i])) {
                continue;
            }

            $address = trim(preg_replace('/〒\s?\d{3}-?\d{4}/u', '', $row->text));
            if ($address === '' && isset($this->rows[$i + 1]) && preg_match('/\p{Han}/u', $this->t[$i + 1])) {
                return $this->take('vendor_address', $i + 1, trim($this->rows[$i + 1]->text));
            }
            if ($address !== '') {
                return $this->take('vendor_address', $i, $address);
            }
        }

        return null;
    }

    private function phone(): ?string
    {
        foreach ($this->t as $i => $text) {
            if (preg_match(self::PHONE, $text, $m) && ! preg_match('/FAX/iu', $text)) {
                return $this->take('vendor_phone', $i, $m[1]);
            }
        }

        return null;
    }

    /**
     * T + 13 digits. OCR sometimes drops the T, splits it off, or reads it as 7 (spec 3.4).
     */
    private function registrationNumber(): ?string
    {
        foreach ($this->t as $i => $text) {
            if (preg_match('/T(\d{13})(?!\d)/u', $text, $m)) {
                return $this->take('registration_number', $i, 'T'.$m[1]);
            }
        }

        foreach ($this->t as $i => $text) {
            if (preg_match('/登録番号:?T?7?(\d{13})(?!\d)/u', $text, $m)
                || (str_contains($text, '登録番号') && preg_match('/^T?7?(\d{13})$/u', $this->t[$i + 1] ?? '', $m))) {
                return $this->take('registration_number', $i, 'T'.$m[1]);
            }
        }

        return null;
    }

    /**
     * The date after one of the labels, on the same row or the next one or two
     * rows. A printed but empty date field ("請求日 年 月 日") means no date.
     * Without labels, the first date that is not a due or delivery date.
     *
     * @param  list<string>  $labels
     */
    private function date(string $field, array $labels, bool $fallback = true): ?string
    {
        $labelSeen = false;

        foreach ($labels as $label) {
            foreach ($this->t as $i => $text) {
                $pos = mb_strpos($text, $label);
                if ($pos === false) {
                    continue;
                }
                $labelSeen = true;

                if ($date = $this->parseDate(mb_substr($text, $pos + mb_strlen($label)))) {
                    return $this->take($field, $i, $date);
                }
                for ($j = $i + 1; $j <= $i + 2 && isset($this->t[$j]); $j++) {
                    // Only a row that starts with the date, not a table row like "1 2025/7/1 商品A".
                    if (preg_match(self::DATE_START, $this->t[$j]) && $date = $this->parseDate($this->t[$j])) {
                        return $this->take($field, $j, $date);
                    }
                }
            }
        }

        if ($labelSeen || ! $fallback) {
            return null;
        }

        foreach ($this->t as $i => $text) {
            // Skip due, delivery and validity dates; 支払先 / 支払方法 (payer, payment method) are fine.
            if (! preg_match('/期限|期日|支払(?!先|方法)|振込|納品|有効/u', $text) && $date = $this->parseDate($text)) {
                return $this->take($field, $i, $date);
            }
        }

        return null;
    }

    /**
     * An identifier after a label ("請求書番号: S-202504-001", "No 1001"), on
     * the same row or the next. A printed but blank label gives null.
     *
     * @param  list<string>  $labels
     */
    private function code(string $field, array $labels): ?string
    {
        foreach ($labels as $label) {
            foreach ($this->t as $i => $text) {
                $pos = mb_strpos($text, $label);
                if ($pos === false) {
                    continue;
                }

                $rest = mb_substr($text, $pos + mb_strlen($label));
                if (preg_match('/^[a-z]/u', $rest)) {
                    continue; // "No" inside a word such as "Nov"
                }

                $rest = ltrim($rest, ':#.');
                if (preg_match('/^([A-Za-z0-9][A-Za-z0-9\-]*)/u', $rest, $m)) {
                    return $this->take($field, $i, $m[1]);
                }
                if ($rest === '' && preg_match('/^([A-Za-z0-9][A-Za-z0-9\-]*)$/u', $this->t[$i + 1] ?? '', $m)) {
                    return $this->take($field, $i + 1, $m[1]);
                }

                return null;
            }
        }

        return null;
    }

    /**
     * What a paid receipt was for: the text after 但し up to として (spec 3.4),
     * on the same row or the next. A blank 但し gives null.
     */
    private function purpose(): ?string
    {
        foreach ($this->t as $i => $text) {
            if (! preg_match('/^但し?(?:書き)?:?(.*)$/u', $text, $m)) {
                continue;
            }

            $value = $m[1];
            $from = $i;
            if ($value === '' && isset($this->t[$i + 1])
                && ! preg_match('/^(領収日|発行日|上記|内訳|金額|税抜|消費税|¥|\d)/u', $this->t[$i + 1])) {
                $value = $this->t[$i + 1];
                $from = $i + 1;
            }

            $value = preg_replace('/として.*$/u', '', $value);

            return preg_match('/\p{L}/u', $value) ? $this->take('purpose', $from, $value) : null;
        }

        return null;
    }

    private function paymentMethod(): ?string
    {
        $text = implode("\n", $this->t);

        return match (true) {
            (bool) preg_match('/電子マネー|交通系|Suica|PASMO|ICOCA|QUICPay|iD|PayPay|nanaco|WAON|Edy|楽天ペイ|d払い|auPAY|メルペイ/u', $text) => 'e_money',
            (bool) preg_match('/クレジット|カード|VISA|JCB|Master|AMEX|ダイナース/iu', $text) => 'card',
            (bool) preg_match('/現金|お?預り|お?預かり|お?釣り?|おつり/u', $text) => 'cash',
            default => null,
        };
    }

    // --- amounts --------------------------------------------------------------

    /**
     * The total (spec 3.4): rows with a total label, rightmost amount after the
     * label (or on the next value row). With several matches, the largest in
     * the lower half of the document wins.
     */
    private function total(string $type): ?int
    {
        $found = [];
        foreach (self::TOTAL_LABELS[$type] ?? self::TOTAL_LABELS[DocumentClassifier::RECEIPT] as $label) {
            foreach ($this->t as $i => $text) {
                if (isset($found[$i]) || ! str_contains($text, $label) || $this->containsAny($text, self::TOTAL_EXCLUDE)) {
                    continue;
                }
                if ($value = $this->valueAfter($i, $label, rightmost: true)) {
                    $found[$i] = $value;
                }
            }
        }

        if ($found === []) {
            return match ($type) {
                DocumentClassifier::PAID_RECEIPT => $this->yenDashAmount(),
                DocumentClassifier::INVOICE => $this->headlineYenAmount(),
                default => null,
            };
        }

        $lower = array_filter($found, fn ($value, $i) => $i >= count($this->rows) / 2, ARRAY_FILTER_USE_BOTH);
        $pool = $lower !== [] ? $lower : $found;
        $best = array_search(max(array_column($pool, 0)), array_column($pool, 0), true);
        [$amount, $row] = array_values($pool)[$best];

        // A return or refund prints the total as "-¥700" or "¥-700".
        $digits = preg_quote(number_format($amount), '/').'|'.$amount;
        if (preg_match("/(?:-\\s?¥|¥\\s?-|-)\\s?(?:{$digits})(?!\\d)/u", $this->t[$row])) {
            $amount = -$amount;
        }

        return $this->take('total', $row, $amount);
    }

    /** $amount if a row prints it (the last such row, nearest to the totals), else null. */
    private function printedAmount(string $field, int $amount): ?int
    {
        foreach (array_reverse($this->t, true) as $i => $text) {
            if (in_array($amount, $this->amounts($text), true)) {
                return $this->take($field, $i, $amount);
            }
        }

        return null;
    }

    /**
     * Amount next to the first matching label.
     *
     * @param  list<string>  $labels
     * @param  list<string>  $exclude
     */
    private function amount(string $field, array $labels, array $exclude = []): ?int
    {
        foreach ($labels as $label) {
            foreach ($this->t as $i => $text) {
                if (str_contains($text, $label) && ! $this->containsAny($text, $exclude)
                    && $value = $this->valueAfter($i, $label)) {
                    return $this->take($field, $value[1], $value[0]);
                }
            }
        }

        return null;
    }

    /**
     * The amount after a label on row $i, or on one of the next two rows when
     * that row is just a value. Stops at another label, but skips qualifiers
     * like "(税抜)".
     *
     * @return array{0: int, 1: int}|null [amount, row index]
     */
    private function valueAfter(int $i, string $label, bool $rightmost = false): ?array
    {
        $after = mb_substr($this->t[$i], mb_strpos($this->t[$i], $label) + mb_strlen($label));
        $amounts = $this->amounts($after);
        if ($amounts !== []) {
            return [$rightmost ? end($amounts) : $amounts[0], $i];
        }

        for ($j = $i + 1; $j <= $i + 2 && isset($this->t[$j]); $j++) {
            if (preg_match('/^[¥(]?\d/u', $this->t[$j])) {
                $amounts = $this->amounts($this->t[$j]);

                return $amounts === [] ? null : [$amounts[0], $j];
            }
            if (preg_match('/\p{L}{2}/u', $this->t[$j]) && ! preg_match('/^\(.*\)$/u', $this->t[$j])) {
                return null; // another label, not ours
            }
        }

        return null;
    }

    /**
     * Invoices print the amount due large in a 「ご請求金額」 box above the items. When
     * no total label could be read (a small or blurred image), the largest row in
     * the upper half that is only a yen amount ("¥119,800", "¥ 119,800-") is it.
     */
    private function headlineYenAmount(): ?int
    {
        $best = null;
        foreach ($this->t as $i => $text) {
            if ($i < count($this->t) / 2 && preg_match('/^\W{0,2}¥\s?(\d{1,3}(?:,\d{3})+|\d{4,})-?\W{0,2}$/u', $text, $m)) {
                $amount = self::number($m[1]);
                if ($best === null || $amount > $best[0]) {
                    $best = [$amount, $i];
                }
            }
        }

        return $best === null ? null : $this->take('total', $best[1], $best[0]);
    }

    /**
     * Paid receipts print the amount as "¥5,500-", often without a label.
     */
    private function yenDashAmount(): ?int
    {
        foreach ($this->t as $i => $text) {
            if (preg_match('/¥(\d{1,3}(?:,\d{3})+|\d+)-/u', $text, $m)) {
                return $this->take('total', $i, self::number($m[1]));
            }
        }

        return null;
    }

    /**
     * Tax rows per rate and the tax mode. Near every "10%" / "8%" it looks for
     * two amounts where one is the tax on the other: base × r (外税, exclusive)
     * or base × r / (100 + r) (内税, inclusive). The ratio, not the column,
     * decides which is which. Without a per-rate breakdown, a single 消費税
     * amount is matched against the subtotal or taxable base (exclusive) or the
     * total (inclusive) to infer the rate.
     *
     * @return array{rows: list<array{tax_rate: int, taxable_amount: int, tax_amount: int}>, mode: ?string}
     */
    private function taxes(): array
    {
        // "ご請求金額(税込)" / "合計(税込)" only says the total includes tax; it says nothing about the prices.
        $text = preg_replace('/(請求金額|請求額|合計|総額|お支払金額)[(（]?税込[)）]?/u', '$1', implode("\n", $this->t));
        $stated = match (true) {
            (bool) preg_match('/外税|外消費税|税抜/u', $text) => 'exclusive',
            (bool) preg_match('/内税|内消費税|うち消費税|\(内\)|税込/u', $text) => 'inclusive',
            default => null,
        };

        $rows = [];
        $modes = [];
        $anyRate = '/(?<![\d.])(?:10|8)(?:\.0)?%/u';

        foreach (self::RATES as $rate) {
            $thisRate = '/(?<![\d.])'.$rate.'(?:\.0)?%/u';
            foreach ($this->t as $i => $line) {
                // A rate on a service charge, fee or discount row is not a tax rate.
                if (! preg_match($thisRate, $line, $m, PREG_OFFSET_CAPTURE) || preg_match(self::NOT_TAX_RATE, $line)) {
                    continue;
                }

                $amounts = [...$this->amounts(substr($line, $m[0][1] + strlen($m[0][0]))), ...$this->amounts(substr($line, 0, $m[0][1]))];
                for ($j = $i - 3; $j <= $i + 3; $j++) {
                    // Neighbouring rows: those without a rate, or with the same rate ("外税8%対象額 ¥1,124" / "外税8% ¥89").
                    if ($j !== $i && isset($this->t[$j]) && (! preg_match($anyRate, $this->t[$j]) || preg_match($thisRate, $this->t[$j]))
                        && ! preg_match(self::NOT_TAX_RATE, $this->t[$j])) {
                        array_push($amounts, ...$this->amounts($this->t[$j]));
                    }
                }

                if ($pair = self::taxPair($amounts, $rate, $stated)) {
                    $rows[] = ['tax_rate' => $rate, 'taxable_amount' => $pair[1], 'tax_amount' => $pair[0]];
                    $modes[] = $pair[2];
                    break;
                }
            }
        }

        if ($rows === []) {
            $tax = $this->amountOnly(['内消費税等', 'うち消費税等', '消費税等', '内税', '消費税'], ['対象', '%']);
            $bases = [
                [$this->amountOnly(['外税対象', '小計', '税抜合計', '税抜金額']), 'exclusive'],
                [$this->amountOnly(['合計金額', '合計'], ['小計', '税抜', '対象', '消費税']), 'inclusive'],
            ];
            if ($stated === 'inclusive') {
                $bases = array_reverse($bases);
            }

            foreach ($bases as [$base, $mode]) {
                foreach (self::RATES as $rate) {
                    if ($tax && $base && abs($tax - self::taxOn($base, $rate, $mode)) <= 1) {
                        $rows[] = ['tax_rate' => $rate, 'taxable_amount' => $base, 'tax_amount' => $tax];
                        $modes[] = $mode;
                        break 2;
                    }
                }
            }
        }

        return ['rows' => $rows, 'mode' => $stated ?? ($modes[0] ?? null)];
    }

    /**
     * @param  list<int>  $amounts
     * @return array{0: int, 1: int, 2: string}|null [tax, base, mode]
     */
    private static function taxPair(array $amounts, int $rate, ?string $stated): ?array
    {
        $modes = $stated === 'inclusive' ? ['inclusive', 'exclusive'] : ['exclusive', 'inclusive'];

        foreach ($modes as $mode) {
            foreach ($amounts as $tax) {
                foreach ($amounts as $base) {
                    // Below ¥100 the ±1 yen rounding tolerance would pair almost anything.
                    if ($tax > 0 && $base >= 100 && $base > $tax && abs($tax - self::taxOn($base, $rate, $mode)) <= 1) {
                        return [$tax, $base, $mode];
                    }
                }
            }
        }

        return null;
    }

    private static function taxOn(int $base, int $rate, string $mode): float
    {
        return $mode === 'inclusive' ? $base * $rate / (100 + $rate) : $base * $rate / 100;
    }

    /**
     * Like amount(), without recording a field source.
     *
     * @param  list<string>  $labels
     * @param  list<string>  $exclude
     */
    private function amountOnly(array $labels, array $exclude = []): ?int
    {
        foreach ($labels as $label) {
            foreach ($this->t as $i => $text) {
                if (str_contains($text, $label) && ! $this->containsAny($text, $exclude)
                    && $value = $this->valueAfter($i, $label)) {
                    return $value[0];
                }
            }
        }

        return null;
    }

    // --- line items -----------------------------------------------------------

    /**
     * Line items (spec 3.4): rows between the header and the first 小計 / 合計
     * row with a name and an amount. A quantity row ("2個 × ¥130") and a
     * discount row ("値引 -¥50") belong to the item above. ※ or 軽 marks the
     * reduced 8% rate, unless the legend says ※ marks standard-rate items.
     *
     * @return list<array{line_no: int, name: string, quantity: float, unit_price: ?int, amount: int, tax_rate: int, discount: int}>
     */
    private function lineItems(string $type): array
    {
        $text = implode("\n", $this->t);
        $markIsStandard = (bool) preg_match('/※[^\n]{0,8}標準税率|標準税率[^\n]{0,8}※/u', $text);

        $start = 0;
        if ($type === DocumentClassifier::RECEIPT) {
            foreach ($this->t as $i => $row) {
                if ($this->parseDate($row) !== null) {
                    $start = $i + 1;
                    break;
                }
            }
        }

        // Invoice tables have a header row (品名 … 数量 / 金額); items start below it. When the table prints
        // its prices with ¥, a row without a ¥ amount is not an item but the first part of a wrapped
        // name ("ナイキ レボリューション 8 ウィメン" + "ズ ロード ランニングシューズ 1 ¥7,700").
        $header = null;
        if ($type !== DocumentClassifier::RECEIPT) {
            foreach ($this->t as $i => $row) {
                if (preg_match('/品名|品目|商品名|内容|摘要/u', $row) && preg_match('/数量|個数|単価|金額|価格/u', $row)) {
                    $header = $i;
                    $start = $i + 1;
                    break;
                }
            }
        }
        $yenTable = $header !== null && collect(array_slice($this->t, $start, 30))->contains(fn ($row) => preg_match('/¥\d/u', $row));

        $items = [];
        $pendingName = null;
        for ($i = $start; $i < count($this->rows); $i++) {
            $row = $this->rows[$i]->text;
            $compact = $this->t[$i];

            // Items end at the subtotal or total. Invoices and paid receipts may print the total in
            // the header, above the items; on till receipts the total always ends the items.
            if (preg_match('/小計/u', $compact)
                || (($items !== [] || $type === DocumentClassifier::RECEIPT) && preg_match('/合計|お買上計/u', $compact))) {
                break;
            }

            if ($items !== [] && preg_match('/値引|割引|クーポン/u', $compact) && preg_match('/(?:-¥?|¥-)\s?(\d[\d,]*)/u', $row, $m)) {
                $items[count($items) - 1]['discount'] -= self::number($m[1]);

                continue;
            }

            if ($items !== [] && preg_match('/^(\d+)\s?(?:点|個|コ)?\s?(?:[x×]\s?@?|@)\s?¥?(\d[\d,]*)$/u', trim($row), $m)) {
                $items[count($items) - 1]['quantity'] = (float) $m[1];
                $items[count($items) - 1]['unit_price'] = self::number($m[2]);

                continue;
            }

            if (preg_match(self::NOT_ITEM, $compact) || preg_match(self::GREETING, $compact)
                || preg_match('/'.implode('|', self::HEADINGS).'/u', $compact)
                || preg_match(self::ADDRESS, $row) || preg_match('/住所|ビル|\d+F(?![A-Za-z])|\d+階/u', $compact)) {
                $pendingName = null;

                continue;
            }

            $item = $yenTable && ! preg_match('/¥\d/u', $compact) ? null : $this->item($row, $markIsStandard);
            if ($item === null) {
                // A name without an amount; the next row may carry the amount behind a tax mark ("外 47").
                $pendingName = preg_match('/\p{L}{2}/u', $compact) ? trim($row) : null;

                continue;
            }

            if (mb_strlen($item['name']) <= 1 && $pendingName !== null) {
                $item['name'] = $pendingName;
            } elseif ($yenTable && $pendingName !== null) {
                $item['name'] = self::joinWrapped(preg_replace('/\s+/u', ' ', $pendingName), $item['name']);
            }
            $pendingName = null;
            $items[] = ['line_no' => count($items) + 1, ...$item];
        }

        return $items;
    }

    /** A name wrapped onto the next row: Japanese text continues without a space ("ウィメン" + "ズ"). */
    private static function joinWrapped(string $first, string $rest): string
    {
        $cjk = '[\p{Han}\p{Hiragana}\p{Katakana}ー]';

        return preg_match("/{$cjk}$/u", $first) && preg_match("/^{$cjk}/u", $rest) ? $first.$rest : "{$first} {$rest}";
    }

    /**
     * @return array{name: string, quantity: float, unit_price: ?int, amount: int, tax_rate: int, discount: int}|null
     */
    private function item(string $row, bool $markIsStandard): ?array
    {
        $marked = (bool) preg_match('/^\s?[※軽]|[※軽]\s?$|\d軽/u', $row);
        $row = trim(preg_replace('/^\s?[※軽]\s?|\s?[※軽]\s?$/u', '', $row));

        // Invoice table rows: "1 2025/7/1 商品A 1 式 ¥1,000,000 10% ¥1,000,000".
        $rate = null;
        if (preg_match('/(?<![\d.])(10|8)\s?%/u', $row, $m)) {
            $rate = (int) $m[1];
            $row = str_replace($m[0], ' ', $row);
        }
        $row = preg_replace('/\d{4}[\/.\-]\d{1,2}[\/.\-]\d{1,2}/u', ' ', $row);
        $row = preg_replace('/^\d{1,3}\s+(?=\D)/u', '', trim($row));

        $quantity = 1.0;
        $counted = false;
        if (preg_match('/(?<![\d,])(\d+(?:\.\d+)?)\s?(式|個|点|コ|本|枚|件|台|人|回|時間|L|ℓ|リットル)(?![A-Za-z])/u', $row, $m)) {
            $quantity = (float) $m[1];
            $counted = true;
            $row = str_replace($m[0], ' ', $row);
        }

        preg_match_all(self::AMOUNT, $row, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        // A number glued to the name is part of it ("サブル1"), not a price.
        $matches = array_values(array_filter($matches, fn ($x) => $x[0][1] === 0 || ! preg_match('/\p{L}$/u', substr($row, 0, $x[0][1]))));
        if ($matches === []) {
            return null;
        }
        $amounts = array_map(fn ($x) => self::number(($x[2][0] ?? '') !== '' ? $x[2][0] : $x[3][0]), $matches);
        $name = trim(preg_replace('/(?:\s*[x×]\s*@?|\s*@|\s*-\s*¥?|\s*¥)+$/u', '', substr($row, 0, $matches[0][0][1])));
        // A returned item prints its amount as "-¥550".
        $negative = (bool) preg_match('/-\s?¥?\s?$/u', substr($row, 0, $matches[array_key_last($matches)][0][1]));

        // Table columns: quantity, unit price, amount ("医品名 100 1,200 120,000").
        $unit = count($amounts) > 1 ? $amounts[0] : null;
        // Two columns, a plain count and a ¥ price ("ランニングシューズ 1 ¥7,700"): quantity and amount.
        if (! $counted && count($amounts) === 2 && $matches[0][1][0] !== '¥' && $matches[1][1][0] === '¥' && $amounts[0] > 0 && $amounts[0] < 1000) {
            [$quantity, $unit] = [(float) $amounts[0], $amounts[1] % $amounts[0] === 0 ? intdiv($amounts[1], $amounts[0]) : null];
        }
        if (count($amounts) >= 3 && end($amounts) === $amounts[0] * $amounts[1]) {
            [$quantity, $unit] = [(float) $amounts[0], $amounts[1]];
        }

        // A name needs letters; sample documents also use placeholders such as △△△△ or ○○.
        if (! preg_match('/[\p{L}○〇●△▲▽□■◇◆×]/u', $name) || end($amounts) <= 0) {
            return null;
        }

        return [
            'name' => preg_replace('/\s+/u', ' ', $name),
            'quantity' => $quantity,
            'unit_price' => $unit,
            'amount' => $negative ? -end($amounts) : end($amounts),
            'tax_rate' => $rate ?? (($marked xor $markIsStandard) ? 8 : 10),
            'discount' => 0,
        ];
    }

    // --- helpers --------------------------------------------------------------

    /**
     * Row indexes in the top 20% of the first page's text (by position when
     * boxes exist, otherwise by row count), and always the first three rows.
     *
     * @return list<int>
     */
    private function topRows(): array
    {
        $first = range(0, min(3, count($this->rows)) - 1);
        $tops = array_filter(array_map(fn (Row $row) => $row->page === 1 ? $row->top : null, $this->rows), fn ($top) => $top !== null);

        if ($tops === []) {
            return array_values(array_unique([...$first, ...range(0, max(0, (int) ceil(count($this->rows) * 0.2) - 1))]));
        }

        // The text area, not the image: photos often have empty space above the receipt.
        $start = min($tops);
        $end = max(array_map(fn (Row $row) => ($row->top ?? 0) + ($row->height ?? 0), $this->rows));
        $band = array_keys(array_filter($tops, fn (float $top) => $top <= $start + ($end - $start) * 0.2));

        $rows = array_values(array_unique([...$first, ...$band]));
        sort($rows);

        return $rows;
    }

    private function parseDate(string $text): ?string
    {
        $text = self::compact($text);

        // A time stuck to the day ("2020/1/115:01") is split off, preferring a two-digit hour.
        $text = preg_replace('/(\d{4}[\/.\-]\d{1,2}[\/.\-]\d{1,2}?)(\d{2}:\d{2})/u', '$1 $2', $text);

        if (preg_match_all('/(\d{4})[年\/.\-](\d{1,2})[月\/.\-](\d{1,2})(?!\d)/u', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                if (checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && $m[1] >= 1990) {
                    return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                }
            }
        }

        // Month-end: "2023年2月末(日)".
        if (preg_match('/(\d{4})[年\/.\-](\d{1,2})月?末/u', $text, $m) && checkdate((int) $m[2], 1, (int) $m[1])) {
            return date('Y-m-t', mktime(0, 0, 0, (int) $m[2], 1, (int) $m[1]));
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function amounts(string $text): array
    {
        preg_match_all(self::AMOUNT, $text, $m, PREG_SET_ORDER);

        return array_map(fn ($x) => self::number(($x[2] ?? '') !== '' ? $x[2] : $x[3]), $m);
    }

    private static function number(string $digits): int
    {
        return (int) str_replace([',', '.', ' '], '', $digits);
    }

    /**
     * Drop spaces unless they separate two numbers, so "合 計" matches "合計"
     * but "5,000 50,000" stays two amounts.
     */
    private static function compact(string $text): string
    {
        return preg_replace('/(?<![\d,])\s+|\s+(?![\d,])/u', '', trim($text));
    }

    private function take(string $field, int $row, mixed $value): mixed
    {
        $this->source[$field] = $row;

        return $value;
    }

    private function firstRow(string $needle): ?int
    {
        foreach ($this->t as $i => $text) {
            if (str_contains($text, $needle)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
