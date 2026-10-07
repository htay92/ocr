{{-- One attendance sheet (SheetView array): summary and the month, day types computed from the calendar. --}}
@php
    use App\Attendance\Support\TimeValue;
    $s = $sheet['summary'];
    $computed = $sheet['computed'];
    $tag = fn (string $key) => in_array($key, $computed, true) ? '<span class="tag" title="'.e(__('Not in the file: computed from the daily rows')).'">'.e(__('computed')).'</span>' : '';
    $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
    $limit = $limit ?? null;
@endphp
<div class="card sheet">
    <div class="sheet-head">
        <strong>{{ $sheet['employee_name'] }}</strong>
        @if ($sheet['employee_code']) <span class="muted small">{{ $sheet['employee_code'] }}</span> @endif
        <span class="muted">{{ $sheet['period_start'] }} 〜 {{ $sheet['period_end'] }}</span>
    </div>

    <dl class="sheet-summary">
        @foreach (['scheduled' => '所定時間', 'worked' => '労働時間', 'overtime' => '時間外', 'late_night' => '深夜', 'legal_holiday' => '法定休日', 'legal_holiday_late_night' => '法定休日深夜'] as $kind => $label)
            <div><dt>{{ $label }} {!! $tag("{$kind}_minutes") !!}</dt><dd>{{ TimeValue::format((int) ($s["{$kind}_minutes"] ?? 0)) }}</dd></div>
        @endforeach
        <div><dt>所定出勤日 {!! $tag('scheduled_days') !!}</dt><dd>{{ $s['scheduled_days'] }}</dd></div>
        <div><dt>出勤日数 {!! $tag('worked_days') !!}</dt><dd>{{ number_format((float) $s['worked_days'], 1) }}</dd></div>
        <div><dt>有休取得日数 {!! $tag('paid_leave_taken_days') !!}</dt><dd>{{ number_format((float) $s['paid_leave_taken_days'], 1) }}</dd></div>
        <div><dt>有休残日数</dt><dd>{{ $s['paid_leave_remaining_days'] === null ? '—' : number_format((float) $s['paid_leave_remaining_days'], 1) }}</dd></div>
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
            <thead><tr><th>日付</th><th>勤務区分</th><th>開始</th><th>終了</th><th class="num">休憩</th><th class="num">労働</th><th class="num">時間外</th><th class="num">深夜</th><th class="num">法定休日</th><th>メモ</th><th>確定</th></tr></thead>
            <tbody>
            @foreach ($sheet['records'] as $i => $r)
                @php $date = strtotime($r['work_date']); @endphp
                <tr class="{{ $r['day_type'] }}" @if ($limit && $i >= $limit) data-more hidden @endif>
                    <td>{{ date('n/j', $date) }}({{ $weekdays[(int) date('w', $date)] }})
                        @if ($r['holiday_name']) <span class="holiday-name">{{ $r['holiday_name'] }}</span> @endif</td>
                    <td>{{ $r['work_type'] }}</td>
                    <td>{{ $r['start_time'] }}</td>
                    <td>{{ $r['end_time'] }}</td>
                    <td class="num">{{ $r['break_minutes'] ? TimeValue::format($r['break_minutes']) : '' }}</td>
                    <td class="num">{{ $r['worked_minutes'] ? TimeValue::format($r['worked_minutes']) : '' }}</td>
                    <td class="num">{{ $r['overtime_minutes'] ? TimeValue::format($r['overtime_minutes']) : '' }}</td>
                    <td class="num">{{ $r['late_night_minutes'] ? TimeValue::format($r['late_night_minutes']) : '' }}</td>
                    <td class="num">{{ $r['legal_holiday_minutes'] ? TimeValue::format($r['legal_holiday_minutes']) : '' }}</td>
                    <td class="wrap">{{ $r['memo'] }}</td>
                    <td>{{ $r['confirmed'] ? '✓' : '' }}</td>
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
