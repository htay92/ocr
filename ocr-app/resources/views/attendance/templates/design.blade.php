@extends('layouts.app', ['title' => 'Template'])

@php
    use App\Attendance\Support\Grid;
    $isPdf = $analysis['kind'] === 'pdf';
    $roles = [
        '' => __('— (just show it)'), 'work_date' => '日付', 'work_day' => '日（1〜31）', 'start_time' => '開始', 'end_time' => '終了',
        'break_minutes' => '休憩時間', 'worked_minutes' => '労働時間', 'overtime_minutes' => '時間外', 'late_night_minutes' => '深夜',
        'legal_holiday_minutes' => '法定休日', 'legal_holiday_late_night_minutes' => '法定休日深夜', 'work_type' => '勤務区分',
        'memo' => 'メモ', 'confirmed' => '確定', 'employee_name' => '氏名', 'employee_code' => '社員番号',
    ];
    $types = ['text' => __('Text'), 'date' => __('Date'), 'day' => __('Day of month'), 'time' => __('Clock time'),
        'duration' => __('Hours'), 'number' => __('Number'), 'work_type' => __('Work type'), 'mark' => __('Mark (✓)')];
    $units = ['auto' => __('9:30 / Excel time'), 'hours' => __('decimal hours (9.5)'), 'minutes' => __('minutes')];
    $summaryRoles = [
        '' => '—', 'scheduled_minutes' => '所定時間', 'worked_minutes' => '労働時間', 'overtime_minutes' => '時間外', 'late_night_minutes' => '深夜',
        'legal_holiday_minutes' => '法定休日', 'scheduled_days' => '所定出勤日', 'worked_days' => '出勤日数', 'paid_leave_taken_days' => '有休取得日数',
        'paid_leave_remaining_days' => '有休残日数',
    ];
    foreach (\App\Attendance\Data\ParsedSheet::WORK_TYPES as $wt) { $summaryRoles["count:{$wt}"] = "{$wt}（回数）"; }
    $workTypeChoices = ['' => __('— none (day off / blank)')] + array_combine(\App\Attendance\Data\ParsedSheet::WORK_TYPES, \App\Attendance\Data\ParsedSheet::WORK_TYPES);
    $cellOptions = collect($analysis['pairs'])->map(fn ($p) => $p['cell'].'  '.$p['label'].' = '.$p['value'])->all();
@endphp

@section('content')
    <p class="small"><a href="{{ route('templates.index') }}">← {{ __('Templates') }}</a></p>
    <h1>{{ $existing ? __('Edit :name', ['name' => $existing->name]) : __('New template') }}</h1>
    <p class="muted">{{ __('Sample') }}: {{ $fileName }} · {{ $isPdf ? 'PDF' : 'CSV / Excel' }}</p>

    @if ($errors->any()) <div class="flash error" role="alert">{{ $errors->first() }}</div> @endif

    <div class="detail">
        {{-- The sample as it is: cells with addresses, or the PDF's lines. --}}
        <div class="viewer-column">
            <div class="card sample">
                <strong>{{ __('Sample file') }}</strong>
                @if ($isPdf)
                    <ol class="pdf-lines">
                        @foreach (array_slice($analysis['lines'], 0, 60) as $line) <li>{{ $line }}</li> @endforeach
                    </ol>
                @else
                    @php $grid = $analysis['grid']; $letters = array_slice($grid->letters(), 0, 16); @endphp
                    <div class="table-wrap">
                        <table class="grid">
                            <thead><tr><th></th>@foreach ($letters as $l)<th>{{ $l }}</th>@endforeach</tr></thead>
                            <tbody>
                            @foreach (array_slice($grid->rows, 0, $settings['header_row'] + 12, true) as $n => $cells)
                                <tr @class(['header' => $n === $settings['header_row']])>
                                    <th>{{ $n }}</th>
                                    @foreach ($letters as $l)
                                        @php $v = $cells[$l] ?? null; $f = $grid->format($l.$n); @endphp
                                        <td>{{ (is_float($v) || is_int($v)) && preg_match('/h/i', $f) ? \App\Attendance\Support\TimeValue::format((int) round($v * 1440)) : \Illuminate\Support\Str::limit(Grid::text($v), 14) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <form method="POST" action="{{ route('templates.save', $key) }}" class="review stack">
            @csrf
            @if ($existing) <input type="hidden" name="template_id" value="{{ $existing->id }}"> @endif

            <div class="card form-grid">
                <label class="field wide @error('name') invalid @enderror"><span class="label">{{ __('Template name') }} <span class="req">*</span></span>
                    <input type="text" name="name" value="{{ old('name', $name) }}" placeholder="{{ __('e.g. A社 出勤簿') }}">
                </label>
                @if ($isPdf)
                    <label class="field wide"><span class="label">{{ __('Text before the employee name in the title') }}</span>
                        <input type="text" name="title_marker" value="{{ $settings['title_marker'] ?? '' }}">
                    </label>
                @else
                    <label class="field"><span class="label">{{ __('Header row') }}</span>
                        <input type="text" inputmode="numeric" name="header_row" value="{{ $settings['header_row'] }}">
                        <a class="small" href="{{ route('templates.design', ['key' => $key, 'header_row' => $settings['header_row'], 'template' => $existing?->id]) }}"
                           onclick="this.href = this.href.replace(/header_row=\d+/, 'header_row=' + this.closest('label').querySelector('input').value)">{{ __('Read again with this row') }}</a>
                    </label>
                    <label class="field"><span class="label">{{ __('Year cell') }}</span>
                        <input type="text" name="year_cell" value="{{ $settings['year_cell'] }}" list="cells" placeholder="B3">
                    </label>
                    <label class="field"><span class="label">{{ __('Month cell') }}</span>
                        <input type="text" name="month_cell" value="{{ $settings['month_cell'] }}" list="cells" placeholder="B4">
                    </label>
                    <label class="field"><span class="label">{{ __('Employee name') }}</span>
                        <select name="name_source">
                            @foreach (['cell' => __('from a cell'), 'column' => __('from the 氏名 column'), 'file' => __('the file name'), 'ask' => __('enter on import')] as $v => $t)
                                <option value="{{ $v }}" @selected(($settings['name_source'] ?? 'ask') === $v)>{{ $t }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field"><span class="label">{{ __('Name cell') }}</span>
                        <input type="text" name="name_cell" value="{{ $settings['name_cell'] }}" list="cells" placeholder="A4">
                    </label>
                    <label class="field"><span class="label">{{ __('Break when there is no break column') }}</span>
                        <input type="text" name="break_default" value="{{ $settings['break_default'] ?? '' }}" placeholder="1:00">
                    </label>
                    <datalist id="cells">@foreach ($cellOptions as $option) <option value="{{ strtok($option, ' ') }}">{{ $option }}</option> @endforeach</datalist>
                @endif
                <label class="field"><span class="label">{{ __('Totals row label') }}</span>
                    <input type="text" name="totals_label" value="{{ $settings['totals_label'] }}" placeholder="合計">
                </label>
            </div>

            <h2>{{ __('Columns') }}</h2>
            <p class="muted small">{{ __('Each column of this format, with its own title. Untick columns you do not need. 「意味」 links a column to a standard item so it is checked and totalled; other columns are kept and shown as they are.') }}</p>
            @error('columns') <div class="flash error">{{ $message }}</div> @enderror
            <div class="card column-list">
                @foreach ($settings['columns'] as $i => $c)
                    <div class="row-item column-row">
                        <input type="hidden" name="columns[{{ $i }}][key]" value="{{ $c['key'] }}">
                        <input type="hidden" name="columns[{{ $i }}][letter]" value="{{ $c['letter'] }}">
                        <input type="hidden" name="columns[{{ $i }}][title]" value="{{ $c['title'] }}">
                        <label class="check"><input type="checkbox" name="columns[{{ $i }}][include]" value="1" @checked($c['include'] ?? true)>
                            <strong>{{ $c['letter'] ? $c['letter'].': ' : '' }}{{ $c['title'] }}</strong>
                            @if (! empty($c['samples'])) <span class="muted small">{{ implode(' · ', $c['samples']) }}</span> @endif
                        </label>
                        <label><span class="label">{{ __('Meaning') }}</span>
                            <select name="columns[{{ $i }}][role]">
                                @foreach ($roles as $v => $t) <option value="{{ $v }}" @selected(($c['role'] ?? '') === $v)>{{ $t }}</option> @endforeach
                            </select>
                        </label>
                        <label><span class="label">{{ __('Type') }}</span>
                            <select name="columns[{{ $i }}][type]">
                                @foreach ($types as $v => $t) <option value="{{ $v }}" @selected($c['type'] === $v)>{{ $t }}</option> @endforeach
                            </select>
                        </label>
                        <label><span class="label">{{ __('Unit (hours)') }}</span>
                            <select name="columns[{{ $i }}][unit]">
                                @foreach ($units as $v => $t) <option value="{{ $v }}" @selected(($c['unit'] ?? 'auto') === $v)>{{ $t }}</option> @endforeach
                            </select>
                        </label>
                    </div>
                @endforeach
            </div>

            @if (! empty($settings['work_types']))
                <h2>{{ __('Work-type words in this file') }}</h2>
                <div class="card form-grid">
                    @foreach ($settings['work_types'] as $word => $type)
                        <label class="field"><span class="label">「{{ $word }}」 →</span>
                            <select name="work_types[{{ $word }}]">
                                @foreach ($workTypeChoices as $v => $t) <option value="{{ $v }}" @selected((string) $type === (string) $v)>{{ $t }}</option> @endforeach
                            </select>
                        </label>
                    @endforeach
                </div>
            @endif

            @if (! empty($settings['summary']))
                <h2>{{ __('Summary in the file') }}</h2>
                <p class="muted small">{{ __('Totals printed in the file. Linked ones are compared with the daily rows (A-05, A-07).') }}</p>
                <div class="card column-list">
                    @foreach ($settings['summary'] as $i => $item)
                        <div class="row-item column-row">
                            <input type="hidden" name="summary[{{ $i }}][title]" value="{{ $item['title'] }}">
                            <input type="hidden" name="summary[{{ $i }}][cell]" value="{{ $item['cell'] ?? '' }}">
                            <label class="check"><input type="checkbox" name="summary[{{ $i }}][include]" value="1" @checked($item['include'] ?? true)>
                                <strong>{{ isset($item['cell']) ? $item['cell'].': ' : '' }}{{ $item['title'] }}</strong>
                                <span class="muted small">{{ $item['value'] ?? '' }}</span>
                            </label>
                            <label><span class="label">{{ __('Meaning') }}</span>
                                <select name="summary[{{ $i }}][role]">
                                    @foreach ($summaryRoles as $v => $t) <option value="{{ $v }}" @selected(($item['role'] ?? '') === $v)>{{ $t }}</option> @endforeach
                                </select>
                            </label>
                            <label><span class="label">{{ __('Type') }}</span>
                                <select name="summary[{{ $i }}][type]">
                                    @foreach (['duration' => __('Hours'), 'days' => __('Days'), 'number' => __('Number')] as $v => $t)
                                        <option value="{{ $v }}" @selected(($item['type'] ?? 'number') === $v)>{{ $t }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label><span class="label">{{ __('Unit (hours)') }}</span>
                                <select name="summary[{{ $i }}][unit]">
                                    @foreach ($units as $v => $t) <option value="{{ $v }}" @selected(($item['unit'] ?? 'auto') === $v)>{{ $t }}</option> @endforeach
                                </select>
                            </label>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="card actions">
                <label class="field"><span class="label">{{ __('Name for the test (if the file has none)') }}</span>
                    <input type="text" name="test_name" value="{{ request('test_name') }}" placeholder="山田 花子">
                </label>
                <div class="row">
                    <button class="button secondary" type="submit" name="action" value="test">{{ __('Test with the sample') }}</button>
                    <button class="button" type="submit" name="action" value="save">{{ __('Save template') }}</button>
                </div>
            </div>
        </form>
        @if ($existing)
            <div class="danger-zone">@include('attendance.templates._delete', ['profile' => $existing])</div>
        @endif
    </div>

    @if ($error)
        <div class="flash error" role="alert" id="test-result">{{ $error }}</div>
    @elseif ($preview)
        <h2 id="test-result">{{ __('Test result') }}</h2>
        @foreach ($preview->sheets as $item)
            @include('attendance._checks', ['results' => array_map(fn ($r) => ['rule_code' => $r->code, 'level' => $r->level, 'message' => $r->message], $item['results'])])
            @include('attendance._sheet', ['sheet' => \App\Attendance\SheetView::fromParsed($item['sheet']), 'limit' => 10])
        @endforeach
    @endif
@endsection
