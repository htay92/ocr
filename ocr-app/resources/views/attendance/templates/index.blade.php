@extends('layouts.app', ['title' => 'Templates'])

@section('content')
    <p class="small"><a href="{{ route('attendance.index') }}">← {{ __('Attendance') }}</a></p>
    <div class="row title-row">
        <h1>{{ __('Templates') }}</h1>
        <a class="button" href="{{ route('templates.create') }}" style="flex:0 0 auto">{{ __('New template') }}</a>
    </div>
    <p class="muted small">{{ __('A template tells how to read one company\'s attendance file. When the format changes, create a template from a sample file first; imports then pick it automatically.') }}</p>

    <ul class="doc-list">
        @foreach ($templates as ['profile' => $profile, 'template' => $template])
            <li class="card template-item">
                <div class="row" style="align-items:center">
                    <strong style="flex:1 1 auto">{{ $profile->name }}</strong>
                    <span class="type">{{ $profile->kind === 'pdf' ? 'PDF' : 'CSV / Excel' }}</span>
                </div>
                <p class="small muted">{{ implode(' · ', array_column($template->columns, 'title')) }}</p>
                <p class="small muted">{{ trans_choice('{0} Not used yet|{1} Used for 1 sheet|[2,*] Used for :count sheets', $profile->attendance_sheets_count) }}</p>
                <div class="row" style="align-items:center">
                    <a class="button secondary small" href="{{ route('templates.create', ['template' => $profile->id]) }}" style="flex:0 0 auto">{{ __('Edit with a sample file') }}</a>
                    @include('attendance.templates._delete', ['profile' => $profile])
                </div>
            </li>
        @endforeach
    </ul>
@endsection
