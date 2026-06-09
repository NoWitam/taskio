<?php

use App\Modules\Approvals\Http\Controllers\ApprovalPipelinesController;
use App\Modules\Approvals\Http\Controllers\ApprovalsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Pipeline CRUD
    Route::resource('approval-pipelines', ApprovalPipelinesController::class)
        ->only(['index', 'show', 'store', 'update', 'destroy'])
        ->parameter('approval-pipelines', 'pipeline');

    // Approval queue & decisions
    Route::get('approvals/queue', [ApprovalsController::class, 'queue']);
    Route::get('approvals/queue/count', [ApprovalsController::class, 'queueCount']);
    Route::get('approvals/runs/{runId}', [ApprovalsController::class, 'runHistory']);
    Route::get('approvals/processes/{process}', [ApprovalsController::class, 'show']);
    Route::post('approvals/processes/{process}/decide', [ApprovalsController::class, 'decide']);
});
