<?php

namespace App\Http\Controllers;

use App\Attendance\AttendanceImporter;
use App\Models\AttendanceSheet;
use App\Models\Document;
use App\Models\ImportProfile;
use App\Processing\ProcessingException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * SC-06 attendance import: upload, preview with A-01..A-08, then import.
 * A source is either an upload kept under imports/ until it is imported
 * ("u-<uuid>.<ext>") or a document uploaded through the normal upload ("d-<id>").
 */
class AttendanceController extends Controller
{
    public function index(Request $request): View
    {
        return view('attendance.index', [
            'sheets' => AttendanceSheet::whereHas('document', fn ($q) => $q->where('user_id', $request->user()->id))
                ->with('document')->orderByDesc('period_start')->orderBy('employee_name')->paginate(50),
        ]);
    }

    public function create(): View
    {
        return view('attendance.import', [
            'profiles' => ImportProfile::orderBy('name')->get(),
            'maxMb' => config('ocr.upload.max_mb'),
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:pdf,csv,xlsx,xls', 'max:'.(config('ocr.upload.max_mb') * 1024)],
            'profile_id' => ['nullable', 'exists:import_profiles,id'],
        ], [], ['file' => __('file')]);

        $file = $request->file('file');
        $key = 'u-'.Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
        $file->storeAs('imports', substr($key, 2), 'local');
        $request->session()->put("imports.{$key}", $file->getClientOriginalName());

        return redirect()->route('attendance.preview', ['key' => $key, 'profile' => $request->input('profile_id')]);
    }

    public function preview(Request $request, string $key, AttendanceImporter $importer): View|RedirectResponse
    {
        [$path, $name, $document] = $this->source($request, $key);
        $profile = $request->query('profile') ? ImportProfile::find($request->query('profile')) : null;

        try {
            $preview = $importer->preview($path, $name, $profile);
        } catch (ProcessingException $e) {
            return redirect()->route('attendance.create')->withErrors(['file' => "{$e->errorCode}: {$e->getMessage()}"]);
        }

        return view('attendance.preview', [
            'key' => $key,
            'fileName' => $name,
            'document' => $document,
            'preview' => $preview,
            'profiles' => ImportProfile::orderBy('name')->get(),
        ]);
    }

    public function import(Request $request, string $key, AttendanceImporter $importer): RedirectResponse
    {
        [$path, $name, $document] = $this->source($request, $key);
        $profile = $request->input('profile_id') ? ImportProfile::find($request->input('profile_id')) : null;

        $preview = $importer->preview($path, $name, $profile);
        $document = $importer->import($preview, $path, $name, $request->user(), $request->boolean('replace'), $request->boolean('acknowledged'), $document);

        $this->forget($request, $key);

        return redirect()->route('documents.show', $document)->with('status', trans_choice(
            '{1} Imported 1 attendance sheet.|[2,*] Imported :count attendance sheets.', count($preview->sheets),
        ));
    }

    public function cancel(Request $request, string $key): RedirectResponse
    {
        $this->forget($request, $key);

        return redirect()->route('attendance.index');
    }

    /**
     * @return array{0: string, 1: string, 2: ?Document} local path, file name, document
     */
    private function source(Request $request, string $key): array
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

    private function forget(Request $request, string $key): void
    {
        if (str_starts_with($key, 'u-')) {
            Storage::disk('local')->delete('imports/'.substr($key, 2));
            $request->session()->forget("imports.{$key}");
        }
    }
}
