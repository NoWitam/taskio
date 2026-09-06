<?php

use App\Http\Controllers\AppController;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Http\Controllers\PlatformOAuthCallbackController;
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

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// PUBLISHING — the OAuth return leg (R4 B2).
// ─────────────────────────────────────────────────────────────────────────────────────────────────
// IT IS A `web` ROUTE, AND EVERY PART OF THAT IS DELIBERATE.
//
// This is where Google and Meta send the browser after a consent screen. It is a top-level navigation,
// not an XHR, so it carries NO Authorization header (the SPA attaches its bearer token in JavaScript,
// which is not running during a cross-origin redirect) and NO X-Workspace-Id. Putting it in the `api`
// group would mean `auth:sanctum` 401ing every legitimate callback, and `ResolveWorkspace` finding no
// workspace to resolve. Both facts arrive instead inside the SIGNED `state` parameter, which is what
// `OAuthStateService` exists to mint and redeem.
//
// IT MUST STAY ABOVE THE `/next{path?}` CATCH-ALL. That route matches `.*` under one prefix, so it
// would not in fact swallow `/oauth/...` today — but the ordering is the guarantee rather than the
// prefix, and the day somebody widens the SPA route is the day this one silently starts rendering an
// application shell to a platform's redirect, storing nothing and reporting success.
//
// The `{platform}` segment is constrained to the enum, and the controller re-checks it against the
// platform named inside the signed state: a nonce minted for one destination cannot be redeemed at
// another's callback.
Route::get('/oauth/{platform}/callback', PlatformOAuthCallbackController::class)
    ->whereIn('platform', PublishingPlatform::values())
    ->name('publishing.oauth.callback');

// The "next" SPA — the application shell.
Route::get('/next{path?}', [AppController::class, 'next'])
    ->where('path', '.*')
    ->name('next');
