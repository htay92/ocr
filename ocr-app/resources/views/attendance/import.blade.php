@extends('layouts.app', ['title' => 'Import attendance'])

@section('content')
    <p class="small"><a href="{{ route('attendance.index') }}">← {{ __('Attendance') }}</a></p>
    <h1>{{ __('Import attendance') }}</h1>

    @if ($errors->any())
        <div class="flash error" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('attendance.upload') }}" enctype="multipart/form-data" class="card stack review">
        @csrf
        <label class="field"><span class="label">{{ __('File') }}</span>
            <input type="file" name="file" accept=".pdf,.csv,.xlsx,.xls" required>
            <span class="muted small">{{ __('PDF, CSV or Excel · up to :max MB. The matching template is chosen automatically.', ['max' => $maxMb]) }}</span>
        </label>
        <button class="button" type="submit">{{ __('Preview') }}</button>
    </form>
    <p class="small"><a href="{{ route('templates.index') }}">{{ __('Templates') }}</a> · {{ __('A new format? Create its template first.') }}</p>
@endsection
