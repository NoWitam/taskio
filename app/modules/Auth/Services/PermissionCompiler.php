<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Models\GroupPermission;
use Illuminate\Database\Eloquent\Builder;

/**
 * Compiles the flat permission list a user has within a workspace: the union of
 * the permissions of every group (in that workspace) the user belongs to.
 */
class PermissionCompiler
{
    /**
     * @return list<string>
     */
    public function compileFor(User $user, ?string $workspaceId): array
    {
        if ($workspaceId === null) {
            return [];
        }

        return GroupPermission::query()
            ->whereHas('group', fn (Builder $group) => $group
                ->where('workspace_id', $workspaceId)
                ->whereHas('users', fn (Builder $users) => $users->whereKey($user->id)))
            ->distinct()
            ->pluck('permission')
            ->all();
    }
}
