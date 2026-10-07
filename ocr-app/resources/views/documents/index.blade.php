@php use App\Support\Labels; @endphp
@extends('layouts.app', ['title' => 'Documents', 'refresh' => $busy])

@section('content')
    <div class="row" style="align-items:center;margin-bottom:12px">
        <h1 style="margin:0">{{ __('Documents') }}</h1>
        <a class="button" href="{{ route('documents.create') }}" style="flex:0 0 auto">{{ __('Upload') }}</a>
    </div>

    @php $active = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty(); @endphp
    <details class="card filters" @if ($active) open @endif>
        <summary>{{ __('Filter') }}@if ($active) · {{ __('on') }}@endif</summary>
        <form method="GET" class="filter-grid">
            <label class="field"><span class="label">{{ __('Status') }}</span>
                <select name="status">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ Labels::status($status) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="field"><span class="label">{{ __('Type') }}</span>
                <select name="type">
                    <option value="">{{ __('All') }}</option>
                    @foreach (array_keys(Labels::TYPES) as $type)
                        <option value="{{ $type }}" @selected(($filters['type'] ?? null) === $type)>{{ Labels::type($type) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="field"><span class="label">{{ __('From') }}</span><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></label>
            <label class="field"><span class="label">{{ __('To') }}</span><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></label>
            <label class="field wide"><span class="label">{{ __('Vendor contains') }}</span><input type="text" name="vendor" value="{{ $filters['vendor'] ?? '' }}"></label>
            <label class="field"><span class="label">{{ __('Total from') }}</span><input type="text" inputmode="numeric" name="min_total" value="{{ $filters['min_total'] ?? '' }}"></label>
            <label class="field"><span class="label">{{ __('Total to') }}</span><input type="text" inputmode="numeric" name="max_total" value="{{ $filters['max_total'] ?? '' }}"></label>
            @foreach (['date_from', 'date_to', 'min_total', 'max_total'] as $key)
                @error($key) <span class="error-text wide">{{ $message }}</span> @enderror
            @endforeach
            <div class="row wide">
                <button class="button" type="submit">{{ __('Filter') }}</button>
                <a class="button secondary" href="{{ route('documents.index') }}">{{ __('Clear') }}</a>
            </div>
        </form>
    </details>

    <p class="list-summary">
        <strong>{{ trans_choice('{0} No documents|{1} 1 document|[2,*] :count documents', $documents->total()) }}</strong>
        @if ($sum) · {{ __('Total of these documents') }} <strong>¥{{ number_format($sum) }}</strong> @endif
    </p>

    @if ($busy)
        <p class="muted small">{{ __('Processing… this page refreshes every 5 seconds.') }}</p>
    @endif

    @if ($documents->isEmpty())
        <div class="card empty">
            <p>{{ $active ? __('No documents match these filters.') : __('No documents yet.') }}</p>
            <a class="button" href="{{ route('documents.create') }}">{{ __('Upload receipts or invoices') }}</a>
        </div>
    @else
        <ul class="doc-list">
            @foreach ($documents as $document)
                <li class="card" style="padding:0">
                    <a class="doc" href="{{ route('documents.show', $document) }}">
                        <span class="name">{{ $document->receipt?->vendor_name ?? $document->original_name }}</span>
                        <x-status :status="$document->status" />
                        @if ($document->type || $document->receipt)
                            <span class="summary">
                                @if ($document->type) <span class="type">{{ Labels::type($document->type) }}</span> @endif
                                @if ($document->receipt?->issue_date) {{ $document->receipt->issue_date->format('Y-m-d') }} @endif
                                @if ($document->receipt?->total !== null) <strong>¥{{ number_format($document->receipt->total) }}</strong> @endif
                            </span>
                        @endif
                        <span class="meta">
                            #{{ $document->id }} · {{ $document->original_name }} · {{ $document->created_at->format('Y-m-d H:i') }}
                            @if ($document->route) · {{ Labels::route($document->route) }} @endif
                            @if ($document->selectedRun) · {{ $document->selectedRun->engine }}, {{ __(':count lines', ['count' => count($document->selectedRun->lines ?? [])]) }} @endif
                            @if ($document->error_message) · <span style="color:var(--danger)">{{ \Illuminate\Support\Str::limit($document->error_message, 80) }}</span> @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
        <div style="margin-top:16px">{{ $documents->links('pagination::simple-default') }}</div>
    @endif
@endsection
