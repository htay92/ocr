@php use App\Support\Labels; @endphp
@extends('layouts.app', ['title' => 'Documents', 'refresh' => $busy])

@section('content')
    <div class="row" style="align-items:center;margin-bottom:12px">
        <h1 style="margin:0">{{ __('Documents') }}</h1>
        <a class="button" href="{{ route('documents.create') }}" style="flex:0 0 auto">{{ __('Upload') }}</a>
    </div>

    <form method="GET" class="row card" style="margin-bottom:16px">
        <div>
            <label for="status">{{ __('Status') }}</label>
            <select id="status" name="status">
                <option value="">{{ __('All') }}</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ Labels::status($status) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="type">{{ __('Type') }}</label>
            <select id="type" name="type">
                <option value="">{{ __('All') }}</option>
                @foreach (array_keys(Labels::TYPES) as $type)
                    <option value="{{ $type }}" @selected(($filters['type'] ?? null) === $type)>{{ Labels::type($type) }}</option>
                @endforeach
            </select>
        </div>
        <button class="button secondary" type="submit">{{ __('Filter') }}</button>
    </form>

    @if ($busy)
        <p class="muted small">{{ __('Processing… this page refreshes every 5 seconds.') }}</p>
    @endif

    @if ($documents->isEmpty())
        <div class="card empty">
            <p>{{ __('No documents yet.') }}</p>
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
