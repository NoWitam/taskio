<?php

use App\Http\Middleware\FlushChangelogMiddleware;
use App\Http\Middleware\LogMiddleware;
use App\Http\Middleware\RequireWorkspace;
use App\Http\Middleware\ResolveWorkspace;
use App\Http\Middleware\SetUserLocale;
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

        // Makes the server answer in the LANGUAGE THE USER CHOSE. `users.locale` was persisted and
        // never read back, so every `__()` rendered in APP_LOCALE — invisible on a Polish install
        // until somebody switched the interface to English and got Polish sentences from the API.
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
