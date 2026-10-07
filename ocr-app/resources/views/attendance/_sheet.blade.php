{{--
    One attendance sheet (SheetView array) with the format's own columns: the date
    (with weekday and holiday, computed from the calendar) and then each column
    under its title from the file. Summary: the file's own items and the standard
    totals the format has columns for.
--}}
@php
    use App\Attendance\Support\TimeValue;
    $s = $sheet['summary'];
    $computed = $sheet['computed'];
    $tag = fn (string $key) => in_array($key, $computed, true) ? '<span class="tag" title="'.e(__('Not in the file: computed from the daily rows')).'">'.e(__('computed')).'</span>' : '';
    $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
    $limit = $limit ?? null;
    $legacy = [
        'start_time' => 'start_time', 'end_time' => 'end_time', 'break_minutes' => 'break_minutes', 'worked_minutes' => 'worked_minutes',
        'overtime_minutes' => 'overtime_minutes', 'late_night_minutes' => 'late_night_minutes', 'legal_holiday_minutes' => 'legal_holiday_minutes',
        'legal_holiday_late_night_minutes' => 'legal_holiday_late_night_minutes', 'work_type' => 'work_type', 'memo' => 'memo', 'confirmed' => 'confirmed',
    ];
    // A cell: the stored value by column key; sheets imported before templates only have the standard fields.
    $cell = function (array $r, array $c) use ($legacy) {
        $value = array_key_exists('values', $r) && $r['values'] !== null ? ($r['values'][$c['key']] ?? null) : ($r[$legacy[$c['role'] ?? ''] ?? ''] ?? null);

        return match ($c['type']) {
            'duration' => $value === null || $value === 0 ? '' : TimeValue::format((int) $value),
            'mark' => $value ? '✓' : '',
            'number' => $value === null ? '' : rtrim(rtrim(number_format((float) $value, 2), '0'), '.'),
            default => (string) ($value ?? ''),
        };
    };
    $standard = ['scheduled' => '所定時間', 'worked' => '労働時間', 'overtime' => '時間外', 'late_night' => '深夜', 'legal_holiday' => '法定休日', 'legal_holiday_late_night' => '法定休日深夜'];
    $fileItems = collect($sheet['summary_values'] ?? [])->filter(fn ($i) => ($i['role'] ?? null) === null || str_starts_with($i['role'] ?? '', 'count:') === false && ! in_array($i['role'], array_map(fn ($k) => "{$k}_minutes", array_keys($standard)), true) && ! in_array($i['role'], ['scheduled_days', 'worked_days', 'paid_leave_taken_days', 'paid_leave_remaining_days'], true));
@endphp
<div class="card sheet">
    <div class="sheet-head">
        <strong>{{ $sheet['employee_name'] ?: __('(name not set)') }}</strong>
        @if ($sheet['employee_code']) <span class="muted small">{{ $sheet['employee_code'] }}</span> @endif
        <span class="muted">{{ $sheet['period_start'] }} 〜 {{ $sheet['period_end'] }}</span>
    </div>

    <dl class="sheet-summary">
        @foreach ($standard as $kind => $label)
            @if (($s["{$kind}_minutes"] ?? null) !== null)
                <div><dt>{{ $label }} {!! $tag("{$kind}_minutes") !!}</dt><dd>{{ TimeValue::format((int) $s["{$kind}_minutes"]) }}</dd></div>
            @endif
        @endforeach
        @foreach (['scheduled_days' => '所定出勤日', 'worked_days' => '出勤日数', 'paid_leave_taken_days' => '有休取得日数', 'paid_leave_remaining_days' => '有休残日数'] as $key => $label)
            @if (($s[$key] ?? null) !== null)
                <div><dt>{{ $label }} {!! $tag($key) !!}</dt><dd>{{ $key === 'scheduled_days' ? $s[$key] : number_format((float) $s[$key], 1) }}</dd></div>
            @endif
        @endforeach
        @foreach ($fileItems as $item)
            <div><dt>{{ $item['title'] }}</dt><dd>{{ ($item['type'] ?? '') === 'duration' && $item['value'] !== null ? TimeValue::format((int) $item['value']) : $item['value'] }}</dd></div>
        @endforeach
    </dl>
    @php $counts = array_filter($s['work_type_counts'] ?? []); @endphp
    @if ($counts)
        <p class="small muted">{{ __('Work types') }} {!! $tag('work_type_counts') !!}:
            @foreach ($counts as $type => $count) <span class="type">{{ $type }} {{ $count }}</span> @endforeach
        </p>
    @endif

    @foreach ($sheet['warnings'] as $warning)
        <div class="flash error small">{{ $warning }}</div>
    @endforeach

    <div class="table-wrap">
        <table class="att-table">
            <thead><tr>
                <th>{{ __('Date') }}</th>
                @foreach ($sheet['columns'] as $c)
                    <th @class(['num' => $c['type'] === 'duration'])>{{ $c['title'] }}</th>
                @endforeach
            </tr></thead>
            <tbody>
            @foreach ($sheet['records'] as $i => $r)
                @php $date = strtotime($r['work_date']); @endphp
                <tr class="{{ $r['day_type'] }}" @if ($limit && $i >= $limit) data-more hidden @endif>
                    <td>{{ date('n/j', $date) }}({{ $weekdays[(int) date('w', $date)] }})
                        @if ($r['holiday_name']) <span class="holiday-name">{{ $r['holiday_name'] }}</span> @endif</td>
                    @foreach ($sheet['columns'] as $c)
                        <td @class(['num' => $c['type'] === 'duration', 'wrap' => $c['type'] === 'text'])>{{ $cell($r, $c) }}</td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @if ($limit && count($sheet['records']) > $limit)
        <button type="button" class="button secondary small" onclick="this.closest('.sheet').querySelectorAll('[data-more]').forEach(r => r.hidden = false); this.remove()">
            {{ __('Show all :count days', ['count' => count($sheet['records'])]) }}
        </button>
    @endif
</div>
