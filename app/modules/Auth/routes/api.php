<?php

use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\GroupsController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
});

// Group & permission management (workspace owner only — enforced in policy).
Route::get('workspaces/{workspace}/groups', [GroupsController::class, 'index']);
Route::post('workspaces/{workspace}/groups', [GroupsController::class, 'store']);
Route::put('workspaces/{workspace}/groups/{group}', [GroupsController::class, 'update']);
Route::delete('workspaces/{workspace}/groups/{group}', [GroupsController::class, 'destroy']);
