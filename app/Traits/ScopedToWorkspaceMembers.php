<?php

namespace App\Traits;

use App\Models\Scopes\WorkspaceMemberScope;

/**
 * Restricts a CENTRAL model to the members of the active workspace.
 *
 * This is the membership counterpart to {@see TenantAware}. Where TenantAware is for
 * per-tenant data (a `workspace_id` column, scoped + stamped in shared mode), this
 * trait is for CENTRAL models that are shared across workspaces but must be presented
 * scoped to the active workspace's MEMBERSHIP — today only {@see \App\Models\User}.
 *
 * It only adds the {@see WorkspaceMemberScope} global scope; there is deliberately NO
 * creating() hook. Unlike TenantAware (which stamps workspace_id onto new rows),
 * membership is expressed as a `workspace_user` PIVOT write, not a column on the model,
 * so there is nothing to stamp at create time.
 */
trait ScopedToWorkspaceMembers
{
    public static function bootScopedToWorkspaceMembers(): void
    {
        static::addGlobalScope(new WorkspaceMemberScope);
    }
}
