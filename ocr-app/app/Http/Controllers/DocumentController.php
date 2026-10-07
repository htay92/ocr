<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentsRequest;
use App\Http\Requests\UpdateReceiptRequest;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\OcrRun;
use App\Models\Receipt;
use App\Ocr\OcrManager;
use App\Services\DocumentUploader;
use App\Services\ReceiptCorrector;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Browser screens: SC-02 list, SC-03 upload and a document detail page.
 * The full review and correction screen (SC-04) follows extraction (milestone 4).
 */
class DocumentController extends Controller
{
    public const STATUSES = [
        Document::PENDING, Document::PROCESSING, Document::EXTRACTED,
        Document::NEEDS_REVIEW, Document::CONFIRMED, Document::FAILED,
    ];

    public function index(Request $request): View
    {
        $filters = $request->validate(Document::filterRules());
        $query = $request->user()->documents()->filter($filters);

        $documents = (clone $query)->with(['selectedRun', 'receipt'])->latest('id')->paginate(30)->withQueryString();

        return view('documents.index', [
            'documents' => $documents,
            'filters' => $filters,
            'statuses' => self::STATUSES,
            'sum' => Receipt::whereIn('document_id', (clone $query)->select('id'))->sum('total'),
            'busy' => $documents->contains(fn (Document $document) => $document->isBusy()),
        ]);
    }

    public function create(Request $request): View
    {
        $phoneUrl = config('ocr.phone_url');
        // Only on the Mac itself: on the phone the QR code would point to the page already open.
        $onThisComputer = in_array($request->getHost(), ['localhost', '127.0.0.1', '::1'], true);

        return view('documents.create', [
            'accept' => '.'.implode(',.', StoreDocumentsRequest::EXTENSIONS),
            'maxMb' => config('ocr.upload.max_mb'),
            'phoneUrl' => $phoneUrl,
            'phoneQr' => $phoneUrl && $onThisComputer ? self::qrSvg(rtrim($phoneUrl, '/').'/documents/upload') : null,
        ]);
    }

    public function store(StoreDocumentsRequest $request, DocumentUploader $uploader): RedirectResponse|JsonResponse
    {
        $count = collect($request->file('files'))
            ->each(fn ($file) => $uploader->store($file, $request->user()))
            ->count();

        $message = trans_choice('{1} 1 file uploaded; it is being processed.|[2,*] :count files uploaded; they are being processed.', $count);
        session()->flash('status', $message);

        // The upload page sends files with XMLHttpRequest (progress bar) and follows this redirect.
        return $request->expectsJson()
            ? response()->json(['redirect' => route('documents.index'), 'count' => $count])
            : redirect()->route('documents.index');
    }

    private static function qrSvg(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(160, 1), new SvgImageBackEnd)))->writeString($url);

        return preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }

    public function show(Document $document, OcrManager $ocr): View
    {
        Gate::authorize('view', $document);

        $document->load(['selectedRun', 'receipt.taxes', 'receipt.lines', 'attendanceSheets.records', 'attendanceSheets.checks']);
        $checks = $document->checks()->get();

        return view('documents.show', [
            'document' => $document,
            'checks' => $checks,
            'hasCritical' => $checks->contains('level', 'critical'),
            'boxes' => self::boxesByPage($document->selectedRun?->lines ?? []),
            'runs' => $document->ocrRuns()->latest('id')->get(),
            'engines' => $ocr->enabledEngines(),
            'lowConfidence' => (float) config('ocr.confidence_min'),
        ]);
    }

    /**
     * The original file, inline, only for its owner (spec 2.6).
     */
    public function file(Document $document): StreamedResponse
    {
        Gate::authorize('view', $document);

        return Storage::disk('local')->response($document->file_path, $document->original_name, [], 'inline');
    }

    public function rerun(Request $request, Document $document, OcrManager $ocr): RedirectResponse
    {
        Gate::authorize('update', $document);

        $data = $request->validate([
            'engine' => ['nullable', 'string', Rule::in($ocr->enabledEngines())],
        ]);

        if (! $document->canTransitionTo(Document::PENDING)) {
            return back()->withErrors(['rerun' => __('This document cannot be re-run now.')]);
        }

        $document->transitionTo(Document::PENDING, ['error_message' => null]);
        ProcessDocumentJob::dispatch($document, $data['engine'] ?? null);

        return redirect()->route('documents.show', $document)->with('status', __('Re-run queued.'));
    }

    public function destroy(Document $document): RedirectResponse
    {
        Gate::authorize('delete', $document);

        if (! $document->canBeDeleted()) {
            return back()->withErrors(['delete' => __('This document is being processed. Delete it when processing has finished.')]);
        }

        $document->deleteWithFile();

        return redirect()->route('documents.index')->with('status', __('Document deleted.'));
    }

    /**
     * SC-04: save corrections, check them again, and confirm when asked ("保存して確定").
     */
    public function update(UpdateReceiptRequest $request, Document $document, ReceiptCorrector $corrector): RedirectResponse
    {
        if (! $document->isEditable()) {
            return back()->withErrors(['review' => __('This document cannot be edited now.')]);
        }

        $corrector->save($document, $request->corrections());

        if ($request->boolean('confirm')) {
            $corrector->confirm($document->fresh(), $request->boolean('acknowledged'));

            return redirect()->route('documents.show', $document)->with('status', __('Confirmed.'));
        }

        return redirect()->route('documents.show', $document)->with('status', __('Saved. The checks were run again.'));
    }

    public function confirm(Request $request, Document $document, ReceiptCorrector $corrector): RedirectResponse
    {
        Gate::authorize('update', $document);

        if (! $document->isEditable()) {
            return back()->withErrors(['review' => __('This document cannot be confirmed now.')]);
        }

        $corrector->confirm($document, $request->boolean('acknowledged'));

        return redirect()->route('documents.show', $document)->with('status', __('Confirmed.'));
    }

    public function reopen(Document $document, ReceiptCorrector $corrector): RedirectResponse
    {
        Gate::authorize('update', $document);

        if ($document->status !== Document::CONFIRMED) {
            return back();
        }

        $corrector->reopen($document);

        return redirect()->route('documents.show', $document)->with('status', __('Reopened for corrections.'));
    }

    /**
     * A preprocessed page image of an OCR run (the image the boxes refer to).
     */
    public function page(Document $document, OcrRun $run, int $page): StreamedResponse
    {
        Gate::authorize('view', $document);
        abort_unless($run->document_id === $document->id, 404);

        $image = collect($run->images ?? [])->firstWhere('page', $page) ?? abort(404);

        return Storage::disk('local')->response($image['path'], null, ['Cache-Control' => 'private, max-age=86400']);
    }

    /**
     * OCR line boxes per page for the viewer overlay: [left, top, right, bottom, confidence, text].
     *
     * @return array<int, list<array>>
     */
    private static function boxesByPage(array $lines): array
    {
        $pages = [];
        foreach ($lines as $line) {
            if (empty($line['bbox'])) {
                continue;
            }
            $xs = array_column($line['bbox'], 0);
            $ys = array_column($line['bbox'], 1);
            $pages[$line['page'] ?? 1][] = [min($xs), min($ys), max($xs), max($ys), round((float) $line['confidence'], 2), $line['text']];
        }

        return $pages;
    }
}
