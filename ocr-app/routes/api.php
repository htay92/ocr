<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EngineController;
use Illuminate\Support\Facades\Route;

// REST API (spec 2.3). Session or token auth (Sanctum); everything requires login (NFR-08).
Route::middleware('auth:sanctum')->group(function () {
    Route::get('engines', EngineController::class);

    Route::get('documents', [DocumentController::class, 'index']);
    Route::post('documents', [DocumentController::class, 'store']);
    Route::get('documents/{document}', [DocumentController::class, 'show']);
    Route::get('documents/{document}/runs', [DocumentController::class, 'runs']);
    Route::get('documents/{document}/file', [DocumentController::class, 'file']);
    Route::post('documents/{document}/rerun', [DocumentController::class, 'rerun']);
    Route::delete('documents/{document}', [DocumentController::class, 'destroy']);
    Route::put('documents/{document}', [DocumentController::class, 'update']);
    Route::post('documents/{document}/confirm', [DocumentController::class, 'confirm']);
    Route::post('documents/{document}/reopen', [DocumentController::class, 'reopen']);

    Route::post('attendance/import', [AttendanceController::class, 'import']);
    Route::get('import-profiles', [AttendanceController::class, 'profiles']);
    Route::post('import-profiles', [AttendanceController::class, 'storeProfile']);
    Route::put('import-profiles/{profile}', [AttendanceController::class, 'updateProfile']);
});
