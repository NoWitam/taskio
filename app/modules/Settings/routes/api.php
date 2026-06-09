<?php

use App\Modules\Settings\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('settings/profile', [ProfileController::class, 'show']);
    Route::put('settings/profile', [ProfileController::class, 'update']);
    Route::put('settings/password', [ProfileController::class, 'updatePassword']);
});
