<?php

namespace App\Attendance;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Files on their way into an import or a new template. A key is either an upload
 * kept under imports/ until it is used ("u-<uuid>.<ext>", remembered in the
 * session) or a document from the normal upload ("d-<id>").
 */
class ImportFiles
{
    public const EXTENSIONS = 'pdf,csv,xlsx,xls';

    public function store(Request $request, UploadedFile $file): string
    {
        $key = 'u-'.Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
        $file->storeAs('imports', substr($key, 2), 'local');
        $request->session()->put("imports.{$key}", $file->getClientOriginalName());

        return $key;
    }

    /**
     * @return array{0: string, 1: string, 2: ?Document} local path, file name, document
     */
    public function resolve(Request $request, string $key): array
    {
        if (preg_match('/^d-(\d+)$/', $key, $m)) {
            $document = Document::findOrFail((int) $m[1]);
            Gate::authorize('view', $document);

            return [Storage::disk('local')->path($document->file_path), $document->original_name, $document];
        }

        $name = $request->session()->get("imports.{$key}");
        abort_unless(is_string($name) && preg_match('/^u-[0-9a-f\-]{36}\.(pdf|csv|xlsx|xls)$/', $key), 404);
        abort_unless(Storage::disk('local')->exists('imports/'.substr($key, 2)), 404);

        return [Storage::disk('local')->path('imports/'.substr($key, 2)), $name, null];
    }

    public function forget(Request $request, string $key): void
    {
        if (str_starts_with($key, 'u-')) {
            Storage::disk('local')->delete('imports/'.substr($key, 2));
            $request->session()->forget("imports.{$key}");
        }
    }
}
