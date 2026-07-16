<?php

namespace Tests\Feature;

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
