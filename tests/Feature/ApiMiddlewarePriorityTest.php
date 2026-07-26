<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireWorkspace;
use App\Http\Middleware\ResolveWorkspace;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Tests\TestCase;

/**
 * ORDERING PIN for the cross-workspace binding fix.
 *
 * The workspace-scoped route-model binding guarantee (CrossWorkspaceBindingTest) rests on ONE
 * invariant in the sorted api middleware for a bound-{model} route:
 *
 *     Authenticate  <  ResolveWorkspace  <  SubstituteBindings
 *
 * - Authenticate before ResolveWorkspace: ResolveWorkspace reads $request->user() to validate
 *   membership, so the user must already be resolved.
 * - ResolveWorkspace before SubstituteBindings: the tenant must be active DURING binding so
 *   WorkspaceScope filters a foreign {model} id to a 404 at bind (the whole security fix).
 *
 * This resolves the ACTUAL gathered + priority-sorted middleware the router runs for a real
 * bound route (Router::gatherRouteMiddleware applies the middleware priority via SortedMiddleware),
 * so any future priority-list edit that reopens the gap fails loudly here.
 */
class ApiMiddlewarePriorityTest extends TestCase
{
    public function test_resolve_workspace_binds_after_auth_and_before_substitute_bindings(): void
    {
        $router = app(Router::class);

        // A concrete bound-{workflow} route on the api group with auth:sanctum — the exact shape
        // the fix protects.
        $route = $router->getRoutes()->getByName('workflows.show');
        $this->assertNotNull($route, 'Expected a bound-{workflow} route named workflows.show to exist.');

        $middleware = $router->gatherRouteMiddleware($route);

        $auth = $this->indexOf($middleware, Authenticate::class);
        $resolve = $this->indexOf($middleware, ResolveWorkspace::class);
        $bindings = $this->indexOf($middleware, SubstituteBindings::class);

        $this->assertNotNull($auth, 'Authenticate must be present in the route middleware. Got: ' . implode(', ', $middleware));
        $this->assertNotNull($resolve, 'ResolveWorkspace must be present in the route middleware. Got: ' . implode(', ', $middleware));
        $this->assertNotNull($bindings, 'SubstituteBindings must be present in the route middleware. Got: ' . implode(', ', $middleware));

        $this->assertTrue(
            $auth < $resolve,
            "Authenticate (#{$auth}) must run BEFORE ResolveWorkspace (#{$resolve}) — ResolveWorkspace reads the resolved user.\nOrder: " . implode(', ', $middleware),
        );

        $this->assertTrue(
            $resolve < $bindings,
            "ResolveWorkspace (#{$resolve}) must run BEFORE SubstituteBindings (#{$bindings}) — else binding happens with no active tenant and a foreign {model} id leaks.\nOrder: " . implode(', ', $middleware),
        );
    }

    /**
     * The Disk binaries add a second invariant on top of the first:
     *
     *     ResolveWorkspace  <  RequireWorkspace  <  SubstituteBindings
     *
     * - ResolveWorkspace before RequireWorkspace: the gate asserts the context the resolver fills.
     * - RequireWorkspace before SubstituteBindings: a context-less request must be refused BEFORE
     *   an unscoped foreign {file} id can bind (route-scoped, since the resolver's no-context
     *   pass-through is load-bearing everywhere else).
     */
    public function test_require_workspace_gates_disk_routes_after_resolve_and_before_bindings(): void
    {
        $router = app(Router::class);

        $route = $router->getRoutes()->getByName('disk.show');
        $this->assertNotNull($route, 'Expected a bound-{file} route named disk.show to exist.');

        $middleware = $router->gatherRouteMiddleware($route);

        $resolve = $this->indexOf($middleware, ResolveWorkspace::class);
        $require = $this->indexOf($middleware, RequireWorkspace::class);
        $bindings = $this->indexOf($middleware, SubstituteBindings::class);

        $this->assertNotNull($require, 'RequireWorkspace must gate the disk routes. Got: ' . implode(', ', $middleware));
        $this->assertNotNull($resolve, 'ResolveWorkspace must be present. Got: ' . implode(', ', $middleware));
        $this->assertNotNull($bindings, 'SubstituteBindings must be present. Got: ' . implode(', ', $middleware));

        $this->assertTrue(
            $resolve < $require,
            "ResolveWorkspace (#{$resolve}) must run BEFORE RequireWorkspace (#{$require}) — the gate reads the context the resolver sets.\nOrder: " . implode(', ', $middleware),
        );

        $this->assertTrue(
            $require < $bindings,
            "RequireWorkspace (#{$require}) must run BEFORE SubstituteBindings (#{$bindings}) — else a context-less request binds a foreign file before the refusal.\nOrder: " . implode(', ', $middleware),
        );
    }

    /**
     * First index of the given middleware class in the gathered list. Entries are class-name
     * strings, possibly parameterised (e.g. `auth:sanctum` resolves to `Authenticate:sanctum`),
     * so match either the bare class or the `Class:params` prefix.
     *
     * @param  array<int, string>  $middleware
     */
    private function indexOf(array $middleware, string $class): ?int
    {
        foreach ($middleware as $i => $name) {
            if ($name === $class || str_starts_with($name, $class . ':')) {
                return $i;
            }
        }

        return null;
    }
}
