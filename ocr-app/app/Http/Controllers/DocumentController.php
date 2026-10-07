<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentsRequest;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Ocr\OcrManager;
use App\Services\DocumentUploader;
use App\Support\Labels;
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
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'type' => ['nullable', Rule::in(array_keys(Labels::TYPES))],
        ]);

        $documents = $request->user()->documents()
            ->with(['selectedRun', 'receipt'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('documents.index', [
            'documents' => $documents,
            'filters' => $filters,
            'statuses' => self::STATUSES,
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

        return view('documents.show', [
            'document' => $document->load(['selectedRun', 'receipt.taxes', 'receipt.lines']),
            'checks' => $document->checks()->get(),
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
            return back()->withErrors(['rerun' => __('Only failed documents can be re-run.')]);
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
}
