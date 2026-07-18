<?php

use App\Http\Middleware\RequireWorkspace;
use App\Modules\Disk\Http\Controllers\FilesController;
use App\Modules\Disk\Http\Controllers\FoldersController;
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
    ->group(function () {
        // Literal segments FIRST, so 'temp'/'folders' are never read as a {file} id. The
        // whereUuid below is the belt to this braces: with it, a literal segment could not
        // bind to {file} even if a future edit reordered these.
        Route::post('/temp', [FilesController::class, 'uploadTemp'])->middleware('throttle:60,1,disk-upload');

        // --- Folders (the tree) ---------------------------------------------------------
        Route::controller(FoldersController::class)->prefix('folders')->name('disk.folders.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            // Literal-then-wildcard again, one level down.
            Route::post('/{id}/restore', 'restore')->name('restore')->whereUuid('id');
            Route::post('/{folder}/move', 'move')->name('move')->whereUuid('folder');
            Route::get('/{folder}', 'show')->name('show')->whereUuid('folder');
            Route::patch('/{folder}', 'update')->name('update')->whereUuid('folder');
            Route::delete('/{folder}', 'destroy')->name('destroy')->whereUuid('folder');
        });

        // --- Files ------------------------------------------------------------------------
        Route::controller(FilesController::class)->group(function () {
            Route::get('/', 'index')->name('disk.index');
            Route::post('/', 'store')->name('disk.store')->middleware('throttle:60,1,disk-upload');

            // The read-only virtual "Zasoby" tree. Literal, so it never binds as a {file} id.
            Route::get('/resources', 'resources')->name('disk.resources');

            // Literal-then-wildcard: these must not be read as a {file} id.
            Route::get('/{id}/restore-preview', 'restorePreview')->name('disk.restore-preview')->whereUuid('id');
            Route::post('/{id}/restore', 'restore')->name('disk.restore')->whereUuid('id');
            Route::delete('/{id}/force', 'forceDestroy')->name('disk.force-destroy')->whereUuid('id');

            // "Pick from Disk": duplicate a LIVE file into the caller's temp. Uses {file}
            // model-binding (not the withTrashed {id} above) so a trashed/foreign source 404s
            // at bind, and throttled with the upload bucket since it writes a new blob.
            Route::post('/{file}/copy-to-temp', 'copyToTemp')
                ->name('disk.copy-to-temp')
                ->whereUuid('file')
                ->middleware('throttle:60,1,disk-upload');

            // "Copy" action: duplicate a LIVE file into the disk (new name + folder). Same
            // binding/throttle rationale as copy-to-temp (it writes a new blob).
            Route::post('/{file}/copy', 'copy')
                ->name('disk.copy')
                ->whereUuid('file')
                ->middleware('throttle:60,1,disk-upload');

            Route::get('/{file}', 'show')
                ->name('disk.show')
                ->whereUuid('file')
                ->middleware('throttle:300,1,disk-read');
            Route::patch('/{file}', 'update')->name('disk.update')->whereUuid('file');
            Route::delete('/{file}', 'destroy')->name('disk.destroy')->whereUuid('file');
        });
    });
