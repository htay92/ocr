<div class="row-item line-row">
    <label class="name"><span class="label">{{ __('Item') }}</span><input type="text" name="lines[{{ $i }}][name]" value="{{ $line['name'] ?? '' }}"></label>
    <label><span class="label">{{ __('Qty') }}</span><input type="text" inputmode="decimal" name="lines[{{ $i }}][quantity]" value="{{ $line['quantity'] ?? '' }}"></label>
    <label><span class="label">{{ __('Unit price') }}</span><span class="yen"><input type="text" inputmode="numeric" name="lines[{{ $i }}][unit_price]" value="{{ $line['unit_price'] ?? '' }}"></span></label>
    <label><span class="label">{{ __('Amount') }}</span><span class="yen"><input type="text" inputmode="numeric" name="lines[{{ $i }}][amount]" value="{{ $line['amount'] ?? '' }}"></span></label>
    <label><span class="label">{{ __('Rate') }}</span>
        <select name="lines[{{ $i }}][tax_rate]">
            @foreach ([10, 8] as $rate)
                <option value="{{ $rate }}" @selected((int) ($line['tax_rate'] ?? 10) === $rate)>{{ $rate }}%</option>
            @endforeach
        </select>
    </label>
    <label><span class="label">{{ __('Discount') }}</span><span class="yen"><input type="text" inputmode="numeric" name="lines[{{ $i }}][discount]" value="{{ $line['discount'] ?? '' }}" placeholder="-50"></span></label>
    <button type="button" class="remove" data-remove aria-label="{{ __('Remove') }}">×</button>
    @foreach (['name', 'quantity', 'unit_price', 'amount', 'tax_rate', 'discount'] as $column)
        @error("lines.{$i}.{$column}") <span class="error-text full">{{ $message }}</span> @enderror
    @endforeach
</div>
