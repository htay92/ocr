@extends('layouts.app', ['title' => 'Upload'])

@section('content')
    <h1>Upload</h1>

    @if ($errors->any())
        <div class="flash error" role="alert">
            @if ($errors->has('code')) <strong>{{ $errors->first('code') }}</strong> · @endif
            {{ collect($errors->getMessages())->except('code')->flatten()->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('documents.store') }}" enctype="multipart/form-data" class="stack" id="upload-form">
        @csrf
        <label class="dropzone" id="dropzone" for="files">
            <input id="files" type="file" name="files[]" multiple accept="{{ $accept }}">
            <strong>Drop files here or tap to choose</strong>
            <div class="muted small">Receipts, invoices, 領収証 (JPEG, PNG, HEIC, PDF) and attendance files (CSV, Excel) · up to {{ $maxMb }} MB each</div>
        </label>

        <div class="row">
            {{-- Opens the rear camera on phones. --}}
            <label class="button secondary" for="camera" style="flex:1 1 auto;margin:0">
                Take photo
                <input id="camera" type="file" name="files[]" accept="image/*" capture="environment" hidden>
            </label>
        </div>

        <ul class="file-list" id="file-list"></ul>

        <button class="button" type="submit" id="submit" disabled>Start</button>
    </form>
@endsection

@push('scripts')
<script>
(() => {
    const files = document.getElementById('files');
    const camera = document.getElementById('camera');
    const dropzone = document.getElementById('dropzone');
    const list = document.getElementById('file-list');
    const submit = document.getElementById('submit');

    function render() {
        const chosen = [...files.files, ...camera.files];
        list.replaceChildren(...chosen.map(file => {
            const li = document.createElement('li');
            const name = document.createElement('span');
            const size = document.createElement('span');
            name.textContent = file.name;
            size.className = 'muted small';
            size.textContent = (file.size / 1024 / 1024).toFixed(1) + ' MB';
            li.append(name, size);
            return li;
        }));
        submit.disabled = chosen.length === 0;
        submit.textContent = chosen.length > 1 ? `Start (${chosen.length} files)` : 'Start';
    }

    files.addEventListener('change', render);
    camera.addEventListener('change', render);

    ['dragenter', 'dragover'].forEach(type => dropzone.addEventListener(type, event => {
        event.preventDefault();
        dropzone.classList.add('over');
    }));
    ['dragleave', 'drop'].forEach(type => dropzone.addEventListener(type, () => dropzone.classList.remove('over')));
    dropzone.addEventListener('drop', event => {
        event.preventDefault();
        const merged = new DataTransfer();
        [...files.files, ...event.dataTransfer.files].forEach(file => merged.items.add(file));
        files.files = merged.files;
        render();
    });

    document.getElementById('upload-form').addEventListener('submit', () => {
        submit.disabled = true;
        submit.textContent = 'Uploading…';
    });
})();
</script>
@endpush
