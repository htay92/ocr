{{-- Extracted fields, tax rows, line items and validation (read-only; editing is SC-04, milestone 5). --}}
@php
    $labels = [
        'vendor_name' => 'Vendor', 'registration_number' => 'Registration no.', 'issue_date' => 'Date',
        'invoice_number' => 'Invoice no.', 'due_date' => 'Due date', 'recipient_name' => 'Recipient',
        'subtotal' => 'Subtotal', 'total' => 'Total', 'withholding_tax' => 'Withholding tax',
        'tax_mode' => 'Tax', 'payment_method' => 'Payment', 'cash_received' => 'Cash received',
        'change' => 'Change', 'purpose' => 'Purpose (但し)', 'vendor_address' => 'Address', 'vendor_phone' => 'Phone',
    ];
    $money = ['subtotal', 'total', 'withholding_tax', 'cash_received', 'change'];
    $required = \App\Models\Receipt::REQUIRED[$document->type] ?? [];
    $modes = ['inclusive' => '内税 (tax included)', 'exclusive' => '外税 (tax excluded)'];
    $payments = ['cash' => 'Cash', 'card' => 'Card', 'e_money' => 'E-money', 'other' => 'Other'];
@endphp

<h2>Fields</h2>
<div class="card">
    <dl class="facts">
        @foreach ($labels as $field => $label)
            @php
                $value = $receipt->{$field};
                $value = match (true) {
                    $value === null => null,
                    in_array($field, $money, true) => '¥'.number_format($value),
                    $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                    $field === 'tax_mode' => $modes[$value] ?? $value,
                    $field === 'payment_method' => $payments[$value] ?? $value,
                    default => $value,
                };
                $missing = $value === null && in_array($field, $required, true);
            @endphp
            @if ($value !== null || $missing)
                <dt>{{ $label }}</dt>
                <dd>
                    @if ($missing)
                        <span class="badge critical">missing</span>
                    @else
                        {{ $value }}
                        @if (($confidence = $receipt->field_confidence[$field] ?? null) !== null && $confidence < $lowConfidence)
                            <span class="muted small" title="OCR confidence of this line">({{ number_format($confidence, 2) }})</span>
                        @endif
                    @endif
                </dd>
            @endif
        @endforeach
    </dl>
</div>

@if ($receipt->taxes->isNotEmpty())
    <h2>Tax by rate</h2>
    <div class="card table-wrap">
        <table>
            <thead><tr><th>Rate</th><th class="num">Taxable</th><th class="num">Tax</th></tr></thead>
            <tbody>
            @foreach ($receipt->taxes as $tax)
                <tr><td>{{ $tax->tax_rate }}%{{ $tax->tax_rate === 8 ? ' (軽減)' : '' }}</td><td class="num">¥{{ number_format($tax->taxable_amount) }}</td><td class="num">¥{{ number_format($tax->tax_amount) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

@if ($receipt->lines->isNotEmpty())
    <h2>Line items ({{ $receipt->lines->count() }})</h2>
    <div class="card table-wrap">
        <table>
            <thead><tr><th>#</th><th>Item</th><th class="num">Qty</th><th class="num">Unit</th><th class="num">Amount</th><th>Rate</th><th class="num">Discount</th></tr></thead>
            <tbody>
            @foreach ($receipt->lines as $line)
                <tr>
                    <td>{{ $line->line_no }}</td>
                    <td class="wrap">{{ $line->name }}</td>
                    <td class="num">{{ rtrim(rtrim($line->quantity, '0'), '.') }}</td>
                    <td class="num">{{ $line->unit_price === null ? '' : '¥'.number_format($line->unit_price) }}</td>
                    <td class="num">¥{{ number_format($line->amount) }}</td>
                    <td>{{ $line->tax_rate }}%</td>
                    <td class="num">{{ $line->discount ? '¥'.number_format($line->discount) : '' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
