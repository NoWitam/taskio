<?php

use App\Http\Middleware\RequireWorkspace;
use App\Modules\Disk\Http\Controllers\FilesController;
use Illuminate\Support\Facades\Route;

/**
 * Disk binaries are workspace-owned, so every route here is gated by RequireWorkspace:
 * without an active tenant the query would be unscoped and any authenticated user could
 * read another workspace's bytes by id.
 *
 * Throttled because the api group has no global rate limiter: these are the endpoints that
 * move (and accept) binaries, so they bound scraping and upload floods. The read limit is
 * deliberately generous — a browsing grid of lazy thumbnails is many reads per minute.
 *
 * The third throttle argument is the rate-limiter key PREFIX and it is LOAD-BEARING: without
 * it ThrottleRequests keys on the user alone (ThrottleRequests::handle -> `$prefix.$signature`),
 * so every `throttle:` route an authenticated user touches shares ONE counter — a thumbnail
 * grid would eat the upload budget and even 429 unrelated modules' throttled endpoints.
 * Distinct prefixes give each surface its own bucket.
 *
 * The browse/write half of the module (index/store/update/trash) lands in a later batch; it
 * used to be advertised by an apiResource whose controller methods never existed, so those
 * routes 500'd on dispatch instead of 404'ing.
 */
Route::middleware(['auth:sanctum', RequireWorkspace::class])
    ->prefix('disk')
    ->controller(FilesController::class)
    ->group(function () {
        // Literal segments before the {file} wildcard, so 'temp' is never read as an id.
        Route::post('/temp', 'uploadTemp')->middleware('throttle:60,1,disk-upload');

        Route::get('/{file}', 'show')->name('disk.show')->middleware('throttle:300,1,disk-read');
    });
