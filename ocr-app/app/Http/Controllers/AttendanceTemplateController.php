<?php

namespace App\Http\Controllers;

use App\Attendance\AttendanceImporter;
use App\Attendance\ImportFiles;
use App\Attendance\Support\TimeValue;
use App\Attendance\Template;
use App\Attendance\TemplateDesigner;
use App\Models\ImportProfile;
use App\Processing\ProcessingException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Attendance templates (setting page): a new company format is set up from a
 * sample file. The page reads the sample, suggests every setting, tests the
 * template on the sample, and saves it; imports then pick it automatically.
 */
class AttendanceTemplateController extends Controller
{
    public function __construct(private readonly ImportFiles $files) {}

    public function index(): View
    {
        return view('attendance.templates.index', [
            'templates' => ImportProfile::withCount('attendanceSheets')->orderBy('name')->get()
                ->map(fn (ImportProfile $p) => ['profile' => $p, 'template' => Template::fromProfile($p)]),
        ]);
    }

    public function create(Request $request): View
    {
        return view('attendance.templates.create', [
            'template' => $request->query('template') ? ImportProfile::findOrFail($request->query('template')) : null,
            'maxMb' => config('ocr.upload.max_mb'),
        ]);
    }

    public function sample(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:'.ImportFiles::EXTENSIONS, 'max:'.(config('ocr.upload.max_mb') * 1024)],
            'template' => ['nullable', 'exists:import_profiles,id'],
        ], [], ['file' => __('file')]);

        $key = $this->files->store($request, $request->file('file'));

        return redirect()->route('templates.design', ['key' => $key, 'template' => $request->input('template')]);
    }

    public function design(Request $request, string $key, TemplateDesigner $designer): View
    {
        [$path, $fileName] = $this->files->resolve($request, $key);
        $analysis = $designer->analyse($path, $fileName, $request->integer('header_row') ?: null);

        // Editing a template: its settings replace the suggestions where the sample has the same columns.
        $existing = $request->query('template') ? ImportProfile::find($request->query('template')) : null;
        $settings = $existing ? $this->merge($analysis['settings'], $existing) : $analysis['settings'];

        return $this->form($key, $fileName, $analysis, $settings, $existing, $existing?->name ?? $this->suggestName($fileName));
    }

    public function save(Request $request, string $key, TemplateDesigner $designer, AttendanceImporter $importer): View|RedirectResponse
    {
        [$path, $fileName] = $this->files->resolve($request, $key);
        $analysis = $designer->analyse($path, $fileName, (int) $request->input('header_row') ?: null);
        $existing = $request->input('template_id') ? ImportProfile::find($request->input('template_id')) : null;
        $profile = $designer->fromForm($request->all(), $analysis['kind'], $analysis['anchors']);

        if ($request->input('action') === 'save') {
            $request->validate([
                'name' => ['required', 'string', 'max:100', Rule::unique('import_profiles', 'name')->ignore($existing?->id)],
            ], [], ['name' => __('Template name')]);
            if (! collect($profile->settings['columns'])->contains(fn ($c) => in_array($c['role'], ['work_date', 'work_day'], true))) {
                return back()->withInput()->withErrors(['columns' => __('Choose which column holds the date or the day of the month.')]);
            }

            $saved = $existing ?? new ImportProfile;
            $saved->fill($profile->only(['name', 'kind', 'header_row', 'settings', 'match']))->save();

            if ($request->session()->pull('template-return') === $key) {
                return redirect()->route('attendance.preview', ['key' => $key, 'profile' => $saved->id])
                    ->with('status', __('Template saved. The file is read with it now.'));
            }
            $this->files->forget($request, $key);

            return redirect()->route('templates.index')->with('status', __('Template saved.'));
        }

        // Test: read the sample with the draft template and show the usual preview.
        $preview = null;
        $error = null;
        try {
            $preview = $importer->preview($path, $fileName, $profile, $request->input('test_name') ?: null);
        } catch (ProcessingException $e) {
            $error = "{$e->errorCode}: {$e->getMessage()}";
        }

        $settings = $this->settingsFromForm($request->all(), $analysis['settings']);

        return $this->form($key, $fileName, $analysis, $settings, $existing, (string) $request->input('name'), $preview, $error);
    }

    private function form(string $key, string $fileName, array $analysis, array $settings, ?ImportProfile $existing, string $name, $preview = null, ?string $error = null): View
    {
        return view('attendance.templates.design', [
            'key' => $key,
            'fileName' => $fileName,
            'analysis' => $analysis,
            'settings' => $settings,
            'existing' => $existing,
            'name' => $name,
            'preview' => $preview,
            'error' => $error,
        ]);
    }

    /** The posted form over the analysis (keeps the samples shown next to each column). */
    private function settingsFromForm(array $input, array $suggested): array
    {
        $columns = collect($input['columns'] ?? [])->keyBy('key');
        $settings = $suggested;
        $settings['columns'] = array_map(fn ($c) => $columns->has($c['key'])
            ? [...$c, ...array_intersect_key($columns[$c['key']], array_flip(['type', 'role', 'unit'])), 'include' => ! empty($columns[$c['key']]['include'])]
            : $c, $suggested['columns']);
        $summary = collect($input['summary'] ?? [])->values();
        $settings['summary'] = array_map(fn ($item, $i) => isset($summary[$i])
            ? [...$item, ...array_intersect_key($summary[$i], array_flip(['type', 'unit', 'role', 'title'])), 'include' => ! empty($summary[$i]['include'])]
            : $item, $suggested['summary'], array_keys($suggested['summary']));
        foreach (['year_cell', 'month_cell', 'name_source', 'name_cell', 'totals_label', 'title_marker', 'break_default'] as $field) {
            if (array_key_exists($field, $input)) {
                $settings[$field] = $input[$field];
            }
        }
        $settings['work_types'] = array_map(fn ($v) => $v === '' ? null : $v, $input['work_types'] ?? $suggested['work_types']);

        return $settings;
    }

    private function merge(array $suggested, ImportProfile $existing): array
    {
        $saved = $existing->settings ?? [];
        $byTitle = collect($saved['columns'] ?? [])->keyBy('title');
        $suggested['columns'] = array_map(fn ($c) => $byTitle->has($c['title'])
            ? [...$c, ...array_intersect_key($byTitle[$c['title']], array_flip(['type', 'role', 'unit'])), 'include' => true]
            : [...$c, 'include' => false], $suggested['columns']);
        foreach (['year_cell', 'month_cell', 'name_source', 'name_cell', 'totals_label', 'title_marker'] as $field) {
            $suggested[$field] = $saved[$field] ?? ($suggested[$field] ?? null);
        }
        $suggested['break_default'] = isset($saved['break_default']) ? TimeValue::format((int) $saved['break_default']) : null;
        $suggested['work_types'] = array_merge($suggested['work_types'] ?? [], $saved['work_types'] ?? []);

        return $suggested;
    }

    private function suggestName(string $fileName): string
    {
        $name = preg_replace('/^\d{6,8}_|[_（(].*$|【.*?】/u', '', pathinfo($fileName, PATHINFO_FILENAME));

        return trim($name) ?: pathinfo($fileName, PATHINFO_FILENAME);
    }
}
