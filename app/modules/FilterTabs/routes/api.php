<?php

use App\Modules\FilterTabs\Http\Controllers\FilterTabsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::put('filter-tabs/reorder', [FilterTabsController::class, 'reorder']);

    Route::apiResource('filter-tabs', FilterTabsController::class)
        ->only(['index', 'store', 'update', 'destroy']);
});
