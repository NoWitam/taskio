<?php

use App\Modules\Forms\Http\Controllers\FormsController;
use App\Modules\Forms\Http\Controllers\FormSubmissionsController;
use Illuminate\Support\Facades\Route;

Route::apiResource('forms', FormsController::class);
Route::delete('forms/{form}/force', [FormsController::class, 'forceDestroy'])->name('forms.force-destroy');
Route::post('forms/{id}/restore', [FormsController::class, 'restore'])->name('forms.restore');

Route::get('forms/{form}/submissions', [FormSubmissionsController::class, 'indexByForm'])->name('form-submissions.index-by-form');
Route::post('form-submissions', [FormSubmissionsController::class, 'store'])->name('form-submissions.store');
Route::get('form-submissions/{submission}', [FormSubmissionsController::class, 'show'])->name('form-submissions.show');
Route::put('form-submissions/{submission}', [FormSubmissionsController::class, 'update'])->name('form-submissions.update');
