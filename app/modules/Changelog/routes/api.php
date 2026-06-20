<?php

use App\Modules\Changelog\Http\Controllers\ChangelogController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('{module}/{id}/changelog', [ChangelogController::class, 'index'])->name('changelog.index');
});
