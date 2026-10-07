<?php

namespace App\Http\Controllers\Api;

use App\Attendance\AttendanceImporter;
use App\Attendance\NoTemplateMatches;
use App\Attendance\Template;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\ImportProfile;
use App\Processing\ProcessingException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    /**
     * POST /api/attendance/import (spec 2.3): file, profile_id, dry_run, replace, acknowledged.
     * With dry_run the preview (sheets, A-xx results) is returned and nothing is saved.
     */
    public function import(Request $request, AttendanceImporter $importer): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:pdf,csv,xlsx,xls', 'max:'.(config('ocr.upload.max_mb') * 1024)],
            'profile_id' => ['nullable', 'exists:import_profiles,id'],
        ]);
        $file = $request->file('file');
        $profile = $request->input('profile_id') ? ImportProfile::find($request->input('profile_id')) : null;

        try {
            $preview = $importer->preview($file->getRealPath(), $file->getClientOriginalName(), $profile, $request->input('employee_name'), $request->input('employee_code'));
        } catch (ProcessingException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
        } catch (NoTemplateMatches $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'NO-TEMPLATE'], 422);
        }

        if ($request->boolean('dry_run')) {
            return response()->json(['data' => $preview->toArray()]);
        }

        $document = $importer->import($preview, $file->getRealPath(), $file->getClientOriginalName(), $request->user(),
            $request->boolean('replace'), $request->boolean('acknowledged'));

        return response()->json([
            'data' => (new DocumentResource($document))->resolve(),
            'sheets' => $document->attendanceSheets->map->only(['id', 'employee_name', 'employee_code', 'worked_minutes', 'overtime_minutes']),
        ], 201);
    }

    public function profiles(): JsonResponse
    {
        return response()->json(['data' => ImportProfile::orderBy('name')->get()]);
    }

    public function storeProfile(Request $request): JsonResponse
    {
        $data = $request->validate(self::templateRules());

        return response()->json(['data' => ImportProfile::create($data)], 201);
    }

    public function updateProfile(Request $request, ImportProfile $profile): JsonResponse
    {
        $profile->update($request->validate(self::templateRules($profile)));

        return response()->json(['data' => $profile]);
    }

    /**
     * Templates through the API: kind, settings (columns, cells, units, work types…
     * as in App\Attendance\Template) and match; older column_map profiles too.
     */
    private static function templateRules(?ImportProfile $profile = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('import_profiles', 'name')->ignore($profile?->id)],
            'kind' => ['sometimes', Rule::in(['spreadsheet', 'pdf'])],
            'encoding' => ['sometimes', Rule::in(['auto', 'UTF-8', 'SJIS-win'])],
            'delimiter' => ['sometimes', 'string', 'size:1'],
            'header_row' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'column_map' => ['sometimes', 'nullable', 'array'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'settings.columns' => ['sometimes', 'array'],
            'settings.columns.*.title' => ['required_with:settings.columns', 'string', 'max:100'],
            'settings.columns.*.type' => ['nullable', Rule::in(Template::TYPES)],
            'settings.columns.*.role' => ['nullable', Rule::in(Template::ROLES)],
            'match' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
