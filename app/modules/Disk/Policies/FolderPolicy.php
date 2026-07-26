<?php

namespace App\Modules\Disk\Policies;

use App\Models\User;
use App\Modules\Disk\Models\Folder;
use App\Policies\Concerns\ChecksRecordOwnership;

/**
 * Membership is enforced upstream (RequireWorkspace + ResolveWorkspace + WorkspaceScope);
 * these checks gate ownership for mutations, mirroring FilePolicy/WorkflowPolicy.
 *
 * Virtual folders (the read-only "Zasoby" tree over resource-owned files) never reach a
 * policy: they are synthesised ids that no folder query can resolve.
 */
class FolderPolicy
{
    use ChecksRecordOwnership;

    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, Folder $folder): bool
    {
        return $user !== null;
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }

    /** Rename. */
    public function update(?User $user, Folder $folder): bool
    {
        return $this->ownsOrManagesSystemRecord($folder, $user);
    }

    /** Re-parenting a whole subtree. */
    public function move(?User $user, Folder $folder): bool
    {
        return $this->ownsOrManagesSystemRecord($folder, $user);
    }

    public function delete(?User $user, Folder $folder): bool
    {
        return $this->ownsOrManagesSystemRecord($folder, $user);
    }

    public function restore(?User $user, Folder $folder): bool
    {
        return $this->ownsOrManagesSystemRecord($folder, $user);
    }

    public function forceDelete(?User $user, Folder $folder): bool
    {
        return $this->ownsOrManagesSystemRecord($folder, $user);
    }
}
