<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentsRequest;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Ocr\OcrManager;
use App\Services\DocumentUploader;
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
            'type' => ['nullable', 'string', 'max:20'],
        ]);

        $documents = $request->user()->documents()
            ->with('selectedRun')
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

    public function create(): View
    {
        return view('documents.create', [
            'accept' => '.'.implode(',.', StoreDocumentsRequest::EXTENSIONS),
            'maxMb' => config('ocr.upload.max_mb'),
        ]);
    }

    public function store(StoreDocumentsRequest $request, DocumentUploader $uploader): RedirectResponse
    {
        $count = collect($request->file('files'))
            ->each(fn ($file) => $uploader->store($file, $request->user()))
            ->count();

        return redirect()->route('documents.index')
            ->with('status', trans_choice('{1} 1 file uploaded; it is being processed.|[2,*] :count files uploaded; they are being processed.', $count));
    }

    public function show(Document $document, OcrManager $ocr): View
    {
        Gate::authorize('view', $document);

        return view('documents.show', [
            'document' => $document->load('selectedRun'),
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
            return back()->withErrors(['rerun' => 'Only failed documents can be re-run.']);
        }

        $document->transitionTo(Document::PENDING, ['error_message' => null]);
        ProcessDocumentJob::dispatch($document, $data['engine'] ?? null);

        return redirect()->route('documents.show', $document)->with('status', 'Re-run queued.');
    }
}
