<?php

namespace App\Http\Requests;

use App\Extraction\Data\ExtractedDocument;
use App\Processing\DocumentClassifier;
use App\Support\Labels;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Corrections from the review screen (SC-04) and PUT /api/documents/{id}.
 * The T-number format is not enforced here: V-03 reports it, so a document
 * with an unusual number can still be saved and confirmed.
 */
class UpdateReceiptRequest extends FormRequest
{
    private const MONEY = ['subtotal', 'total', 'withholding_tax', 'cash_received', 'change'];

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('document'));
    }

    /**
     * Accept amounts as typed ("¥1,280", "１２８０"), and drop table rows left empty.
     */
    protected function prepareForValidation(): void
    {
        $fields = (array) $this->input('fields', []);
        foreach ($fields as $key => $value) {
            $fields[$key] = in_array($key, self::MONEY, true) ? self::number($value) : self::text($value);
        }

        // Keys are kept, so errors point at the row the user typed in (re-indexed in corrections()).
        $rows = fn (string $key, array $numeric) => array_filter(
            array_map(function ($row) use ($numeric) {
                $row = (array) $row;
                foreach ($row as $column => $value) {
                    $row[$column] = in_array($column, $numeric, true) ? self::number($value) : self::text($value);
                }

                return $row;
            }, (array) $this->input($key, [])),
            fn (array $row) => array_filter($row, fn ($value) => $value !== null && $value !== '') !== [],
        );

        $this->merge([
            'fields' => $fields,
            'taxes' => $rows('taxes', ['tax_rate', 'taxable_amount', 'tax_amount']),
            'lines' => $rows('lines', ['quantity', 'unit_price', 'amount', 'tax_rate', 'discount']),
        ]);
    }

    public function rules(): array
    {
        $money = ['nullable', 'integer', 'min:0', 'max:2000000000'];

        return [
            'type' => ['required', Rule::in(DocumentClassifier::RECEIPT_TYPES)],
            'fields' => ['array'],
            'fields.vendor_name' => ['nullable', 'string', 'max:255'],
            'fields.vendor_address' => ['nullable', 'string', 'max:255'],
            'fields.vendor_phone' => ['nullable', 'string', 'max:50'],
            'fields.registration_number' => ['nullable', 'string', 'max:14'],
            'fields.issue_date' => ['nullable', 'date_format:Y-m-d'],
            'fields.invoice_number' => ['nullable', 'string', 'max:50'],
            'fields.due_date' => ['nullable', 'date_format:Y-m-d'],
            'fields.recipient_name' => ['nullable', 'string', 'max:255'],
            'fields.subtotal' => $money,
            'fields.total' => $money,
            'fields.withholding_tax' => $money,
            'fields.cash_received' => $money,
            'fields.change' => $money,
            'fields.tax_mode' => ['nullable', Rule::in(['inclusive', 'exclusive'])],
            'fields.payment_method' => ['nullable', Rule::in(['cash', 'card', 'e_money', 'other'])],
            'fields.purpose' => ['nullable', 'string', 'max:255'],
            'taxes' => ['array', 'max:5'],
            'taxes.*.tax_rate' => ['required', Rule::in([8, 10])],
            'taxes.*.taxable_amount' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'taxes.*.tax_amount' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'lines' => ['array', 'max:300'],
            'lines.*.name' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'lines.*.unit_price' => ['nullable', 'integer', 'max:2000000000'],
            'lines.*.amount' => ['required', 'integer', 'min:-2000000000', 'max:2000000000'],
            'lines.*.tax_rate' => ['required', Rule::in([8, 10])],
            'lines.*.discount' => ['nullable', 'integer', 'max:0'],
        ];
    }

    /**
     * @return array{type: string, fields: array<string, mixed>, taxes: list<array>, lines: list<array>}
     */
    public function corrections(): array
    {
        $data = $this->validated();

        return [
            'type' => $data['type'],
            'fields' => array_intersect_key($data['fields'] ?? [], array_flip(ExtractedDocument::FIELDS)),
            'taxes' => array_values(array_map(fn (array $row) => array_map('intval', $row), $data['taxes'] ?? [])),
            'lines' => array_values(array_map(fn (array $line) => [
                'name' => $line['name'],
                'quantity' => (float) ($line['quantity'] ?? 1),
                'unit_price' => $line['unit_price'] ?? null,
                'amount' => (int) $line['amount'],
                'tax_rate' => (int) $line['tax_rate'],
                'discount' => (int) ($line['discount'] ?? 0),
            ], $data['lines'] ?? [])),
        ];
    }

    public function attributes(): array
    {
        return collect(Labels::FIELDS)
            ->mapWithKeys(fn (string $label, string $field) => ["fields.{$field}" => __($label)])
            ->merge([
                'taxes.*.tax_rate' => __('Rate'), 'taxes.*.taxable_amount' => __('Taxable'), 'taxes.*.tax_amount' => __('Tax'),
                'lines.*.name' => __('Item'), 'lines.*.quantity' => __('Qty'), 'lines.*.unit_price' => __('Unit price'),
                'lines.*.amount' => __('Amount'), 'lines.*.tax_rate' => __('Rate'), 'lines.*.discount' => __('Discount'),
            ])->all();
    }

    private static function number(mixed $value): int|float|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        $clean = str_replace([',', '¥', '円', ' '], '', \Normalizer::normalize((string) $value, \Normalizer::FORM_KC) ?: (string) $value);

        return is_numeric($clean) ? $clean + 0 : (string) $value;   // non-numbers fail validation with a message
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === '' ? null : (is_scalar($value) ? (string) $value : null);
    }
}
