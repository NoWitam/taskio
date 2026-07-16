<?php

use App\Modules\Disk\Http\Controllers\FilesController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('disk', FilesController::class)
        ->parameters(['disk' => 'file']);

    Route::prefix('disk')->controller(FilesController::class)->group(function () {
        Route::post('/temp', 'uploadTemp');
    });
});
