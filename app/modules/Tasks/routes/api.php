<?php

use App\Modules\Tasks\Http\Controllers\TasksController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Registered BEFORE the apiResource so 'counts' is not captured as {task} by the
    // wildcard show route (GET tasks/{task}).
    Route::get('tasks/counts', [TasksController::class, 'counts'])->name('tasks.counts');
    Route::apiResource('tasks', TasksController::class);
    Route::patch('tasks/{task}/status/{status}', [TasksController::class, 'changeStatus'])->name('tasks.change-status');
    Route::delete('tasks/{task}/attachments/{file}', [TasksController::class, 'removeAttachment'])->name('tasks.attachments.destroy');
    Route::delete('tasks/{task}/force', [TasksController::class, 'forceDestroy'])->name('tasks.force-destroy');
    Route::post('tasks/{id}/restore', [TasksController::class, 'restore'])->name('tasks.restore');
    Route::post('tasks/{task}/form-submission', [TasksController::class, 'submitForm'])->name('tasks.submit-form');
});
