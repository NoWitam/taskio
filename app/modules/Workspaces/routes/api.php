<?php

use App\Modules\Workspaces\Http\Controllers\WorkspacesController;
use Illuminate\Support\Facades\Route;

Route::apiResource('workspaces', WorkspacesController::class)->only([
    'index', 'store', 'show', 'update',
]);

Route::post('workspaces/{workspace}/members', [WorkspacesController::class, 'addMember']);
Route::delete('workspaces/{workspace}/members/{user}', [WorkspacesController::class, 'removeMember']);
