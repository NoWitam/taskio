<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed workspace gate for routes that must NEVER run without an active tenant.
 *
 * ResolveWorkspace deliberately no-ops when the X-Workspace-Id header is absent (console,
 * queue, login and other pre-workspace paths depend on that pass-through), which leaves
 * WorkspaceScope inert — so on a tenant-owned route an authenticated user could omit the
 * header and reach ANOTHER workspace's row by id. That is the whole exposure this gate
 * closes for the Disk binaries.
 *
 * It is ROUTE-scoped on purpose: making the scope itself fail-closed globally would break
 * every legitimate no-context path. Registered in the middleware priority list right after
 * ResolveWorkspace (which fills the context) and before SubstituteBindings, so the refusal
 * happens BEFORE a foreign id is ever bound — the ordering is pinned by
 * {@see \Tests\Feature\ApiMiddlewarePriorityTest}.
 *
 * 400 (not 404): nothing is looked up before this check, so the answer reveals nothing about
 * whether the id exists — it only states that the request lacks the context it must carry.
 */
class RequireWorkspace
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            $this->context->hasWorkspace(),
            Response::HTTP_BAD_REQUEST,
            'This request requires an active workspace (X-Workspace-Id).'
        );

        return $next($request);
    }
}
