<?php

namespace App\Modules\Workspaces\Policies;

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;

class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $workspace->hasMember($user);
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $workspace->isOwnedBy($user);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $workspace->isOwnedBy($user);
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $workspace->isOwnedBy($user);
    }

    public function manageGroups(User $user, Workspace $workspace): bool
    {
        return $workspace->isOwnedBy($user);
    }
}
