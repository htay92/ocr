@extends('layouts.app', ['title' => 'Import profiles'])

@section('content')
    <p class="small"><a href="{{ route('attendance.index') }}">← {{ __('Attendance') }}</a></p>
    <div class="row title-row">
        <h1>{{ __('Import profiles') }}</h1>
        <a class="button" href="{{ route('profiles.create') }}" style="flex:0 0 auto">{{ __('New profile') }}</a>
    </div>
    <p class="muted small">{{ __('A profile says which CSV / Excel column holds each item.') }}</p>
    <ul class="doc-list">
        @foreach ($profiles as $profile)
            <li class="card" style="padding:0">
                <a class="doc" href="{{ route('profiles.edit', $profile) }}">
                    <span class="name">{{ $profile->name }}</span>
                    <span class="meta">{{ $profile->encoding }} · {{ __('header row') }} {{ $profile->header_row }} · {{ count($profile->column_map) }} {{ __('columns') }}</span>
                </a>
            </li>
        @endforeach
    </ul>
@endsection
