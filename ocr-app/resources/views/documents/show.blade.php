@php use App\Support\Labels; @endphp
@extends('layouts.app', ['title' => $document->original_name, 'refresh' => $document->isBusy()])

@section('content')
    <p class="small"><a href="{{ route('documents.index') }}">← {{ __('Documents') }}</a></p>
    <div class="row title-row">
        <h1>{{ $document->receipt?->vendor_name ?? $document->original_name }}</h1>
        <x-status :status="$document->status" />
    </div>

    @if ($document->isBusy())
        <p class="muted small">{{ __('Processing… this page refreshes every 5 seconds.') }}</p>
    @endif
    @if ($document->error_message)
        <div class="flash error" role="alert">{{ $document->error_message }}</div>
    @endif
    @foreach (['rerun', 'delete', 'review'] as $key)
        @error($key) <div class="flash error" role="alert">{{ $message }}</div> @enderror
    @endforeach

    @if ($document->status === 'confirmed')
        <div class="card confirmed-banner">
            <div>
                <strong>{{ __('Confirmed') }}</strong>
                <span class="muted small">{{ $document->confirmed_at?->format('Y-m-d H:i') }} · {{ __('Used for export and as ground truth.') }}</span>
            </div>
            <form method="POST" action="{{ route('documents.reopen', $document) }}">
                @csrf
                <button class="button secondary" type="submit">{{ __('Edit again') }}</button>
            </form>
        </div>
    @endif

    @php $hasPicture = str_starts_with($document->mime_type, 'image/') || $document->mime_type === 'application/pdf'; @endphp
    <div @class(['detail', 'single' => ! $hasPicture])>
        @if ($hasPicture)
            <div class="viewer-column">
                @include('documents._viewer')
            </div>
        @endif

        <div>
            @if ($document->type !== 'attendance') @include('documents._validation') @endif

            @if ($document->type === 'attendance')
                @forelse ($document->attendanceSheets as $sheet)
                    <h2>{{ __('Checks') }} · {{ $sheet->employee_name }}</h2>
                    @include('attendance._checks', ['results' => $sheet->checks->map->only(['rule_code', 'level', 'message'])->all()])
                    @include('attendance._sheet', ['sheet' => \App\Attendance\SheetView::fromModel($sheet)])
                @empty
                    @if (! $document->isBusy())
                        <div class="card">
                            <p>{{ __('This looks like an attendance file. Import it to read the days and check the totals.') }}</p>
                            <a class="button" href="{{ route('attendance.preview', 'd-'.$document->id) }}">{{ __('Import as attendance') }}</a>
                        </div>
                    @endif
                @endforelse
            @elseif ($document->isEditable())
                @include('documents._form')
            @elseif ($receipt = $document->receipt)
                @include('documents._receipt')
            @endif

            <details class="card more" @if (! $document->receipt && ! $document->isEditable()) open @endif>
                <summary>{{ __('Document details and OCR text') }}</summary>
                <dl class="facts">
                    <dt>{{ __('File') }}</dt><dd>{{ $document->original_name }}</dd>
                    <dt>{{ __('Type') }}</dt><dd>{{ Labels::type($document->type) }}</dd>
                    <dt>{{ __('Read by') }}</dt><dd>{{ Labels::route($document->route) }}</dd>
                    @if ($run = $document->selectedRun)
                        <dt>{{ __('Engine') }}</dt><dd>{{ $run->engine }} {{ $run->engine_version }}</dd>
                        <dt>{{ __('Time') }}</dt><dd>{{ number_format($run->elapsed_ms / 1000, 1) }} {{ __('s') }}</dd>
                        <dt>{{ __('Confidence') }}</dt><dd>{{ $run->avg_confidence === null ? '—' : number_format((float) $run->avg_confidence, 3) }}</dd>
                    @endif
                    <dt>{{ __('Uploaded') }}</dt><dd>{{ $document->created_at->format('Y-m-d H:i') }}</dd>
                </dl>

                @if ($run = $document->selectedRun)
                    <h3>{{ __('Text (:count lines)', ['count' => count($run->lines ?? [])]) }}</h3>
                    <p class="muted small">{{ __('Lines below :min confidence are highlighted.', ['min' => number_format($lowConfidence, 2)]) }}</p>
                    <ol class="lines">
                        @foreach ($run->lines ?? [] as $i => $line)
                            <li @class(['low' => $line['confidence'] < $lowConfidence])>
                                <span class="no">{{ $i + 1 }}</span>
                                <span class="text">{{ $line['text'] }}</span>
                                <span class="conf">{{ number_format($line['confidence'], 2) }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif

                @if ($runs->isNotEmpty())
                    <h3>{{ __('OCR runs') }}</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>#</th><th>{{ __('Engine') }}</th><th>{{ __('Result') }}</th><th>{{ __('Critical') }}</th><th>{{ __('Lines') }}</th><th>{{ __('Confidence') }}</th><th>{{ __('Time') }}</th><th>{{ __('When') }}</th></tr></thead>
                            <tbody>
                            @foreach ($runs as $item)
                                <tr>
                                    <td>{{ $item->id }}{{ $item->id === $document->selected_run_id ? ' ✓' : '' }}</td>
                                    <td>{{ $item->engine }}</td>
                                    <td>{{ $item->error_code ?? 'OK' }}</td>
                                    <td>{{ $item->error_code ? '—' : $item->critical_failures }}</td>
                                    <td>{{ count($item->lines ?? []) }}</td>
                                    <td>{{ $item->avg_confidence === null ? '—' : number_format((float) $item->avg_confidence, 3) }}</td>
                                    <td>{{ $item->elapsed_ms === null ? '—' : number_format($item->elapsed_ms / 1000, 1).' '.__('s') }}</td>
                                    <td>{{ $item->created_at->format('m-d H:i:s') }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </details>

            @if ($document->canTransitionTo('pending'))
                <details class="card more" @if ($document->status === 'failed') open @endif>
                    <summary>{{ __('Read again') }}</summary>
                    <form method="POST" action="{{ route('documents.rerun', $document) }}" class="row"
                          @if ($document->status !== 'failed') onsubmit="return confirm(@js(__('Read the document again? Corrections are replaced by the new reading.')))" @endif>
                        @csrf
                        <div>
                            <label for="engine">{{ __('Re-run with') }}</label>
                            <select id="engine" name="engine">
                                <option value="">{{ __('Default order') }}</option>
                                @foreach ($engines as $engine)
                                    <option value="{{ $engine }}">{{ $engine }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button class="button" type="submit">{{ __('Re-run') }}</button>
                    </form>
                </details>
            @endif

            @if ($document->canBeDeleted())
                <form method="POST" action="{{ route('documents.destroy', $document) }}" class="danger-zone"
                      onsubmit="return confirm(@js(__('Delete this document? The file and its results are removed and cannot be restored.')))">
                    @csrf
                    @method('DELETE')
                    <button class="button danger" type="submit">{{ __('Delete this document') }}</button>
                </form>
            @endif
        </div>
    </div>
@endsection
