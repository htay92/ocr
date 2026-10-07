{{--
    Image viewer (SC-04): the original file, or the engine's preprocessed page with
    every OCR line box drawn on it. Zoom with − / ＋ / 全体, drag to pan, pinch on phones.
    Form fields highlight the box they were read from (data-field → field_boxes).
--}}
@php
    $run = $document->selectedRun;
    $pages = collect($run?->images ?? []);
    $isPdf = $document->mime_type === 'application/pdf';
    $isImage = str_starts_with($document->mime_type, 'image/');
@endphp
<div class="viewer" data-viewer data-field-boxes='@json($document->receipt?->field_boxes ?? (object) [])'>
    <div class="viewer-bar">
        @if ($pages->isNotEmpty())
            <div class="segmented" role="group">
                <button type="button" data-mode="ocr" class="active">{{ __('OCR boxes') }}</button>
                <button type="button" data-mode="original">{{ __('Original') }}</button>
            </div>
            @if ($pages->count() > 1)
                <div class="segmented" role="group" data-page-tabs>
                    @foreach ($pages as $image)
                        <button type="button" data-page="{{ $image['page'] }}" @class(['active' => $loop->first])>{{ __('p.:n', ['n' => $image['page']]) }}</button>
                    @endforeach
                </div>
            @endif
        @endif
        <div class="segmented zoom" role="group">
            <button type="button" data-zoom="out" aria-label="{{ __('Zoom out') }}">−</button>
            <button type="button" data-zoom="fit">{{ __('Fit') }}</button>
            <button type="button" data-zoom="in" aria-label="{{ __('Zoom in') }}">＋</button>
        </div>
    </div>

    <div class="viewer-stage" data-stage>
        <div class="viewer-canvas" data-canvas>
            @foreach ($pages as $image)
                <div class="viewer-page" data-view="ocr" data-page="{{ $image['page'] }}" @if (! $loop->first) hidden @endif>
                    <img src="{{ route('documents.page', [$document, $run, $image['page']]) }}" alt="{{ __('Page :n as read by OCR', ['n' => $image['page']]) }}" draggable="false">
                    <svg viewBox="0 0 {{ $image['width'] }} {{ $image['height'] }}" preserveAspectRatio="none" aria-hidden="true">
                        @foreach ($boxes[$image['page']] ?? [] as [$x1, $y1, $x2, $y2, $confidence, $text])
                            <rect x="{{ $x1 }}" y="{{ $y1 }}" width="{{ $x2 - $x1 }}" height="{{ $y2 - $y1 }}" @class(['box', 'low' => $confidence < $lowConfidence])><title>{{ $text }} ({{ $confidence }})</title></rect>
                        @endforeach
                        <rect class="focus" data-focus x="0" y="0" width="0" height="0"></rect>
                    </svg>
                </div>
            @endforeach

            <div class="viewer-page" data-view="original" @if ($pages->isNotEmpty()) hidden @endif>
                @if ($isImage)
                    <img src="{{ route('documents.file', $document) }}" alt="{{ $document->original_name }}" draggable="false">
                @elseif ($isPdf)
                    <iframe src="{{ route('documents.file', $document) }}" title="{{ $document->original_name }}"></iframe>
                @else
                    <p class="muted" style="padding:16px">{{ __('No preview for this file type.') }} <a href="{{ route('documents.file', $document) }}">{{ __('Download') }}</a></p>
                @endif
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(() => {
    const viewer = document.querySelector('[data-viewer]');
    if (!viewer) return;
    const stage = viewer.querySelector('[data-stage]');
    const canvas = viewer.querySelector('[data-canvas]');
    const boxes = JSON.parse(viewer.dataset.fieldBoxes || '{}');
    let zoom = 1, mode = viewer.querySelector('[data-mode].active')?.dataset.mode || 'original', page = 1;

    function show() {
        viewer.querySelectorAll('.viewer-page').forEach(el => {
            el.hidden = el.dataset.view !== mode || (mode === 'ocr' && Number(el.dataset.page) !== page);
        });
        viewer.querySelectorAll('[data-mode]').forEach(b => b.classList.toggle('active', b.dataset.mode === mode));
        viewer.querySelectorAll('[data-page-tabs] [data-page]').forEach(b => b.classList.toggle('active', Number(b.dataset.page) === page));
        canvas.style.width = (zoom * 100) + '%';
    }

    viewer.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.dataset.mode) mode = button.dataset.mode;
        if (button.dataset.page) { page = Number(button.dataset.page); mode = 'ocr'; }
        if (button.dataset.zoom === 'in') zoom = Math.min(zoom * 1.5, 6);
        if (button.dataset.zoom === 'out') zoom = Math.max(zoom / 1.5, 1);
        if (button.dataset.zoom === 'fit') zoom = 1;
        show();
    });

    // Drag to pan with a mouse; touch screens scroll and pinch natively.
    let drag = null;
    stage.addEventListener('pointerdown', e => {
        if (e.pointerType !== 'mouse' || zoom === 1) return;
        drag = { x: e.clientX, y: e.clientY, left: stage.scrollLeft, top: stage.scrollTop };
        stage.classList.add('dragging');
    });
    window.addEventListener('pointermove', e => {
        if (!drag) return;
        stage.scrollLeft = drag.left - (e.clientX - drag.x);
        stage.scrollTop = drag.top - (e.clientY - drag.y);
    });
    window.addEventListener('pointerup', () => { drag = null; stage.classList.remove('dragging'); });

    // Highlight where a field was read when its input gets focus.
    document.addEventListener('focusin', event => {
        const field = event.target.closest('[data-field]')?.dataset.field;
        const where = field && boxes[field];
        viewer.querySelectorAll('[data-focus]').forEach(r => r.setAttribute('width', 0));
        if (!where || !viewer.querySelector('[data-view="ocr"]')) return;
        mode = 'ocr';
        page = where.page;
        show();
        const pageEl = viewer.querySelector(`[data-view="ocr"][data-page="${page}"]`);
        const rect = pageEl.querySelector('[data-focus]');
        const [x1, y1, x2, y2] = where.box;
        const pad = 6;
        rect.setAttribute('x', x1 - pad); rect.setAttribute('y', y1 - pad);
        rect.setAttribute('width', x2 - x1 + 2 * pad); rect.setAttribute('height', y2 - y1 + 2 * pad);
        const svg = pageEl.querySelector('svg');
        const scale = pageEl.clientHeight / svg.viewBox.baseVal.height;
        stage.scrollTo({ top: Math.max(0, y1 * scale - stage.clientHeight / 3), behavior: 'smooth' });
    });

    show();
})();
</script>
@endpush
