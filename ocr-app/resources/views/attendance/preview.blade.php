@extends('layouts.app', ['title' => 'Import preview'])

@section('content')
    <p class="small"><a href="{{ route('attendance.index') }}">← {{ __('Attendance') }}</a></p>
    <h1>{{ __('Import preview') }}</h1>
    <p class="muted">{{ $fileName }} · {{ \App\Support\Labels::route($preview->route) }} ·
        {{ trans_choice('{1} 1 employee|[2,*] :count employees', count($preview->sheets)) }}</p>

    @if ($errors->any())
        <div class="flash error" role="alert">{{ $errors->first() }}</div>
    @endif

    @if ($preview->route === 'spreadsheet')
        <form method="GET" class="row card" style="margin-bottom:12px">
            <div>
                <label for="profile">{{ __('Import profile') }}</label>
                <select id="profile" name="profile">
                    @foreach ($profiles as $profile)
                        <option value="{{ $profile->id }}" @selected($profile->id === $preview->profileId)>{{ $profile->name }}</option>
                    @endforeach
                </select>
            </div>
            <button class="button secondary" type="submit">{{ __('Read again') }}</button>
        </form>
        @if ($preview->unknownColumns)
            <p class="small muted">{{ __('Columns not used by this profile') }}: {{ implode('、', $preview->unknownColumns) }}</p>
        @endif
    @endif

    @foreach ($preview->sheets as $item)
        @if ($item['existing'])
            <div class="flash error">{{ __(':name :from was imported before (document #:id). Importing replaces it.', ['name' => $item['sheet']->employeeName, 'from' => $item['sheet']->periodStart, 'id' => $item['existing']->document_id]) }}</div>
        @endif
        <h2>{{ __('Checks') }} · {{ $item['sheet']->employeeName }}</h2>
        @include('attendance._checks', ['results' => array_map(fn ($r) => ['rule_code' => $r->code, 'level' => $r->level, 'message' => $r->message], $item['results'])])
        @include('attendance._sheet', ['sheet' => \App\Attendance\SheetView::fromParsed($item['sheet']), 'limit' => 20])
    @endforeach

    <form method="POST" action="{{ route('attendance.import', $key) }}" class="card actions">
        @csrf
        <input type="hidden" name="profile_id" value="{{ $preview->profileId }}">
        @if ($preview->hasExisting())
            <label class="ack"><input type="checkbox" name="replace" value="1"> {{ __('Replace the earlier import (its file stays in the document list)') }}</label>
        @endif
        @if ($preview->hasCritical())
            <label class="ack"><input type="checkbox" name="acknowledged" value="1"> {{ __('Some checks fail. Import anyway after checking them.') }}</label>
        @endif
        <div class="row">
            <button class="button secondary" type="submit" formaction="{{ route('attendance.cancel', $key) }}">{{ __('Cancel') }}</button>
            <button class="button" type="submit">{{ __('Import') }}</button>
        </div>
    </form>
@endsection
