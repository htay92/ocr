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
            <span class="muted small">{{ __('勤務データ PDF, or a CSV / Excel export · up to :max MB', ['max' => $maxMb]) }}</span>
        </label>
        <label class="field"><span class="label">{{ __('Import profile (CSV / Excel only)') }}</span>
            <select name="profile_id">
                @foreach ($profiles as $profile)
                    <option value="{{ $profile->id }}">{{ $profile->name }}</option>
                @endforeach
            </select>
        </label>
        <button class="button" type="submit">{{ __('Preview') }}</button>
    </form>
    <p class="small"><a href="{{ route('profiles.index') }}">{{ __('Import profiles (column mapping)') }}</a></p>
@endsection
