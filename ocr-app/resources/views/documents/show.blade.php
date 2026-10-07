@php use App\Support\Labels; @endphp
@extends('layouts.app', ['title' => $document->original_name, 'refresh' => $document->isBusy()])

@section('content')
    <p class="small"><a href="{{ route('documents.index') }}">← {{ __('Documents') }}</a></p>
    <div class="row" style="align-items:center;margin-bottom:12px">
        <h1 style="margin:0;overflow-wrap:anywhere">{{ $document->original_name }}</h1>
        <x-status :status="$document->status" style="flex:0 0 auto" />
    </div>

    @if ($document->isBusy())
        <p class="muted small">{{ __('Processing… this page refreshes every 5 seconds.') }}</p>
    @endif

    @if ($document->error_message)
        <div class="flash error" role="alert">{{ $document->error_message }}</div>
    @endif
    @error('rerun') <div class="flash error" role="alert">{{ $message }}</div> @enderror
    @error('delete') <div class="flash error" role="alert">{{ $message }}</div> @enderror

    <div class="detail">
        <div class="preview">
            @if (str_starts_with($document->mime_type, 'image/'))
                <img src="{{ route('documents.file', $document) }}" alt="{{ $document->original_name }}">
            @elseif ($document->mime_type === 'application/pdf')
                <iframe src="{{ route('documents.file', $document) }}" title="{{ $document->original_name }}"></iframe>
            @else
                <p class="muted" style="padding:16px">{{ __('No preview for this file type.') }} <a href="{{ route('documents.file', $document) }}">{{ __('Download') }}</a></p>
            @endif
        </div>

        <div>
            <div class="card">
                <dl class="facts">
                    <dt>{{ __('Status') }}</dt><dd>{{ Labels::status($document->status) }}</dd>
                    <dt>{{ __('Type') }}</dt><dd>{{ Labels::type($document->type) }}</dd>
                    <dt>{{ __('Read by') }}</dt><dd>{{ Labels::route($document->route) }}</dd>
                    @if ($run = $document->selectedRun)
                        <dt>{{ __('Engine') }}</dt><dd>{{ $run->engine }} {{ $run->engine_version }}</dd>
                        <dt>{{ __('Time') }}</dt><dd>{{ number_format($run->elapsed_ms / 1000, 1) }} {{ __('s') }}</dd>
                        <dt>{{ __('Confidence') }}</dt><dd>{{ $run->avg_confidence === null ? '—' : number_format((float) $run->avg_confidence, 3) }}</dd>
                    @endif
                    <dt>{{ __('Uploaded') }}</dt><dd>{{ $document->created_at->format('Y-m-d H:i') }}</dd>
                </dl>
            </div>

            @if ($document->status === 'failed')
                <form method="POST" action="{{ route('documents.rerun', $document) }}" class="row card" style="margin-top:12px">
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
            @endif

            @include('documents._validation')

            @if ($receipt = $document->receipt)
                @include('documents._receipt')
            @endif

            @if ($run = $document->selectedRun)
                <h2>{{ __('Text (:count lines)', ['count' => count($run->lines ?? [])]) }}</h2>
                <p class="muted small">{{ __('Lines below :min confidence are highlighted.', ['min' => number_format($lowConfidence, 2)]) }}</p>
                <ol class="lines card">
                    @foreach ($run->lines ?? [] as $i => $line)
                        <li @class(['low' => $line['confidence'] < $lowConfidence])>
                            <span class="no">{{ $i + 1 }}</span>
                            <span class="text">{{ $line['text'] }}</span>
                            <span class="conf">{{ number_format($line['confidence'], 2) }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>

    @if ($runs->isNotEmpty())
        <h2>{{ __('OCR runs') }}</h2>
        <div class="card table-wrap">
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

    @if ($document->canBeDeleted())
        <form method="POST" action="{{ route('documents.destroy', $document) }}" class="danger-zone"
              onsubmit="return confirm(@js(__('Delete this document? The file and its results are removed and cannot be restored.')))">
            @csrf
            @method('DELETE')
            <button class="button danger" type="submit">{{ __('Delete this document') }}</button>
        </form>
    @endif
@endsection
