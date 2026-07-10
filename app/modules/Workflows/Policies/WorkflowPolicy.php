<?php

namespace App\Modules\Workflows\Policies;

use App\Models\User;
use App\Modules\Workflows\Models\Workflow;

/**
 * Workspace membership is enforced upstream by ResolveWorkspace + WorkspaceScope:
 * a request only ever reaches a workflow that belongs to the active workspace, and a
 * non-member cannot resolve the workspace at all. These checks therefore gate
 * ownership (mutations) on top of that membership guarantee — mirrors BotPolicy.
 */
class WorkflowPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, Workflow $workflow): bool
    {
        return $user !== null;
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }

    public function update(?User $user, Workflow $workflow): bool
    {
        return $user !== null && $workflow->creator_id === $user->id;
    }

    /** Toggling a workflow's status (active|inactive) is a creator-only action (mirrors update). */
    public function changeStatus(?User $user, Workflow $workflow): bool
    {
        return $user !== null && $workflow->creator_id === $user->id;
    }

    public function delete(?User $user, Workflow $workflow): bool
    {
        return $user !== null && $workflow->creator_id === $user->id;
    }

    public function restore(?User $user, Workflow $workflow): bool
    {
        return $user !== null && $workflow->creator_id === $user->id;
    }

    /**
     * Manually run a workflow. Defined now for the capability flag; the run endpoint and
     * its execution wiring ship in Batch 3. Any workspace member may run (mirrors view).
     */
    public function run(?User $user, Workflow $workflow): bool
    {
        return $user !== null;
    }
}
