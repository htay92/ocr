@extends('layouts.app', ['title' => 'Attendance'])

@section('content')
    <div class="row title-row">
        <h1>{{ __('Attendance') }}</h1>
        <a class="button" href="{{ route('attendance.create') }}" style="flex:0 0 auto">{{ __('Import') }}</a>
    </div>
    <p class="small"><a href="{{ route('profiles.index') }}">{{ __('Import profiles (column mapping)') }}</a></p>

    @if ($sheets->isEmpty())
        <div class="card empty">
            <p>{{ __('No attendance imported yet.') }}</p>
            <a class="button" href="{{ route('attendance.create') }}">{{ __('Import a PDF, CSV or Excel file') }}</a>
        </div>
    @else
        <ul class="doc-list">
            @foreach ($sheets as $sheet)
                <li class="card" style="padding:0">
                    <a class="doc" href="{{ route('documents.show', $sheet->document_id) }}">
                        <span class="name">{{ $sheet->employee_name }}</span>
                        <span class="muted small">{{ $sheet->employee_code }}</span>
                        <span class="summary">
                            {{ $sheet->period_start->format('Y-m-d') }} 〜 {{ $sheet->period_end->format('Y-m-d') }}
                            <strong>{{ __('Worked') }} {{ \App\Attendance\Support\TimeValue::format($sheet->worked_minutes) }}</strong>
                            @if ($sheet->overtime_minutes) <span>{{ __('Overtime') }} {{ \App\Attendance\Support\TimeValue::format($sheet->overtime_minutes) }}</span> @endif
                        </span>
                        <span class="meta">{{ $sheet->document->original_name }} · {{ $sheet->created_at->format('Y-m-d H:i') }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
        <div style="margin-top:16px">{{ $sheets->links('pagination::simple-default') }}</div>
    @endif
@endsection
