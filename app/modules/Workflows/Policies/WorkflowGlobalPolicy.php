<?php

namespace App\Modules\Workflows\Policies;

use App\Models\User;
use App\Modules\Workflows\Models\WorkflowGlobal;
use App\Policies\Concerns\ChecksRecordOwnership;

/**
 * Workspace membership is enforced upstream by ResolveWorkspace + WorkspaceScope: a request only
 * ever reaches a global that belongs to the active workspace, and a non-member cannot resolve the
 * workspace at all. These checks therefore gate READ on membership (any member) and MUTATION on
 * ownership (the creator) — mirrors WorkflowPolicy. A global is always user-created, so the
 * system-record fallback in ChecksRecordOwnership never applies.
 */
class WorkflowGlobalPolicy
{
    use ChecksRecordOwnership;

    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, WorkflowGlobal $global): bool
    {
        return $user !== null;
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }

    public function update(?User $user, WorkflowGlobal $global): bool
    {
        return $this->ownsOrManagesSystemRecord($global, $user);
    }

    public function delete(?User $user, WorkflowGlobal $global): bool
    {
        return $this->ownsOrManagesSystemRecord($global, $user);
    }
}
