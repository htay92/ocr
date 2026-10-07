<?php

namespace App\Extraction\Data;

/**
 * What an extractor read from one OCR run: header fields named as the
 * receipts columns (spec 3.3), tax rows and line items.
 */
final class ExtractedDocument
{
    public const FIELDS = [
        'vendor_name', 'vendor_address', 'vendor_phone', 'registration_number', 'issue_date',
        'invoice_number', 'due_date', 'recipient_name', 'subtotal', 'total', 'withholding_tax',
        'tax_mode', 'payment_method', 'cash_received', 'change', 'purpose',
    ];

    /**
     * @param  array<string, mixed>  $fields  every key of FIELDS; null when not found
     * @param  list<array{tax_rate: int, taxable_amount: int, tax_amount: int}>  $taxes
     * @param  list<array{line_no: int, name: string, quantity: float, unit_price: ?int, amount: int, tax_rate: int, discount: int}>  $lines
     * @param  array<string, float>  $fieldConfidence  OCR confidence of the row each field came from
     */
    public function __construct(
        public array $fields,
        public array $taxes = [],
        public array $lines = [],
        public array $fieldConfidence = [],
    ) {
        $this->fields = array_merge(array_fill_keys(self::FIELDS, null), $fields);
    }

    public function get(string $field): mixed
    {
        return $this->fields[$field] ?? null;
    }

    public function taxTotal(): int
    {
        return array_sum(array_column($this->taxes, 'tax_amount'));
    }
}
