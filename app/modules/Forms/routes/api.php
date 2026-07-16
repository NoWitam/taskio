<?php

use App\Modules\Forms\Http\Controllers\FormReportsController;
use App\Modules\Forms\Http\Controllers\FormsController;
use App\Modules\Forms\Http\Controllers\FormSubmissionsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('forms', FormsController::class);
    Route::delete('forms/{form}/force', [FormsController::class, 'forceDestroy'])->name('forms.force-destroy');
    Route::post('forms/{id}/restore', [FormsController::class, 'restore'])->name('forms.restore');
    Route::post('forms/{form}/enable', [FormsController::class, 'enable'])->name('forms.enable');
    Route::post('forms/{form}/disable', [FormsController::class, 'disable'])->name('forms.disable');
    Route::post('forms/{form}/index', [FormsController::class, 'indexForm'])->name('forms.index-form');
    Route::post('forms/{form}/unindex', [FormsController::class, 'unindex'])->name('forms.unindex');
    Route::post('forms/{form}/restore-index', [FormsController::class, 'restoreIndex'])->name('forms.restore-index');
    Route::get('forms/{form}/compatibility', [FormsController::class, 'compatibilityInfo'])->name('forms.compatibility');
    Route::get('forms/{form}/preview', [FormsController::class, 'preview'])->name('forms.preview');

    Route::get('forms/{form}/submissions', [FormSubmissionsController::class, 'indexByForm'])->name('form-submissions.index-by-form');
    Route::post('form-submissions', [FormSubmissionsController::class, 'store'])->name('form-submissions.store');
    Route::get('form-submissions/{submission}', [FormSubmissionsController::class, 'show'])->name('form-submissions.show');
    Route::put('form-submissions/{submission}', [FormSubmissionsController::class, 'update'])->name('form-submissions.update');
    Route::delete('form-submissions/{submission}', [FormSubmissionsController::class, 'destroy'])->name('form-submissions.destroy');
    Route::post('form-submissions/{id}/restore', [FormSubmissionsController::class, 'restore'])->name('form-submissions.restore');
    Route::delete('form-submissions/{id}/force', [FormSubmissionsController::class, 'forceDestroy'])->name('form-submissions.force-destroy');

    Route::get('forms/{form}/reports', [FormReportsController::class, 'indexByForm'])->name('form-reports.index-by-form');
    Route::post('form-reports', [FormReportsController::class, 'store'])->name('form-reports.store');
    Route::get('form-reports/{report}', [FormReportsController::class, 'show'])->name('form-reports.show');
    Route::delete('form-reports/{report}', [FormReportsController::class, 'destroy'])->name('form-reports.destroy');
    Route::post('form-reports/{id}/restore', [FormReportsController::class, 'restore'])->name('form-reports.restore');
    Route::delete('form-reports/{id}/force', [FormReportsController::class, 'forceDestroy'])->name('form-reports.force-destroy');
});
