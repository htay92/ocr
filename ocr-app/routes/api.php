<?php

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
});
