<?php

namespace App\Http\Controllers;

use App\Models\ImportProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Column mapping profiles for CSV and Excel attendance exports (SC-06, spec 3.7).
 */
class ImportProfileController extends Controller
{
    public function index(): View
    {
        return view('attendance.profiles.index', ['profiles' => ImportProfile::orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('attendance.profiles.form', ['profile' => new ImportProfile(ImportProfile::default()->only(['encoding', 'delimiter', 'header_row', 'column_map']))]);
    }

    public function store(Request $request): RedirectResponse
    {
        ImportProfile::create($this->validated($request));

        return redirect()->route('profiles.index')->with('status', __('Profile saved.'));
    }

    public function edit(ImportProfile $profile): View
    {
        return view('attendance.profiles.form', ['profile' => $profile]);
    }

    public function update(Request $request, ImportProfile $profile): RedirectResponse
    {
        $profile->update($this->validated($request, $profile));

        return redirect()->route('profiles.index')->with('status', __('Profile saved.'));
    }

    public static function rules(?ImportProfile $profile = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('import_profiles', 'name')->ignore($profile?->id)],
            'encoding' => ['required', Rule::in(['auto', 'UTF-8', 'SJIS-win'])],
            // A tab is sent as "tab": Laravel's required rule treats whitespace as empty.
            'delimiter' => ['required', Rule::in([',', ';', 'tab', '|'])],
            'header_row' => ['required', 'integer', 'min:1', 'max:50'],
            'column_map' => ['required', 'array'],
            'column_map.work_date' => ['required', 'string', 'max:100'],
            'column_map.*' => ['nullable', 'string', 'max:100'],
        ];
    }

    private function validated(Request $request, ?ImportProfile $profile = null): array
    {
        $data = self::normalize($request->validate(self::rules($profile), [], ['column_map.work_date' => '日付']));

        return $data;
    }

    /** Store a tab delimiter as a tab, and only the mapped system fields. */
    public static function normalize(array $data): array
    {
        $data['delimiter'] = $data['delimiter'] === 'tab' ? "\t" : $data['delimiter'];
        $data['column_map'] = array_filter(
            array_intersect_key($data['column_map'], array_flip(ImportProfile::FIELDS)),
            fn ($value) => $value !== null && $value !== '',
        );

        return $data;
    }
}
