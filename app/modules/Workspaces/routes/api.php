<?php

use App\Modules\Workspaces\Http\Controllers\WorkspaceAiUsageController;
use App\Modules\Workspaces\Http\Controllers\WorkspaceInvitationsController;
use App\Modules\Workspaces\Http\Controllers\WorkspacesController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('workspaces', WorkspacesController::class)->only([
        'index', 'store', 'show', 'update',
    ]);

    // AI cost usage (R2 sub-stage 4): the $-first summary (any member) + the monthly $ cap (owner only).
    // Dedicated sub-resource routes so the PATCH /workspaces/{id} rename stays thin.
    Route::get('workspaces/{workspace}/ai-usage', [WorkspaceAiUsageController::class, 'show']);
    Route::patch('workspaces/{workspace}/ai-usage/cap', [WorkspaceAiUsageController::class, 'updateCap']);

    Route::get('workspaces/{workspace}/members', [WorkspacesController::class, 'members']);
    Route::post('workspaces/{workspace}/members', [WorkspacesController::class, 'addMember']);
    Route::delete('workspaces/{workspace}/members/{user}', [WorkspacesController::class, 'removeMember']);

    // Invitation administration (workspace owner only — gated by manageMembers).
    Route::get('workspaces/{workspace}/invitations', [WorkspaceInvitationsController::class, 'index']);
    Route::post('workspaces/{workspace}/invitations', [WorkspaceInvitationsController::class, 'store']);
    Route::delete('workspaces/{workspace}/invitations/{invitation}', [WorkspaceInvitationsController::class, 'destroy']);
    Route::post('workspaces/{workspace}/invitations/{invitation}/resend', [WorkspaceInvitationsController::class, 'resend']);
});

// Public invitation endpoints. Registered OUTSIDE auth:sanctum and must NEVER
// return 401 (the SPA api client force-redirects to /login on any 401). Unknown
// tokens 404; terminal/expired states are 200 (valid:false) or structured 422.
Route::get('invitations/{token}', [WorkspaceInvitationsController::class, 'preview'])
    ->middleware('throttle:30,1');
Route::post('invitations/{token}/accept', [WorkspaceInvitationsController::class, 'accept'])
    ->middleware('throttle:10,1');
