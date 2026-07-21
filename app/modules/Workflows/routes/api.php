<?php

use App\Modules\Workflows\Http\Controllers\WorkflowController;
use App\Modules\Workflows\Http\Controllers\WorkflowRunController;
use App\Modules\Workflows\Http\Controllers\WorkflowScheduleAssistController;
use App\Modules\Workflows\Http\Controllers\WorkflowSchedulePreviewController;
use App\Modules\Workflows\Http\Controllers\WorkflowVariableCatalogController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // LIVE SCHEDULE PREVIEW: projects the next N fire instants of a proposed cadence so the FE
    // schedule builder shows a running preview. A static /meta path, declared before the {workflow}
    // resource so it never binds as an id. Any member may call it; it validates the block with the
    // shared rules but with the empty-schedule guard OFF (emptiness comes back as data, not a 422).
    // Throttled: the projection loop is CPU-bound (worst case ~10 years of candidates per
    // occurrence), so the rate is capped per user on top of the debounced FE.
    Route::post('workflows/meta/schedule-preview', WorkflowSchedulePreviewController::class)
        ->middleware('throttle:60,1')
        ->name('workflows.meta.schedule-preview');

    // AI SCHEDULE ASSIST: natural-language -> structured schedule config. A static path declared
    // before the {workflow} resource so it never binds as an id. Any member may call it; the
    // service throttles per user and re-validates whatever the model proposes.
    Route::post('workflows/schedule-assist', WorkflowScheduleAssistController::class)
        ->name('workflows.schedule-assist');

    // The TYPED variable catalog for a form_submitted workflow built on {form}: trigger system
    // vars + per-form field vars + step outputs, and the condition field descriptors. Owned by
    // the Workflows module (the catalog concept), binds a Form, authorized by FormPolicy::view.
    Route::get('forms/{form}/workflow-catalog', [WorkflowVariableCatalogController::class, 'show'])
        ->name('workflows.forms.catalog');

    // The FORM-INDEPENDENT variable catalog: STRUCTURAL metadata (trigger vars by `trigger_type` +
    // step outputs + operations + personas + variable types) with an OPTIONAL `form_id` that layers
    // in that form's field vars. A form-less call carries NO tenant rows, so it gates on
    // workspace-member read (viewAny); a form_id call authorizes FormPolicy::view (see the request).
    // A static `catalog` path declared BEFORE the {workflow} resource so it never binds as an id.
    Route::get('workflows/catalog', [WorkflowVariableCatalogController::class, 'index'])
        ->name('workflows.catalog');

    Route::post('workflows/{id}/restore', [WorkflowController::class, 'restore'])->name('workflows.restore');

    // Status toggle (active|inactive) — the only path that mutates a workflow's status.
    Route::patch('workflows/{workflow}/status', [WorkflowController::class, 'changeStatus'])->name('workflows.status');

    // Manual run — starts a run on demand (any workflow, even inactive). Batch 4 adds the run
    // index/show read-APIs.
    Route::post('workflows/{workflow}/run', [WorkflowController::class, 'run'])->name('workflows.run');

    // GLOBAL runs feed: every run in the active workspace (workspace-member read). DECLARED
    // BEFORE the apiResource `workflows/{workflow}` below so the static `runs` segment wins —
    // otherwise `GET workflows/runs` would bind {workflow}="runs" and hit WorkflowController@show
    // (Laravel matches routes in declaration order).
    Route::get('workflows/runs', [WorkflowRunController::class, 'global'])->name('workflows.runs.global');

    // Run monitoring (read-only): a workflow's runs list + a single run with its step timeline.
    // The controller enforces that {run} belongs to {workflow} (a foreign run 404s).
    Route::get('workflows/{workflow}/runs', [WorkflowRunController::class, 'index'])->name('workflows.runs.index');
    Route::get('workflows/{workflow}/runs/{run}', [WorkflowRunController::class, 'show'])->name('workflows.runs.show');

    // Retry a FAILED run: user-initiated re-execution that STARTS A NEW run for {workflow}
    // reusing {run}'s stored trigger_payload (the engine has no mid-run resume). Authorized like
    // run-now (WorkflowPolicy::run); {run} must belong to {workflow} (else 404); only a terminal
    // FAILED run is retryable (else 422); honors the same run budget as run-now.
    Route::post('workflows/{workflow}/runs/{run}/retry', [WorkflowRunController::class, 'retry'])->name('workflows.runs.retry');

    Route::apiResource('workflows', WorkflowController::class)
        ->only(['index', 'show', 'store', 'update', 'destroy']);
});
