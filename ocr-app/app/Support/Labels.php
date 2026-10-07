<?php

namespace App\Support;

/**
 * Display names of stored codes (statuses, types, routes, check levels…),
 * translated to the current language. Codes stay English in the database and API.
 */
final class Labels
{
    public const STATUSES = [
        'pending' => 'Pending', 'processing' => 'Processing', 'extracted' => 'Extracted',
        'needs_review' => 'Needs review', 'confirmed' => 'Confirmed', 'failed' => 'Failed',
    ];

    public const TYPES = [
        'receipt' => 'Receipt', 'invoice' => 'Invoice', 'paid_receipt' => 'Paid receipt',
        'attendance' => 'Attendance', 'other' => 'Other',
    ];

    public const ROUTES = ['ocr' => 'OCR', 'pdf_text' => 'PDF text', 'spreadsheet' => 'Spreadsheet'];

    public const LEVELS = ['pass' => 'OK', 'warning' => 'Warning', 'critical' => 'Critical'];

    public const TAX_MODES = ['inclusive' => 'Tax included', 'exclusive' => 'Tax excluded'];

    public const PAYMENTS = ['cash' => 'Cash', 'card' => 'Card', 'e_money' => 'E-money', 'other' => 'Other'];

    /** Short titles of the validation rules (spec 3.5). */
    public const RULES = [
        'V-01' => 'Total present', 'V-02' => 'Issue date valid', 'V-03' => 'Registration number',
        'V-04' => 'Lines add up', 'V-05' => 'Tax and total agree', 'V-06' => 'Tax matches rate',
        'V-07' => 'Change correct', 'V-08' => 'Lines per tax rate', 'V-09' => 'OCR confidence',
        'V-10' => 'Duplicate check',
    ];

    /** Receipt fields (spec 3.3) in display order. */
    public const FIELDS = [
        'vendor_name' => 'Vendor', 'registration_number' => 'Registration no.', 'issue_date' => 'Date',
        'invoice_number' => 'Invoice no.', 'due_date' => 'Due date', 'recipient_name' => 'Recipient',
        'subtotal' => 'Subtotal', 'total' => 'Total', 'withholding_tax' => 'Withholding tax',
        'tax_mode' => 'Tax', 'payment_method' => 'Payment', 'cash_received' => 'Cash received',
        'change' => 'Change', 'purpose' => 'Purpose', 'vendor_address' => 'Address', 'vendor_phone' => 'Phone',
    ];

    public static function status(?string $code): string
    {
        return self::label(self::STATUSES, $code);
    }

    public static function type(?string $code): string
    {
        return self::label(self::TYPES, $code);
    }

    public static function route(?string $code): string
    {
        return self::label(self::ROUTES, $code);
    }

    public static function level(?string $code): string
    {
        return self::label(self::LEVELS, $code);
    }

    public static function taxMode(?string $code): string
    {
        return self::label(self::TAX_MODES, $code);
    }

    public static function payment(?string $code): string
    {
        return self::label(self::PAYMENTS, $code);
    }

    public static function rule(?string $code): string
    {
        return self::label(self::RULES, $code);
    }

    public static function field(string $field): string
    {
        return self::label(self::FIELDS, $field);
    }

    /**
     * @param  array<string, string>  $labels
     */
    private static function label(array $labels, ?string $code): string
    {
        if ($code === null) {
            return '—';
        }

        return isset($labels[$code]) ? __($labels[$code]) : $code;
    }
}
