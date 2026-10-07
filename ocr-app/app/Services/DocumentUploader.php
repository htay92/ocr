<?php

namespace App\Services;

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Spec 2.4 step 1: save the file privately, hash it, create a pending document and queue it.
 */
class DocumentUploader
{
    public function store(UploadedFile $file, User $user): Document
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->extension());
        $path = $file->storeAs('documents/'.now()->format('Y/m'), Str::uuid().'.'.$extension, 'local');

        $document = $user->documents()->create([
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'sha256' => hash_file('sha256', $file->getRealPath()),
        ]);

        ProcessDocumentJob::dispatch($document);

        return $document;
    }
}
