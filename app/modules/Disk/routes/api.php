<?php

use App\Http\Middleware\RequireWorkspace;
use App\Modules\Disk\Http\Controllers\AiImageController;
use App\Modules\Disk\Http\Controllers\AiTextController;
use App\Modules\Disk\Http\Controllers\DraftController;
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

        // AI image edit for the preview editor. ASYNC (canvas in, a status id out): POST queues an
        // edit under its own TIGHT throttle bucket — these are long, provider-billed calls, so the
        // prefix keeps the spend cap from sharing a counter with uploads/reads — and GET polls it on
        // the read bucket (the poll loop hammers it like the thumbnail grid). Literal `ai/image`, so
        // neither is ever read as a {file} id.
        Route::post('/ai/image', [AiImageController::class, 'store'])
            ->name('disk.ai-image')
            ->middleware('throttle:10,1,disk-ai');
        Route::get('/ai/image/{diskAiEdit}', [AiImageController::class, 'show'])
            ->name('disk.ai-image.show')
            ->whereUuid('diskAiEdit')
            ->middleware('throttle:300,1,disk-read');

        // AI TEXT edit for the preview editor. SYNC (text in, edited text out): gpt-4o text edits are
        // fast, so unlike the image edit this is NOT queued — it applies an instruction to the posted
        // content and returns the result inline. Shares the image edit's TIGHT `disk-ai` bucket (both
        // are billed provider calls). Literal `ai/text`, so it is never read as a {file} id.
        Route::post('/ai/text', [AiTextController::class, 'store'])
            ->name('disk.ai-text')
            ->middleware('throttle:10,1,disk-ai');

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

            // The unified folder browse: subfolders + files as ONE cursor-paginated list. Literal
            // `items`, so it never binds as a {file} id; the folder rides the PATH (not a query).
            Route::get('/items', 'items')->name('disk.items');
            Route::get('/items/{folder}', 'items')->name('disk.items.folder')->whereUuid('folder');

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

            // Metadata as JSON (the preview's deep-link/refresh source) — the bare GET /{file}
            // below serves the BINARY, so this rides a literal suffix. Read-bucket throttled:
            // preview prev/next hammers it like the thumbnail grid.
            Route::get('/{file}/info', 'info')
                ->name('disk.info')
                ->whereUuid('file')
                ->middleware('throttle:300,1,disk-read');

            // A small first-page PNG raster of a PDF (the grid's real thumbnail). Literal suffix like
            // /info so it never binds as a {file} id and never shadows the bare GET /{file} binary
            // serve. Read-bucket throttled: a browsing grid lazy-loads one per PDF tile.
            Route::get('/{file}/thumbnail', 'thumbnail')
                ->name('disk.thumbnail')
                ->whereUuid('file')
                ->middleware('throttle:300,1,disk-read');

            // Overwrite the file's CONTENT (preview editor "Zapisz"). POST, not PUT — PHP only
            // parses multipart bodies on POST. Upload-bucket throttled (it writes a new blob).
            Route::post('/{file}/content', 'replaceContent')
                ->name('disk.replace-content')
                ->whereUuid('file')
                ->middleware('throttle:60,1,disk-upload');

            Route::get('/{file}', 'show')
                ->name('disk.show')
                ->whereUuid('file')
                ->middleware('throttle:300,1,disk-read');
            Route::patch('/{file}', 'update')->name('disk.update')->whereUuid('file');
            Route::delete('/{file}', 'destroy')->name('disk.destroy')->whereUuid('file');
        });

        // --- Per-user edit drafts (autosave) ---------------------------------------------
        // The preview editor autosaves its in-progress state here so a refresh/crash never loses
        // work; the MAIN file is overwritten only on explicit Save. Every route is a literal `draft`
        // suffix on {file} (so it never binds as a bare {file} id), authorizes `view` like disk.info,
        // and is scoped to the auth user inside the controller — one member sees only their OWN draft.
        // POST/DELETE ride the upload bucket (they write blobs); GET/base ride the generous read
        // bucket (reopening a large image draft fetches several bases). The upsert is a plain POST
        // (not PUT) — like disk.replace-content, because PHP only parses a multipart body on POST.
        Route::controller(DraftController::class)->group(function () {
            Route::post('/{file}/draft', 'store')
                ->name('disk.draft.store')
                ->whereUuid('file')
                ->middleware('throttle:60,1,disk-upload');
            Route::get('/{file}/draft', 'show')
                ->name('disk.draft.show')
                ->whereUuid('file')
                ->middleware('throttle:300,1,disk-read');
            Route::get('/{file}/draft/base/{baseId}', 'base')
                ->name('disk.draft.base')
                ->whereUuid('file')
                ->whereNumber('baseId')
                ->middleware('throttle:300,1,disk-read');
            Route::delete('/{file}/draft', 'destroy')
                ->name('disk.draft.destroy')
                ->whereUuid('file')
                ->middleware('throttle:60,1,disk-upload');
        });
    });
