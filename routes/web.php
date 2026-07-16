<?php

use App\Http\Controllers\AppController;
use Illuminate\Support\Facades\Route;

// Home → the "next" app (the legacy SPA at /app has been decommissioned).
Route::get('/', function () {
    return redirect('/next');
});

// Old legacy entry: keep the path alive for existing bookmarks by redirecting
// into the next app (preserving whatever sub-path was requested).
Route::get('/app{path?}', function (string $path = '') {
    return redirect('/next' . $path);
})->where('path', '.*');

// The "next" SPA — the application shell.
Route::get('/next{path?}', [AppController::class, 'next'])
    ->where('path', '.*')
    ->name('next');
