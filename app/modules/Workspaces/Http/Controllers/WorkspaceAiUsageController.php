<?php

namespace App\Modules\Workspaces\Http\Controllers;

use App\Modules\Variables\Services\AiUsageService;
use App\Modules\Workspaces\Http\Requests\UpdateAiBudgetRequest;
use App\Modules\Workspaces\Http\Resources\AiUsageSummaryResource;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Workspace AI-usage endpoints (R2 sub-stage 4). READ the $-first usage summary (any member) and SET the
 * monthly $ cap (owner only). Thin: the controller resolves the summary through the shared Variables
 * {@see AiUsageService} (the allowed Workspaces → Variables edge) and the write persists one central
 * column; the PATCH /workspaces/{id} rename stays untouched.
 *
 * The usage figures are always read against the CURRENTLY ACTIVE workspace (the meter is workspace-scoped),
 * which the ResolveWorkspace middleware set from X-Workspace-Id — the same workspace the route binds.
 */
class WorkspaceAiUsageController
{
    public function __construct(
        private AiUsageService $usage,
        private TenantContext $tenant,
    ) {}

    /** The usage summary for a workspace the caller is a member of. */
    public function show(Request $request, Workspace $workspace): AiUsageSummaryResource
    {
        $this->assertRouteIsActiveWorkspace($workspace);

        abort_unless($request->user()->can('view', $workspace), 403);

        return new AiUsageSummaryResource($this->usage->summary(), $workspace);
    }

    /**
     * Set (or clear) the workspace's monthly $ cap. null clears the override (inherit the env default), a
     * positive value is the cap, 0 is explicit unlimited. Owner-only via the FormRequest Policy check.
     * Returns the refreshed summary so the caller sees the new effective cap/source immediately.
     */
    public function updateCap(UpdateAiBudgetRequest $request, Workspace $workspace): AiUsageSummaryResource
    {
        $this->assertRouteIsActiveWorkspace($workspace);

        $workspace->update([
            'ai_monthly_cost_cap' => $request->input('monthly_cost_cap'),
        ]);

        // cap() reads the ACTIVE-workspace instance TenantContext holds (set by ResolveWorkspace at request
        // start). The invariant above guarantees it is the SAME central row as this route-bound one; refresh
        // it so the returned summary reflects the new cap immediately. Workspace is a central model, so
        // re-setting it never disturbs the tenant connection.
        $this->tenant->set($workspace);

        return new AiUsageSummaryResource($this->usage->summary(), $workspace);
    }

    /**
     * The usage summary + cap are read/written against the CURRENTLY ACTIVE workspace (the meter is scoped to
     * TenantContext, set by ResolveWorkspace from X-Workspace-Id), so a route id that differs from the active
     * tenant would MISLABEL the summary / MISDIRECT the cap refresh. ResolveWorkspace already enforces
     * membership on X-Workspace-Id, so this only ever fires on a header/route MISMATCH (both the caller's own
     * workspaces) — a clean 409 instead of a silent mislabel. The FE always sends the matching header, so the
     * happy path is a pass-through.
     */
    private function assertRouteIsActiveWorkspace(Workspace $workspace): void
    {
        abort_unless(
            $workspace->getKey() === $this->tenant->id(),
            Response::HTTP_CONFLICT,
            __('workspaces.ai_budget.workspace_mismatch'),
        );
    }
}
