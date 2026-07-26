<?php

use App\Modules\Variables\Http\Controllers\ConstantController;
use App\Modules\Variables\Http\Controllers\CustomFunctionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // CONSTANTS: user-created, workspace-scoped typed LITERAL constants exposed as `globals.<key>`
    // references in every workflow. A distinct path prefix from `workflows`, so no binding collision;
    // workspace-member read + creator-only mutation (ConstantPolicy). The route parameter is pinned to
    // `{constant}` (the apiResource default would singularize `consts` to the reserved word `const`).
    Route::apiResource('consts', ConstantController::class)
        ->parameters(['consts' => 'constant'])
        ->only(['index', 'show', 'store', 'update', 'destroy']);

    // CUSTOM FUNCTIONS (Phase 3a): user-created, workspace-scoped variable transforms (input + typed
    // args → return, over a saved body pipeline). Workspace-member read + creator-only mutation
    // (CustomFunctionPolicy). The route parameter is pinned to `{function}` to bind CustomFunction.
    Route::apiResource('functions', CustomFunctionController::class)
        ->parameters(['functions' => 'function'])
        ->only(['index', 'show', 'store', 'update', 'destroy']);
});
