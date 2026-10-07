{{-- Extracted fields, tax rows, line items (read-only; editing is SC-04, milestone 5). --}}
@php
    use App\Support\Labels;
    $money = ['subtotal', 'total', 'withholding_tax', 'cash_received', 'change'];
    $required = \App\Models\Receipt::REQUIRED[$document->type] ?? [];
@endphp

<h2>{{ __('Fields') }}</h2>
<div class="card">
    <dl class="facts">
        @foreach (array_keys(Labels::FIELDS) as $field)
            @php
                $value = $receipt->{$field};
                $value = match (true) {
                    $value === null => null,
                    in_array($field, $money, true) => '¥'.number_format($value),
                    $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                    $field === 'tax_mode' => Labels::taxMode($value),
                    $field === 'payment_method' => Labels::payment($value),
                    default => $value,
                };
                $missing = $value === null && in_array($field, $required, true);
            @endphp
            @if ($value !== null || $missing)
                <dt>{{ Labels::field($field) }}</dt>
                <dd>
                    @if ($missing)
                        <span class="badge critical">{{ __('Missing') }}</span>
                    @else
                        {{ $value }}
                        @if (($confidence = $receipt->field_confidence[$field] ?? null) !== null && $confidence < $lowConfidence)
                            <span class="muted small" title="{{ __('OCR confidence of this line') }}">({{ number_format($confidence, 2) }})</span>
                        @endif
                    @endif
                </dd>
            @endif
        @endforeach
    </dl>
</div>

@if ($receipt->taxes->isNotEmpty())
    <h2>{{ __('Tax by rate') }}</h2>
    <div class="card table-wrap">
        <table>
            <thead><tr><th>{{ __('Rate') }}</th><th class="num">{{ __('Taxable') }}</th><th class="num">{{ __('Tax') }}</th></tr></thead>
            <tbody>
            @foreach ($receipt->taxes as $tax)
                <tr><td>{{ $tax->tax_rate }}%{{ $tax->tax_rate === 8 ? ' ('.__('reduced').')' : '' }}</td><td class="num">¥{{ number_format($tax->taxable_amount) }}</td><td class="num">¥{{ number_format($tax->tax_amount) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

@if ($receipt->lines->isNotEmpty())
    <h2>{{ __('Line items (:count)', ['count' => $receipt->lines->count()]) }}</h2>
    <div class="card table-wrap">
        <table>
            <thead><tr><th>#</th><th>{{ __('Item') }}</th><th class="num">{{ __('Qty') }}</th><th class="num">{{ __('Unit price') }}</th><th class="num">{{ __('Amount') }}</th><th>{{ __('Rate') }}</th><th class="num">{{ __('Discount') }}</th></tr></thead>
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
