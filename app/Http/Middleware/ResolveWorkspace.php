<?php

namespace App\Http\Middleware;

use App\Modules\Workspaces\Enums\WorkspaceStatus;
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

            // Membership is checked FIRST so this readiness answer never tells a stranger
            // anything about a workspace they cannot see.
            //
            // An own-database workspace is created `provisioning` and only becomes `ready`
            // once its dedicated database exists (ProvisionWorkspaceJob) — which may never
            // happen if the worker is down or provisioning failed. Such a workspace has no
            // tenant database to work in, so it must never become the active tenant: doing so
            // used to aim the tenant connection at the SHARED database while own-mode leaves
            // WorkspaceScope inert — an unscoped read of every workspace's data. Shared
            // workspaces are created Ready synchronously (and legacy rows default to 'ready'),
            // so they are unaffected. The next UI already polls and only enters a workspace
            // once it reports ready.
            abort_unless(
                $workspace->status === WorkspaceStatus::Ready,
                Response::HTTP_CONFLICT,
                'This workspace is not ready yet.'
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
