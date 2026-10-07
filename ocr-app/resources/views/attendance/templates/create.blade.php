@extends('layouts.app', ['title' => 'New template'])

@section('content')
    <p class="small"><a href="{{ route('templates.index') }}">← {{ __('Templates') }}</a></p>
    <h1>{{ $template ? __('Edit :name', ['name' => $template->name]) : __('New template') }}</h1>
    <p class="muted">{{ __('Choose a sample file of the format (one month is enough). The next page reads it and suggests every setting.') }}</p>

    @if ($errors->any()) <div class="flash error" role="alert">{{ $errors->first() }}</div> @endif

    <form method="POST" action="{{ route('templates.sample') }}" enctype="multipart/form-data" class="card stack review">
        @csrf
        @if ($template) <input type="hidden" name="template" value="{{ $template->id }}"> @endif
        <label class="field"><span class="label">{{ __('Sample file') }}</span>
            <input type="file" name="file" accept=".pdf,.csv,.xlsx,.xls" required>
            <span class="muted small">PDF · CSV · Excel · {{ __('up to :max MB', ['max' => $maxMb]) }}</span>
        </label>
        <button class="button" type="submit">{{ __('Read the sample') }}</button>
    </form>
@endsection
