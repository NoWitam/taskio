<?php

use App\Modules\Comments\Http\Controllers\CommentsController;
use Illuminate\Support\Facades\Route;

Route::prefix('{module}/{id}/comments')->group(function () {
    Route::get('/', [CommentsController::class, 'index'])->name('comments.index');
    Route::post('/', [CommentsController::class, 'store'])->name('comments.store');
});

Route::prefix('comments')->group(function () {
    Route::patch('{comment}', [CommentsController::class, 'update'])->name('comments.update');
    Route::delete('{comment}', [CommentsController::class, 'destroy'])->name('comments.destroy');
});
