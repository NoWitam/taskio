<?php

use App\Http\Middleware\RequireWorkspace;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Http\Controllers\PlatformConnectionController;
use App\Modules\Publishing\Http\Controllers\PublicationController;
use Illuminate\Support\Facades\Route;

/**
 * Publishing API.
 *
 * Every route is gated by RequireWorkspace, not merely by auth — the same rule the Calendar's routes
 * follow, with a sharper consequence. A publication belongs to a workspace's ACCOUNTS: without an
 * active tenant the model's workspace scope goes inert, and a write could reach a row belonging to
 * somebody else's connection. RequireWorkspace refuses that with a 400 before anything is looked up,
 * and ResolveWorkspace has already refused a workspace the caller is not a member of with a 403.
 *
 * `counts` sits at the group root rather than under `publications/`, so no ordering trick is needed to
 * keep it from being read as an id — and every `{publication}` segment is uuid-constrained besides.
 */
Route::middleware(['auth:sanctum', RequireWorkspace::class])
    ->prefix('publishing')
    ->group(function () {
        // The badge and the tabs. Answers for every status at once — see PublicationService::counts().
        Route::get('counts', [PublicationController::class, 'counts'])
            ->name('publishing.counts');

        Route::get('publications', [PublicationController::class, 'index'])
            ->name('publishing.publications.index');
        Route::post('publications', [PublicationController::class, 'store'])
            ->name('publishing.publications.store');
        Route::get('publications/{publication}', [PublicationController::class, 'show'])
            ->whereUuid('publication')
            ->name('publishing.publications.show');
        Route::put('publications/{publication}', [PublicationController::class, 'update'])
            ->whereUuid('publication')
            ->name('publishing.publications.update');
        Route::delete('publications/{publication}', [PublicationController::class, 'destroy'])
            ->whereUuid('publication')
            ->name('publishing.publications.destroy');

        // THE ONE TRANSITION A PERSON MAKES IN B1. A POST rather than a field on the update payload,
        // because arming is not editing — see SchedulePublicationRequest.
        Route::post('publications/{publication}/schedule', [PublicationController::class, 'schedule'])
            ->whereUuid('publication')
            ->name('publishing.publications.schedule');

        // ── CONNECTIONS (B2) ──────────────────────────────────────────────────────────────────────
        //
        // The AUTHENTICATED half of the OAuth surface. Its other half — GET /oauth/{platform}/callback
        // — is in routes/web.php and is deliberately NOT in this group: a browser redirect from Google
        // or Meta carries neither a bearer token nor X-Workspace-Id, so `auth:sanctum` would 401 every
        // legitimate callback and RequireWorkspace would 400 it first. The workspace travels in the
        // signed `state` instead. See PlatformOAuthCallbackController.
        //
        // Nothing here ever answers with a token. `PlatformConnectionResource` lists what it emits and
        // the model declares both credential columns `$hidden` — two independent guards, so the mistake
        // has to be made twice.
        Route::get('connections', [PlatformConnectionController::class, 'index'])
            ->name('publishing.connections.index');

        // MINTS a single-use nonce, so it is a POST: a GET that spends one is a GET a browser may make
        // on its own during a prefetch. The destination is constrained to the enum here, so an
        // unrecognised word is a 404 at routing and never reaches a FormRequest — what the request does
        // handle is a destination that exists and cannot be connected (`dry_run`), or one this
        // installation has no credentials for.
        Route::post('connections/{platform}/authorize', [PlatformConnectionController::class, 'authorizeConnection'])
            ->whereIn('platform', PublishingPlatform::values())
            ->name('publishing.connections.authorize');

        Route::delete('connections/{connection}', [PlatformConnectionController::class, 'destroy'])
            ->whereUuid('connection')
            ->name('publishing.connections.destroy');
    });
