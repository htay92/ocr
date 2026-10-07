<?php

namespace App\Processing;

use App\Extraction\Support\JapaneseNormalizer;

/**
 * Picks the document type from keywords (FR-06, spec 3.1).
 */
class DocumentClassifier
{
    public const RECEIPT = 'receipt';

    public const INVOICE = 'invoice';

    public const PAID_RECEIPT = 'paid_receipt';

    public const ATTENDANCE = 'attendance';

    public const OTHER = 'other';

    /** Types the receipt extractor handles. */
    public const RECEIPT_TYPES = [self::RECEIPT, self::INVOICE, self::PAID_RECEIPT];

    public function classify(string $text): string
    {
        $t = preg_replace('/\s+/u', '', JapaneseNormalizer::normalize($text));

        if (preg_match('/勤務データ|勤務区分|所定時間|出勤日数/u', $t)) {
            return self::ATTENDANCE;
        }

        // Till receipts often print 領収証 as a heading; these words only appear on till receipts.
        $till = preg_match('/お?預り|お?預かり|お?釣り?|おつり|釣銭|レジ|点数|お買上/u', $t) === 1;

        if (! $till && preg_match('/請求書|請求金額|御請求|ご請求/u', $t)) {
            return self::INVOICE;
        }

        // Paid receipts are addressed (御中 / 様), state a purpose (但し) or print the amount as "¥5,500-".
        if (! $till && preg_match('/領収書|領収証|領収いたしました/u', $t) && preg_match('/御中|様|但し?|¥[\d,]+-/u', $t)) {
            return self::PAID_RECEIPT;
        }

        if ($till || preg_match('/合計|小計/u', $t)) {
            return self::RECEIPT;
        }

        return self::OTHER;
    }
}
