<?php

namespace App\Http\Middleware;

use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active workspace from the X-Workspace-Id header and validates
 * that the authenticated user is a member. Registered on the api group but
 * REORDERED (bootstrap/app.php `prependToPriorityList`) to run right BEFORE
 * SubstituteBindings — and, in the stock priority list, still after Authenticate
 * — so the workspace is active DURING route-model binding (tenant-scoped binds;
 * a foreign {model} id 404s at bind, not in the controller). When no user is
 * resolved (a public route without auth:sanctum), it no-ops so the scope stays
 * inert. That ordering invariant is pinned by a test.
 */
class ResolveWorkspace
{
    public function __construct(
        private TenantContext $context,
        private TenantManager $tenants,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $workspaceId = $request->header('X-Workspace-Id');

        // LOAD-BEARING ORDERING (do not move below TenantContext::set): the User↔token
        // morph MUST happen while no workspace is active, so the User WorkspaceMemberScope
        // stays inert (else the scope hides the just-authenticated user from their own
        // token morph and silently breaks auth). With the reorder this is doubly safe: the
        // morph already ran upstream in Authenticate:sanctum (earlier in the priority list);
        // this $request->user() is the resolved user, and set() below is still after it.
        $user = $request->user();

        if ($workspaceId && $user) {
            $workspace = Workspace::find($workspaceId);

            abort_unless(
                $workspace !== null && $workspace->hasMember($user),
                Response::HTTP_FORBIDDEN,
                'You do not have access to this workspace.'
            );

            $this->context->set($workspace);

            // Own-database workspaces query a dedicated connection.
            if ($this->context->isOwn()) {
                $this->tenants->configure($workspace);
            }
        } else {
            $this->context->clear();
        }

        return $next($request);
    }
}
