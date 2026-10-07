<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentsRequest;
use App\Http\Requests\UpdateReceiptRequest;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\OcrRunResource;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Ocr\OcrManager;
use App\Services\DocumentUploader;
use App\Services\ReceiptCorrector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
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
     * List the user's documents, newest first, with the SC-02 filters (spec 2.3).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $documents = $request->user()->documents()
            ->filter($request->validate(Document::filterRules()))
            ->with('receipt')
            ->latest('id')
            ->paginate(50);

        return DocumentResource::collection($documents);
    }

    public function show(Document $document): DocumentResource
    {
        Gate::authorize('view', $document);

        return new DocumentResource($document->load(['selectedRun', 'receipt.taxes', 'receipt.lines']));
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
     * Re-run a document (failed, extracted or needing review), optionally with a specific engine.
     * Corrections are replaced by the new reading.
     */
    public function rerun(Request $request, Document $document, OcrManager $ocr): JsonResponse
    {
        Gate::authorize('update', $document);

        $data = $request->validate([
            'engine' => ['nullable', 'string', Rule::in($ocr->enabledEngines())],
        ]);

        if (! $document->canTransitionTo(Document::PENDING)) {
            return response()->json([
                'message' => "This document cannot be re-run while it is {$document->status}.",
            ], 409);
        }

        $document->transitionTo(Document::PENDING, ['error_message' => null]);
        ProcessDocumentJob::dispatch($document, $data['engine'] ?? null);

        return (new DocumentResource($document))->response()->setStatusCode(202);
    }

    /**
     * Delete a document with its file and results. Not while it is being processed.
     */
    public function destroy(Document $document): JsonResponse|Response
    {
        Gate::authorize('delete', $document);

        if (! $document->canBeDeleted()) {
            return response()->json(['message' => 'The document is being processed; delete it when processing has finished.'], 409);
        }

        $document->deleteWithFile();

        return response()->noContent();
    }

    /**
     * Save corrections (spec 2.3 PUT /api/documents/{id}): type, fields, taxes[], lines[].
     * The checks run again and come back in the response.
     */
    public function update(UpdateReceiptRequest $request, Document $document, ReceiptCorrector $corrector): JsonResponse|DocumentResource
    {
        if (! $document->isEditable()) {
            return response()->json(['message' => "The document cannot be edited while it is {$document->status}."], 409);
        }

        $corrector->save($document, $request->corrections());

        return new DocumentResource($document->fresh()->load(['selectedRun', 'receipt.taxes', 'receipt.lines']));
    }

    /**
     * Mark as confirmed (spec 2.3). With failing critical checks, send "acknowledged": true.
     */
    public function confirm(Request $request, Document $document, ReceiptCorrector $corrector): JsonResponse|DocumentResource
    {
        Gate::authorize('update', $document);

        if (! $document->isEditable()) {
            return response()->json(['message' => "The document cannot be confirmed while it is {$document->status}."], 409);
        }

        $corrector->confirm($document, $request->boolean('acknowledged'));

        return new DocumentResource($document->fresh()->load(['selectedRun', 'receipt.taxes', 'receipt.lines']));
    }

    public function reopen(Document $document, ReceiptCorrector $corrector): JsonResponse|DocumentResource
    {
        Gate::authorize('update', $document);

        if ($document->status !== Document::CONFIRMED) {
            return response()->json(['message' => 'Only confirmed documents can be reopened.'], 409);
        }

        $corrector->reopen($document);

        return new DocumentResource($document->fresh());
    }
}
