@extends('layouts.app', ['title' => 'Upload'])

@section('content')
    <h1>{{ __('Upload') }}</h1>

    <div class="flash error" role="alert" id="upload-error" @if (! $errors->any()) hidden @endif>
        @if ($errors->any())
            @if ($errors->has('code')) <strong>{{ $errors->first('code') }}</strong> · @endif
            {{ collect($errors->getMessages())->except('code')->flatten()->first() }}
        @endif
    </div>

    @if ($phoneQr)
        {{-- Shown on the Mac only: open the same page on the phone (same Wi-Fi). --}}
        <div class="card phone">
            <div class="qr">{!! $phoneQr !!}</div>
            <div>
                <strong>{{ __('Open on your phone') }}</strong>
                <p class="small">{{ __('Scan with the phone camera, or open this address on the same Wi-Fi:') }}</p>
                <p><a href="{{ $phoneUrl }}">{{ $phoneUrl }}</a></p>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('documents.store') }}" enctype="multipart/form-data" class="stack" id="upload-form">
        @csrf
        <label class="dropzone" id="dropzone" for="files">
            <input id="files" type="file" name="files[]" multiple accept="{{ $accept }}">
            <strong>{{ __('Drop files here or tap to choose') }}</strong>
            <div class="muted small">{{ __('Receipts, invoices, paid receipts (JPEG, PNG, HEIC, PDF) and attendance files (CSV, Excel) · up to :max MB each', ['max' => $maxMb]) }}</div>
        </label>

        {{-- Opens the rear camera on phones; every photo is added to the list. --}}
        <label class="button secondary camera" for="camera">
            📷 {{ __('Take photo') }}
            <input id="camera" type="file" name="files[]" accept="image/*" capture="environment" hidden>
        </label>

        <ul class="file-cards" id="file-list"></ul>

        <label class="muted small"><input type="checkbox" id="shrink" checked> {{ __('Shrink photos before sending (recommended)') }}</label>

        <progress id="progress" max="100" value="0" hidden></progress>
        <button class="button" type="submit" id="submit" disabled>{{ __('Upload') }}</button>
    </form>
@endsection

@push('scripts')
@php
    $texts = [
        'upload' => __('Upload'),
        'uploadCount' => __('Upload (:count files)'),
        'uploading' => __('Uploading…'),
        'preparing' => __('Preparing photos…'),
        'remove' => __('Remove'),
        'failed' => __('Upload failed. Please try again.'),
    ];
@endphp
<script>
(() => {
    const text = @json($texts);
    const MAX_SIDE = 3000;            // long side after shrinking; OCR resizes to at most 4,000 px
    const SHRINK_OVER = 1.5 * 1024 * 1024;

    const form = document.getElementById('upload-form');
    const picker = document.getElementById('files');
    const camera = document.getElementById('camera');
    const dropzone = document.getElementById('dropzone');
    const list = document.getElementById('file-list');
    const submit = document.getElementById('submit');
    const shrink = document.getElementById('shrink');
    const progress = document.getElementById('progress');
    const errorBox = document.getElementById('upload-error');
    const chosen = [];   // {file, url}

    function add(files) {
        for (const file of files) {
            chosen.push({ file, url: file.type.startsWith('image/') ? URL.createObjectURL(file) : null });
        }
        render();
    }

    function render() {
        list.replaceChildren(...chosen.map((item, index) => {
            const li = document.createElement('li');
            const thumb = document.createElement(item.url ? 'img' : 'span');
            if (item.url) {
                thumb.src = item.url;
                thumb.alt = '';
            } else {
                thumb.textContent = (item.file.name.split('.').pop() || 'file').toUpperCase();
            }
            thumb.className = 'thumb';
            const name = document.createElement('span');
            name.className = 'name';
            name.textContent = item.file.name;
            const size = document.createElement('span');
            size.className = 'muted small';
            size.textContent = (item.file.size / 1024 / 1024).toFixed(1) + ' MB';
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'remove';
            remove.textContent = '×';
            remove.setAttribute('aria-label', text.remove);
            remove.addEventListener('click', () => {
                if (item.url) URL.revokeObjectURL(item.url);
                chosen.splice(index, 1);
                render();
            });
            li.append(thumb, name, size, remove);
            return li;
        }));
        submit.disabled = chosen.length === 0;
        submit.textContent = chosen.length > 1 ? text.uploadCount.replace(':count', chosen.length) : text.upload;
    }

    // Phone photos are 3–5 MB; a 3,000 px JPEG keeps the text sharp and uploads much faster.
    // Browsers apply the EXIF rotation when decoding, so the result is upright.
    async function prepare(file) {
        const isImage = /^image\/(jpeg|png|heic|heif)$/.test(file.type) || /\.(heic|heif)$/i.test(file.name);
        if (!shrink.checked || !isImage) return file;
        try {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.src = url;
            await img.decode();
            URL.revokeObjectURL(url);
            const long = Math.max(img.naturalWidth, img.naturalHeight);
            if (file.size <= SHRINK_OVER && long <= MAX_SIDE) return file;
            const scale = Math.min(1, MAX_SIDE / long);
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(img.naturalWidth * scale);
            canvas.height = Math.round(img.naturalHeight * scale);
            canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.9));
            if (!blob || blob.size >= file.size) return file;
            return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
        } catch {
            return file;   // e.g. HEIC outside Safari: send the original
        }
    }

    picker.addEventListener('change', () => { add(picker.files); picker.value = ''; });
    camera.addEventListener('change', () => { add(camera.files); camera.value = ''; });

    ['dragenter', 'dragover'].forEach(type => dropzone.addEventListener(type, event => {
        event.preventDefault();
        dropzone.classList.add('over');
    }));
    ['dragleave', 'drop'].forEach(type => dropzone.addEventListener(type, () => dropzone.classList.remove('over')));
    dropzone.addEventListener('drop', event => {
        event.preventDefault();
        add(event.dataTransfer.files);
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (chosen.length === 0) return;
        submit.disabled = true;
        errorBox.hidden = true;
        submit.textContent = text.preparing;

        const data = new FormData();
        data.append('_token', form.querySelector('[name=_token]').value);
        for (const item of chosen) {
            const file = await prepare(item.file);
            data.append('files[]', file, file.name);
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.addEventListener('progress', e => {
            if (e.lengthComputable) progress.value = Math.round(100 * e.loaded / e.total);
        });
        xhr.addEventListener('load', () => {
            let body = {};
            try { body = JSON.parse(xhr.responseText); } catch {}
            if (xhr.status >= 200 && xhr.status < 300 && body.redirect) {
                window.location = body.redirect;
                return;
            }
            errorBox.textContent = body.code ? `${body.code} · ${body.message}` : text.failed;
            errorBox.hidden = false;
            progress.hidden = true;
            render();
        });
        xhr.addEventListener('error', () => {
            errorBox.textContent = text.failed;
            errorBox.hidden = false;
            progress.hidden = true;
            render();
        });
        progress.hidden = false;
        progress.value = 0;
        submit.textContent = text.uploading;
        xhr.send(data);
    });
})();
</script>
@endpush
