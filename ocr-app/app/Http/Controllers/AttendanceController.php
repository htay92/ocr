<?php

namespace App\Http\Controllers;

use App\Attendance\AttendanceImporter;
use App\Attendance\ImportFiles;
use App\Attendance\NoTemplateMatches;
use App\Attendance\TemplateDesigner;
use App\Models\AttendanceSheet;
use App\Models\ImportProfile;
use App\Processing\ProcessingException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * SC-06 attendance import: upload, preview with the matching template and
 * A-01..A-08, then import. A file that no template fits leads to the template
 * setting page first.
 */
class AttendanceController extends Controller
{
    public function __construct(private readonly ImportFiles $files) {}

    public function index(Request $request): View
    {
        return view('attendance.index', [
            'sheets' => AttendanceSheet::whereHas('document', fn ($q) => $q->where('user_id', $request->user()->id))
                ->with('document')->orderByDesc('period_start')->orderBy('employee_name')->paginate(50),
        ]);
    }

    public function create(): View
    {
        return view('attendance.import', ['maxMb' => config('ocr.upload.max_mb')]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:'.ImportFiles::EXTENSIONS, 'max:'.(config('ocr.upload.max_mb') * 1024)],
        ], [], ['file' => __('file')]);

        return redirect()->route('attendance.preview', ['key' => $this->files->store($request, $request->file('file'))]);
    }

    public function preview(Request $request, string $key, AttendanceImporter $importer): View
    {
        [$path, $name, $document] = $this->files->resolve($request, $key);
        $profile = $request->query('profile') ? ImportProfile::find($request->query('profile')) : null;

        try {
            $preview = $importer->preview($path, $name, $profile, $request->query('employee_name'), $request->query('employee_code'));
        } catch (NoTemplateMatches|ProcessingException $e) {
            $request->session()->put('template-return', $key);

            return view('attendance.no-template', [
                'key' => $key,
                'fileName' => $name,
                'error' => $e instanceof ProcessingException ? "{$e->errorCode}: {$e->getMessage()}" : null,
                'templates' => $this->templatesFor($name),
                'selected' => $profile?->id,
            ]);
        }

        return view('attendance.preview', [
            'key' => $key,
            'fileName' => $name,
            'document' => $document,
            'preview' => $preview,
            'templates' => $this->templatesFor($name),
            'employeeName' => $request->query('employee_name'),
            'employeeCode' => $request->query('employee_code'),
        ]);
    }

    public function import(Request $request, string $key, AttendanceImporter $importer): RedirectResponse
    {
        [$path, $name, $document] = $this->files->resolve($request, $key);
        $profile = $request->input('profile_id') ? ImportProfile::find($request->input('profile_id')) : null;

        $preview = $importer->preview($path, $name, $profile, $request->input('employee_name') ?: null, $request->input('employee_code') ?: null);
        $document = $importer->import($preview, $path, $name, $request->user(), $request->boolean('replace'), $request->boolean('acknowledged'), $document);

        $this->files->forget($request, $key);

        return redirect()->route('documents.show', $document)->with('status', trans_choice(
            '{1} Imported 1 attendance sheet.|[2,*] Imported :count attendance sheets.', count($preview->sheets),
        ));
    }

    public function cancel(Request $request, string $key): RedirectResponse
    {
        $this->files->forget($request, $key);

        return redirect()->route('attendance.index');
    }

    private function templatesFor(string $fileName)
    {
        return ImportProfile::where('kind', TemplateDesigner::isPdf($fileName) ? 'pdf' : 'spreadsheet')->orderBy('name')->get();
    }
}
