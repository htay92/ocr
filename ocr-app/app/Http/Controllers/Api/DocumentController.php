<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentsRequest;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\OcrRunResource;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Ocr\OcrManager;
use App\Services\DocumentUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    /**
     * Upload one or more files; processing runs in the queue (NFR-05).
     */
    public function store(StoreDocumentsRequest $request, DocumentUploader $uploader): JsonResponse
    {
        $documents = collect($request->file('files'))
            ->map(fn ($file) => $uploader->store($file, $request->user()));

        return DocumentResource::collection($documents)->response()->setStatusCode(202);
    }

    /**
     * List the user's documents, newest first. Full filters (date, vendor, amount) come with SC-02.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([
                Document::PENDING, Document::PROCESSING, Document::EXTRACTED,
                Document::NEEDS_REVIEW, Document::CONFIRMED, Document::FAILED,
            ])],
            'type' => ['nullable', 'string', 'max:20'],
        ]);

        $documents = $request->user()->documents()
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->latest('id')
            ->paginate(50);

        return DocumentResource::collection($documents);
    }

    public function show(Document $document): DocumentResource
    {
        Gate::authorize('view', $document);

        return new DocumentResource($document->load('selectedRun'));
    }

    /**
     * Every OCR run of the document, newest first (FR-11, UC-08).
     */
    public function runs(Document $document): AnonymousResourceCollection
    {
        Gate::authorize('view', $document);

        return OcrRunResource::collection($document->ocrRuns()->latest('id')->get());
    }

    /**
     * The original file, only through this authenticated route (spec 2.6).
     */
    public function file(Document $document): StreamedResponse
    {
        Gate::authorize('view', $document);

        return Storage::disk('local')->response($document->file_path, $document->original_name);
    }

    /**
     * Re-run a failed document, optionally with a specific engine.
     */
    public function rerun(Request $request, Document $document, OcrManager $ocr): JsonResponse
    {
        Gate::authorize('update', $document);

        $data = $request->validate([
            'engine' => ['nullable', 'string', Rule::in($ocr->enabledEngines())],
        ]);

        if (! $document->canTransitionTo(Document::PENDING)) {
            return response()->json([
                'message' => "Only failed documents can be re-run; this one is {$document->status}.",
            ], 409);
        }

        $document->transitionTo(Document::PENDING, ['error_message' => null]);
        ProcessDocumentJob::dispatch($document, $data['engine'] ?? null);

        return (new DocumentResource($document))->response()->setStatusCode(202);
    }
}
