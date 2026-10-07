<?php

namespace App\Http\Controllers\Api;

use App\Attendance\AttendanceImporter;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ImportProfileController;
use App\Http\Resources\DocumentResource;
use App\Models\ImportProfile;
use App\Processing\ProcessingException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            $preview = $importer->preview($file->getRealPath(), $file->getClientOriginalName(), $profile);
        } catch (ProcessingException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], 422);
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
        $data = ImportProfileController::normalize($request->validate(ImportProfileController::rules()));

        return response()->json(['data' => ImportProfile::create($data)], 201);
    }

    public function updateProfile(Request $request, ImportProfile $profile): JsonResponse
    {
        $profile->update(ImportProfileController::normalize($request->validate(ImportProfileController::rules($profile))));

        return response()->json(['data' => $profile]);
    }
}
