<?php

use App\Http\Middleware\FlushChangelogMiddleware;
use App\Http\Middleware\LogMiddleware;
use App\Http\Middleware\RequireWorkspace;
use App\Http\Middleware\ResolveWorkspace;
use App\Http\Middleware\SetUserLocale;
use App\Modules\Publishing\Services\OAuthStateService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    // Broadcasting auth must authenticate the SPA the SAME way the API does — the `api` group
    // (Sanctum stateful) + auth:sanctum — otherwise the framework default (`web` guard) resolves no
    // user and /broadcasting/auth returns 403 for the private channel. ResolveWorkspace (in the api
    // group) no-ops without X-Workspace-Id, and the channel checks CENTRAL workspace membership, so
    // no tenant context is needed here; RequireWorkspace is route-scoped, so it is not applied.
    ->withBroadcasting(
        __DIR__ . '/../routes/channels.php',
        ['middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(LogMiddleware::class);
        $middleware->append(FlushChangelogMiddleware::class);
        $middleware->appendToGroup('api', ResolveWorkspace::class);

        // Makes the server answer in the LANGUAGE THE USER IS ACTUALLY LOOKING AT: the stored choice
        // first, then the locale the client says it is rendering (X-Client-Locale), then APP_LOCALE.
        // The column alone was not enough — the frontend resolves its own locale from localStorage or
        // the browser and only ever POSTs it on a deliberate switch, so a Polish interface got English
        // server prose from first login with no way to correct it from the UI.
        //
        // It reads $request->user(), so it must run AFTER Authenticate — the same dependency
        // ResolveWorkspace has, and the reason that one needed an explicit slot. This one does NOT:
        // it is absent from the framework's priority list, so the sort leaves it where the group put
        // it, which is after every prioritised middleware and therefore after Authenticate. Pinned by
        // UserLocaleTest, because "it happens to land late" is exactly the kind of guarantee that
        // quietly stops holding.
        //
        // Unlike ResolveWorkspace it carries no security weight — nothing about model binding depends
        // on the locale — so it needs no constraint against SubstituteBindings either.
        $middleware->appendToGroup('api', SetUserLocale::class);

        // SECURITY (cross-workspace binding): appendToGroup alone leaves ResolveWorkspace
        // running AFTER SubstituteBindings, so route-model binding happens while no workspace
        // is active — WorkspaceScope is inert and a {model} id from ANOTHER workspace binds
        // unscoped. Reorder ResolveWorkspace to run just BEFORE SubstituteBindings (still after
        // Authenticate, which stays earlier in the stock priority list — pinned by a test), so
        // tenancy is active during binding: shared-DB rows are WorkspaceScope-filtered and
        // own-DB rows resolve on the tenant connection. Foreign ids 404 at bind, app-wide.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveWorkspace::class,
        );

        // The OAuth handshake binding (R4 B2) crosses the two route groups, so it cannot be encrypted.
        // It is SET on the response of `POST /api/publishing/connections/{platform}/authorize` — the
        // `api` group, which has no cookie encryption — and READ on `GET /oauth/{platform}/callback`,
        // which is `web` and does. Without this exemption `EncryptCookies` would find a value it cannot
        // decrypt on the way in, null it, and every legitimate callback would be refused as
        // `oauth_browser_mismatch` — a failure whose cause is two route groups away from its symptom.
        //
        // THE VALUE IS A SECRET — 32 CSPRNG bytes — and exempting it is still right. This exemption's
        // first justification was that the value was a public nonce id with nothing to hide, and that
        // design was broken: a client holding the `state` could read the id out of it and set the cookie
        // itself. What encryption would buy even now is nothing, because the ciphertext would be exactly
        // as redeemable as the plaintext to anybody holding it; it defends against a client reading its
        // OWN cookie, which is not a threat. What protects this one is HttpOnly, SameSite=Lax, Secure
        // wherever the session is, single use, and a ten-minute life.
        // See App\Modules\Publishing\Services\OAuthStateService.
        $middleware->encryptCookies(except: [
            OAuthStateService::HANDSHAKE_COOKIE,
        ]);

        // Route-scoped fail-closed tenancy gate (attached per route, e.g. the Disk binaries).
        // Slotted between the two above — after ResolveWorkspace has filled the context, still
        // before SubstituteBindings — so a context-less request is refused BEFORE a foreign id
        // can bind. Both orderings are pinned by ApiMiddlewarePriorityTest.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: RequireWorkspace::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
