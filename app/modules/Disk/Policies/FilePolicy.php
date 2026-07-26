<?php

namespace App\Modules\Disk\Policies;

use App\Models\User;
use App\Modules\Disk\Models\File;
use App\Policies\Concerns\ChecksRecordOwnership;

/**
 * Workspace membership is enforced upstream (RequireWorkspace + ResolveWorkspace +
 * WorkspaceScope), so a file only ever reaches these checks if it belongs to the active
 * workspace. What is gated here is OWNERSHIP for mutations — mirrors WorkflowPolicy/BotPolicy.
 *
 * The disk-specific rule on top: a file that belongs to another resource (a task attachment,
 * a report's output — File::isOwnedByResource) is shown on the disk but its LIFECYCLE belongs
 * to the owning module. Such a file may be described (name/tags/description) from the disk,
 * never trashed or moved out of its virtual home.
 */
class FilePolicy
{
    use ChecksRecordOwnership;

    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    /** Any member may read a file in their workspace — creator never gates reads. */
    public function view(?User $user, File $file): bool
    {
        return $user !== null;
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }

    /** Metadata edits (rename, description, tags) — allowed even for resource-owned files. */
    public function update(?User $user, File $file): bool
    {
        return $this->ownsOrManagesSystemRecord($file, $user);
    }

    /** Moving between folders is meaningless for a file that lives under a virtual folder. */
    public function move(?User $user, File $file): bool
    {
        return !$file->isOwnedByResource() && $this->ownsOrManagesSystemRecord($file, $user);
    }

    /** Trashing an attachment is the parent module's job (detach it there instead). */
    public function delete(?User $user, File $file): bool
    {
        return !$file->isOwnedByResource() && $this->ownsOrManagesSystemRecord($file, $user);
    }

    public function restore(?User $user, File $file): bool
    {
        return $this->ownsOrManagesSystemRecord($file, $user);
    }

    public function forceDelete(?User $user, File $file): bool
    {
        return $this->ownsOrManagesSystemRecord($file, $user);
    }
}
