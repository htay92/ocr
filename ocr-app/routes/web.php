<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceTemplateController;
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
    Route::get('documents/{document}/runs/{run}/pages/{page}', [DocumentController::class, 'page'])->whereNumber('page')->name('documents.page');
    Route::post('documents/{document}/rerun', [DocumentController::class, 'rerun'])->name('documents.rerun');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::put('documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
    Route::post('documents/{document}/confirm', [DocumentController::class, 'confirm'])->name('documents.confirm');
    Route::post('documents/{document}/reopen', [DocumentController::class, 'reopen'])->name('documents.reopen');

    // SC-06 attendance import
    Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('attendance/import', [AttendanceController::class, 'create'])->name('attendance.create');
    Route::post('attendance/import', [AttendanceController::class, 'upload'])->name('attendance.upload');
    Route::get('attendance/import/{key}', [AttendanceController::class, 'preview'])->name('attendance.preview');
    Route::post('attendance/import/{key}', [AttendanceController::class, 'import'])->name('attendance.import');
    Route::post('attendance/import/{key}/cancel', [AttendanceController::class, 'cancel'])->name('attendance.cancel');

    // Attendance templates (setting page): one per company format, made from a sample file.
    Route::get('attendance/templates', [AttendanceTemplateController::class, 'index'])->name('templates.index');
    Route::get('attendance/templates/new', [AttendanceTemplateController::class, 'create'])->name('templates.create');
    Route::post('attendance/templates/sample', [AttendanceTemplateController::class, 'sample'])->name('templates.sample');
    Route::get('attendance/templates/design/{key}', [AttendanceTemplateController::class, 'design'])->name('templates.design');
    Route::post('attendance/templates/design/{key}', [AttendanceTemplateController::class, 'save'])->name('templates.save');
});
