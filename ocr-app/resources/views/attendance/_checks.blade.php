{{-- A-01..A-08 results, critical first; $results: list of [rule_code, level, message]. --}}
@php
    $order = ['critical' => 0, 'warning' => 1, 'pass' => 2, 'skipped' => 3];
    $results = collect($results)->sortBy(fn ($r) => [$order[$r['level']] ?? 4, $r['rule_code']]);
    $titles = [
        'A-01' => __('Clock order'), 'A-02' => __('Worked time'), 'A-03' => __('Dates in period'), 'A-04' => __('No duplicates'),
        'A-05' => __('Totals'), 'A-06' => __('Every day once'), 'A-07' => __('Day counts'), 'A-08' => __('Scheduled days'),
    ];
@endphp
<ul class="checks card">
    @foreach ($results as $result)
        <li>
            <span class="badge {{ $result['level'] === 'skipped' ? '' : $result['level'] }}">{{ $result['level'] === 'skipped' ? '—' : \App\Support\Labels::level($result['level']) }}</span>
            <span class="code">{{ $result['rule_code'] }}</span>
            <span class="text"><strong>{{ $titles[$result['rule_code']] ?? '' }}</strong><br><span class="muted small">{{ $result['message'] }}</span></span>
        </li>
    @endforeach
</ul>
