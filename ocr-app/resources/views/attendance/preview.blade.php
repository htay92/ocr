@extends('layouts.app', ['title' => 'Import preview'])

@section('content')
    <p class="small"><a href="{{ route('attendance.index') }}">← {{ __('Attendance') }}</a></p>
    <h1>{{ __('Import preview') }}</h1>
    <p class="muted">{{ $fileName }} · {{ \App\Support\Labels::route($preview->route) }} ·
        {{ trans_choice('{1} 1 employee|[2,*] :count employees', count($preview->sheets)) }}</p>

    @if ($errors->any())
        <div class="flash error" role="alert">{{ $errors->first() }}</div>
    @endif

    @if ($created)
        <div class="flash info stack" role="status">
            <span>{{ __('No template fitted this file, so the new template ":name" was made from it and saved. Check the reading below.', ['name' => $created->name]) }}</span>
            <div class="row" style="align-items:center">
                <a class="button secondary small" href="{{ route('templates.design', ['key' => $key, 'template' => $created->id]) }}" style="flex:0 0 auto">{{ __('Check or edit the template') }}</a>
                <form method="POST" action="{{ route('templates.destroy', $created) }}" style="flex:0 0 auto"
                      onsubmit="return confirm(@js(__('Delete the template ":name" and set it up by hand?', ['name' => $created->name])))">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="key" value="{{ $key }}">
                    <button class="linkbutton" type="submit">{{ __('Delete it and set up by hand') }}</button>
                </form>
            </div>
        </div>
    @endif

    <form method="GET" class="row card" style="margin-bottom:12px">
        <div>
            <label for="profile">{{ __('Template') }}</label>
            <select id="profile" name="profile">
                @foreach ($templates as $template)
                    <option value="{{ $template->id }}" @selected($template->id === $preview->profileId)>{{ $template->name }}</option>
                @endforeach
            </select>
        </div>
        @if ($preview->needsName() || $employeeName)
            <div>
                <label for="employee_name">{{ __('Employee name') }} <span class="req">*</span></label>
                <input id="employee_name" type="text" name="employee_name" value="{{ $employeeName }}" placeholder="山田 花子" @if ($preview->needsName()) autofocus @endif>
            </div>
            <div>
                <label for="employee_code">{{ __('Employee code') }}</label>
                <input id="employee_code" type="text" name="employee_code" value="{{ $employeeCode }}">
            </div>
        @endif
        <button class="button secondary" type="submit">{{ __('Read again') }}</button>
    </form>
    @if ($preview->needsName())
        <div class="flash error">{{ __('This file does not contain the employee\'s name. Enter it above and read again.') }}</div>
    @endif
    <p class="small"><a href="{{ route('templates.design', $key) }}">{{ __('Wrong reading? Create a new template from this file') }}</a></p>
    @if ($preview->unknownColumns)
        <p class="small muted">{{ __('Columns not used by this template') }}: {{ implode('、', $preview->unknownColumns) }}</p>
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
        <input type="hidden" name="employee_name" value="{{ $employeeName }}">
        <input type="hidden" name="employee_code" value="{{ $employeeCode }}">
        @if ($preview->hasExisting())
            <label class="ack"><input type="checkbox" name="replace" value="1"> {{ __('Replace the earlier import (its file stays in the document list)') }}</label>
        @endif
        @if ($preview->hasCritical())
            <label class="ack"><input type="checkbox" name="acknowledged" value="1"> {{ __('Some checks fail. Import anyway after checking them.') }}</label>
        @endif
        <div class="row">
            <button class="button secondary" type="submit" formaction="{{ route('attendance.cancel', $key) }}">{{ __('Cancel') }}</button>
            <button class="button" type="submit" @disabled($preview->needsName())>{{ __('Import') }}</button>
        </div>
    </form>
@endsection
