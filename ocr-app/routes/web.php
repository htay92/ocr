<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DocumentController;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

// 日本語 / English switch, also on the login page.
Route::get('locale/{locale}', function (string $locale) {
    session(['locale' => $locale]);

    return back();
})->whereIn('locale', SetLocale::LOCALES)->name('locale');

// SC-01 Login
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:5,1');
});

// Everything else requires login (NFR-08).
Route::middleware('auth')->group(function () {
    Route::redirect('/', '/documents');
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/upload', [DocumentController::class, 'create'])->name('documents.create');
    Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('documents/{document}/file', [DocumentController::class, 'file'])->name('documents.file');
    Route::post('documents/{document}/rerun', [DocumentController::class, 'rerun'])->name('documents.rerun');
});
