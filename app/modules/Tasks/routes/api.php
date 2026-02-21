<?php

use App\Modules\Tasks\Http\Controllers\TasksController;
use Illuminate\Support\Facades\Route;

Route::apiResource('tasks', TasksController::class);
Route::patch('tasks/{task}/status/{status}', [TasksController::class, 'changeStatus'])->name('tasks.change-status');
Route::delete('tasks/{task}/force', [TasksController::class, 'forceDestroy'])->name('tasks.force-destroy');
Route::post('tasks/{id}/restore', [TasksController::class, 'restore'])->name('tasks.restore');