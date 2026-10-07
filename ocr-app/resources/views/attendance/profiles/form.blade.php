@extends('layouts.app', ['title' => 'Import profile'])

@section('content')
    @php
        $labels = [
            'employee_name' => '氏名', 'employee_code' => '社員番号', 'work_date' => '日付', 'work_type' => '勤務区分',
            'start_time' => '開始', 'end_time' => '終了', 'break_minutes' => '休憩時間', 'worked_minutes' => '労働時間',
            'overtime_minutes' => '時間外', 'late_night_minutes' => '深夜', 'legal_holiday_minutes' => '法定休日',
            'legal_holiday_late_night_minutes' => '法定休日深夜', 'memo' => 'メモ', 'confirmed' => '確定',
        ];
    @endphp
    <p class="small"><a href="{{ route('profiles.index') }}">← {{ __('Import profiles') }}</a></p>
    <h1>{{ $profile->exists ? $profile->name : __('New profile') }}</h1>

    <form method="POST" action="{{ $profile->exists ? route('profiles.update', $profile) : route('profiles.store') }}" class="review stack">
        @csrf
        @if ($profile->exists) @method('PUT') @endif
        <div class="card form-grid">
            <label class="field wide @error('name') invalid @enderror"><span class="label">{{ __('Name') }}</span>
                <input type="text" name="name" value="{{ old('name', $profile->name) }}" required>
                @error('name') <span class="error-text">{{ $message }}</span> @enderror
            </label>
            <label class="field"><span class="label">{{ __('Encoding') }}</span>
                <select name="encoding">
                    @foreach (['auto' => __('Detect'), 'UTF-8' => 'UTF-8', 'SJIS-win' => 'Shift-JIS'] as $value => $text)
                        <option value="{{ $value }}" @selected(old('encoding', $profile->encoding) === $value)>{{ $text }}</option>
                    @endforeach
                </select>
            </label>
            <label class="field"><span class="label">{{ __('Delimiter') }}</span>
                <select name="delimiter">
                    @foreach ([',' => ', (comma)', ';' => '; (semicolon)', 'tab' => 'Tab', '|' => '|'] as $value => $text)
                        <option value="{{ $value }}" @selected(old('delimiter', $profile->delimiter === "\t" ? 'tab' : $profile->delimiter) === $value)>{{ $text }}</option>
                    @endforeach
                </select>
            </label>
            <label class="field"><span class="label">{{ __('Header row') }}</span>
                <input type="text" inputmode="numeric" name="header_row" value="{{ old('header_row', $profile->header_row ?? 1) }}">
            </label>
        </div>

        <h2>{{ __('Columns') }}</h2>
        <p class="muted small">{{ __('Type the column title as it appears in the file, or its number (1 = first column). Leave empty if the file has no such column.') }}</p>
        <div class="card form-grid">
            @foreach ($labels as $field => $label)
                <label class="field @error("column_map.{$field}") invalid @enderror"><span class="label">{{ $label }} @if ($field === 'work_date') <span class="req">*</span> @endif</span>
                    <input type="text" name="column_map[{{ $field }}]" value="{{ old("column_map.{$field}", $profile->column_map[$field] ?? '') }}">
                    @error("column_map.{$field}") <span class="error-text">{{ $message }}</span> @enderror
                </label>
            @endforeach
        </div>
        <div class="card actions"><div class="row"><button class="button" type="submit">{{ __('Save') }}</button></div></div>
    </form>
@endsection
