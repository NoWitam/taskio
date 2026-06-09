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
 * that the authenticated user is a member. Runs on the api group; when the user
 * is not yet resolved (e.g. before a route's auth:sanctum), it no-ops so
 * token-authenticated endpoints handle the header themselves.
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
