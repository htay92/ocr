{{-- Results of V-01..V-10 for the selected run (spec 3.5). Critical first. --}}
@php
    $order = ['critical' => 0, 'warning' => 1, 'pass' => 2];
    $results = $checks->sortBy(fn ($r) => [$order[$r->level] ?? 3, $r->rule_code]);
@endphp
@if ($results->isNotEmpty())
    <h2>Checks</h2>
    <ul class="checks card">
        @foreach ($results as $result)
            <li>
                <span class="badge {{ $result->level }}">{{ $result->level }}</span>
                <span class="code">{{ $result->rule_code }}</span>
                <span class="text">{{ $result->message }}</span>
            </li>
        @endforeach
    </ul>
@endif
