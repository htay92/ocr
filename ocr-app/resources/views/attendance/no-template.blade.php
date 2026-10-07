@extends('layouts.app', ['title' => 'Import attendance'])

@section('content')
    <p class="small"><a href="{{ route('attendance.index') }}">← {{ __('Attendance') }}</a></p>
    <h1>{{ __('Import attendance') }}</h1>
    <p class="muted">{{ $fileName }}</p>

    <div class="card stack">
        <p><strong>{{ __('No template fits this file.') }}</strong></p>
        @if ($error) <div class="flash error">{{ $error }}</div> @endif
        <p class="muted small">{{ __('A new company or a changed format needs a template first. It is made from this file in a few steps.') }}</p>
        <a class="button" href="{{ route('templates.design', $key) }}">{{ __('Create a template from this file') }}</a>
    </div>

    @if ($templates->isNotEmpty())
        <form method="GET" action="{{ route('attendance.preview', $key) }}" class="row card" style="margin-top:12px">
            <div>
                <label for="profile">{{ __('Or read it with a template') }}</label>
                <select id="profile" name="profile">
                    @foreach ($templates as $template)
                        <option value="{{ $template->id }}" @selected($template->id === $selected)>{{ $template->name }}</option>
                    @endforeach
                </select>
            </div>
            <button class="button secondary" type="submit">{{ __('Read again') }}</button>
        </form>
    @endif

    <form method="POST" action="{{ route('attendance.cancel', $key) }}" style="margin-top:12px">
        @csrf
        <button class="linkbutton" type="submit">{{ __('Cancel') }}</button>
    </form>
@endsection
