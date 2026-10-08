{{-- SC-04 correction form: header fields, tax rows and line items. Saving runs V-01..V-11 again. --}}
@php
    use App\Support\Labels;
    $receipt = $document->receipt;
    $money = ['subtotal', 'total', 'withholding_tax', 'cash_received', 'change'];
    $dates = ['issue_date', 'due_date'];
    $type = old('type', in_array($document->type, \App\Processing\DocumentClassifier::RECEIPT_TYPES, true) ? $document->type : 'receipt');
    $required = \App\Models\Receipt::REQUIRED[$type] ?? [];
    $corrected = $receipt?->corrected_fields ?? [];
    $value = function (string $field) use ($receipt) {
        $v = $receipt?->{$field};
        return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
    };
    $taxes = old('taxes', $receipt?->taxes->map->only(['tax_rate', 'taxable_amount', 'tax_amount'])->all() ?? []);
    $lines = old('lines', $receipt?->lines->map(fn ($l) => [
        'name' => $l->name, 'quantity' => rtrim(rtrim((string) $l->quantity, '0'), '.'), 'unit_price' => $l->unit_price,
        'amount' => $l->amount, 'tax_rate' => $l->tax_rate, 'discount' => $l->discount ?: null,
    ])->all() ?? []);
@endphp

<form method="POST" action="{{ route('documents.update', $document) }}" id="review-form" class="review" novalidate>
    @csrf
    @method('PUT')

    @if ($errors->any() && ! $errors->has('acknowledged'))
        <div class="flash error" role="alert">{{ __('Please check the highlighted items.') }}</div>
    @endif

    <h2>{{ __('Fields') }}</h2>
    <div class="card form-grid">
        <label class="field">
            <span class="label">{{ __('Type') }}</span>
            <select name="type">
                @foreach (\App\Processing\DocumentClassifier::RECEIPT_TYPES as $option)
                    <option value="{{ $option }}" @selected($type === $option)>{{ Labels::type($option) }}</option>
                @endforeach
            </select>
        </label>

        @foreach (array_keys(Labels::FIELDS) as $field)
            @php
                $current = old("fields.{$field}", $value($field));
                $confidence = $receipt?->field_confidence[$field] ?? null;
            @endphp
            <label @class(['field', 'wide' => in_array($field, ['vendor_name', 'vendor_address', 'recipient_name', 'purpose'], true), 'invalid' => $errors->has("fields.{$field}")]) data-field="{{ $field }}">
                <span class="label">
                    {{ Labels::field($field) }}
                    @if (in_array($field, $required, true)) <span class="req" title="{{ __('Required') }}">*</span> @endif
                    @if (in_array($field, $corrected, true)) <span class="tag">{{ __('Corrected') }}</span>
                    @elseif ($confidence !== null && $confidence < $lowConfidence) <span class="tag low">{{ __('Check') }} {{ number_format($confidence, 2) }}</span> @endif
                </span>
                @if ($field === 'tax_mode')
                    <select name="fields[tax_mode]">
                        <option value="">—</option>
                        @foreach (['inclusive', 'exclusive'] as $mode)
                            <option value="{{ $mode }}" @selected($current === $mode)>{{ Labels::taxMode($mode) }}</option>
                        @endforeach
                    </select>
                @elseif ($field === 'payment_method')
                    <select name="fields[payment_method]">
                        <option value="">—</option>
                        @foreach (array_keys(Labels::PAYMENTS) as $method)
                            <option value="{{ $method }}" @selected($current === $method)>{{ Labels::payment($method) }}</option>
                        @endforeach
                    </select>
                @elseif (in_array($field, $dates, true))
                    <input type="date" name="fields[{{ $field }}]" value="{{ $current }}">
                @elseif (in_array($field, $money, true))
                    <span class="yen"><input type="text" inputmode="numeric" name="fields[{{ $field }}]" value="{{ $current }}" autocomplete="off"></span>
                @else
                    <input type="text" name="fields[{{ $field }}]" value="{{ $current }}" autocomplete="off" @if ($field === 'registration_number') placeholder="T1234567890123" @endif>
                @endif
                @error("fields.{$field}") <span class="error-text">{{ $message }}</span> @enderror
            </label>
        @endforeach
    </div>

    <h2>{{ __('Tax by rate') }}</h2>
    <div class="card">
        <div class="rows" data-rows="taxes">
            @foreach ($taxes as $i => $tax)
                @include('documents._tax_row', ['i' => $i, 'tax' => $tax])
            @endforeach
        </div>
        <template data-template="taxes">@include('documents._tax_row', ['i' => '__INDEX__', 'tax' => []])</template>
        <button type="button" class="button secondary small" data-add="taxes">＋ {{ __('Add tax row') }}</button>
    </div>

    <h2>{{ __('Line items') }}</h2>
    <div class="card">
        <div class="rows lines-form" data-rows="lines">
            @foreach ($lines as $i => $line)
                @include('documents._line_row', ['i' => $i, 'line' => $line])
            @endforeach
        </div>
        <template data-template="lines">@include('documents._line_row', ['i' => '__INDEX__', 'line' => ['quantity' => 1, 'tax_rate' => 10]])</template>
        <button type="button" class="button secondary small" data-add="lines">＋ {{ __('Add line') }}</button>
    </div>

    <div class="card actions">
        @if ($hasCritical || $errors->has('acknowledged'))
            <label @class(['ack', 'invalid' => $errors->has('acknowledged')])>
                <input type="checkbox" name="acknowledged" value="1">
                {{ __('Some checks still fail. Confirm anyway after checking them.') }}
            </label>
            @error('acknowledged') <div class="error-text">{{ $message }}</div> @enderror
        @endif
        <div class="row">
            <button class="button secondary" type="submit">{{ __('Save') }}</button>
            <button class="button" type="submit" name="confirm" value="1">{{ __('Save and confirm') }}</button>
        </div>
    </div>
</form>

@push('scripts')
<script>
(() => {
    const form = document.getElementById('review-form');
    if (!form) return;
    let next = Date.now();
    form.addEventListener('click', event => {
        const add = event.target.closest('[data-add]');
        if (add) {
            const name = add.dataset.add;
            const html = form.querySelector(`[data-template="${name}"]`).innerHTML.replaceAll('__INDEX__', String(next++));
            const holder = form.querySelector(`[data-rows="${name}"]`);
            holder.insertAdjacentHTML('beforeend', html);
            holder.lastElementChild.querySelector('input, select')?.focus();
        }
        const remove = event.target.closest('[data-remove]');
        if (remove) remove.closest('.row-item').remove();
    });
})();
</script>
@endpush
