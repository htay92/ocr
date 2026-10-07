<div class="row-item tax-row">
    <label><span class="label">{{ __('Rate') }}</span>
        <select name="taxes[{{ $i }}][tax_rate]">
            @foreach ([10, 8] as $rate)
                <option value="{{ $rate }}" @selected((int) ($tax['tax_rate'] ?? 10) === $rate)>{{ $rate }}%</option>
            @endforeach
        </select>
    </label>
    <label><span class="label">{{ __('Taxable') }}</span><span class="yen"><input type="text" inputmode="numeric" name="taxes[{{ $i }}][taxable_amount]" value="{{ $tax['taxable_amount'] ?? '' }}"></span></label>
    <label><span class="label">{{ __('Tax') }}</span><span class="yen"><input type="text" inputmode="numeric" name="taxes[{{ $i }}][tax_amount]" value="{{ $tax['tax_amount'] ?? '' }}"></span></label>
    <button type="button" class="remove" data-remove aria-label="{{ __('Remove') }}">×</button>
    @foreach (['tax_rate', 'taxable_amount', 'tax_amount'] as $column)
        @error("taxes.{$i}.{$column}") <span class="error-text full">{{ $message }}</span> @enderror
    @endforeach
</div>
