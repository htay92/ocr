@extends('layouts.app', ['title' => 'Documents', 'refresh' => $busy])

@section('content')
    <div class="row" style="align-items:center;margin-bottom:12px">
        <h1 style="margin:0">Documents</h1>
        <a class="button" href="{{ route('documents.create') }}" style="flex:0 0 auto">Upload</a>
    </div>

    <form method="GET" class="row card" style="margin-bottom:16px">
        <div>
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ str_replace('_', ' ', $status) }}</option>
                @endforeach
            </select>
        </div>
        <button class="button secondary" type="submit">Filter</button>
    </form>

    @if ($busy)
        <p class="muted small">Processing… this page refreshes every 5 seconds.</p>
    @endif

    @if ($documents->isEmpty())
        <div class="card empty">
            <p>No documents yet.</p>
            <a class="button" href="{{ route('documents.create') }}">Upload receipts or invoices</a>
        </div>
    @else
        <ul class="doc-list">
            @foreach ($documents as $document)
                <li class="card" style="padding:0">
                    <a class="doc" href="{{ route('documents.show', $document) }}">
                        <span class="name">{{ $document->original_name }}</span>
                        <x-status :status="$document->status" />
                        <span class="meta">
                            #{{ $document->id }} · {{ $document->created_at->format('Y-m-d H:i') }}
                            @if ($document->route) · {{ str_replace('_', ' ', $document->route) }} @endif
                            @if ($document->selectedRun) · {{ $document->selectedRun->engine }}, {{ count($document->selectedRun->lines ?? []) }} lines @endif
                            @if ($document->error_message) · <span style="color:var(--danger)">{{ \Illuminate\Support\Str::limit($document->error_message, 80) }}</span> @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
        <div style="margin-top:16px">{{ $documents->links('pagination::simple-default') }}</div>
    @endif
@endsection
