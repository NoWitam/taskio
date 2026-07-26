<?php

namespace App\Modules\Variables\Policies;

use App\Models\User;
use App\Modules\Variables\Models\CustomFunction;
use App\Policies\Concerns\ChecksRecordOwnership;

/**
 * Workspace membership is enforced upstream by ResolveWorkspace + WorkspaceScope: a request only ever
 * reaches a function that belongs to the active workspace, and a non-member cannot resolve the workspace
 * at all. These checks therefore gate READ on membership (any member) and MUTATION on ownership (the
 * creator) — mirrors ConstantPolicy / WorkflowPolicy. A function is always user-created, so the
 * system-record fallback in ChecksRecordOwnership never applies.
 */
class CustomFunctionPolicy
{
    use ChecksRecordOwnership;

    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, CustomFunction $function): bool
    {
        return $user !== null;
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }

    public function update(?User $user, CustomFunction $function): bool
    {
        return $this->ownsOrManagesSystemRecord($function, $user);
    }

    public function delete(?User $user, CustomFunction $function): bool
    {
        return $this->ownsOrManagesSystemRecord($function, $user);
    }
}
