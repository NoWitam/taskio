<?php

namespace App\Modules\Workspaces\Services;

use App\Models\User;
use App\Modules\Workspaces\DTOs\WorkspaceDTO;
use App\Modules\Workspaces\DTOs\WorkspaceSettingsDTO;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Jobs\ProvisionWorkspaceJob;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkspaceService
{
    public function forUser(User $user): Collection
    {
        return Workspace::query()
            ->where(fn (Builder $query) => $query
                ->where('owner_id', $user->id)
                // No workspace is active here (this computes which workspaces the
                // user belongs to), but bypass the member scope defensively so the
                // existence check is never narrowed to the active workspace.
                ->orWhereHas('users', fn (Builder $members) => $members
                    ->withoutWorkspaceMemberScope()
                    ->whereKey($user->id)))
            ->latest('created_at')
            ->get();
    }

    public function create(WorkspaceDTO $dto, User $owner): Workspace
    {
        $isOwn = $dto->dbMode === WorkspaceDbMode::Own;

        // Own-mode workspaces are unusable until their database is provisioned,
        // so they are created Provisioning; shared workspaces are Ready at once.
        $workspace = DB::transaction(function () use ($dto, $owner, $isOwn) {
            $workspace = Workspace::create([
                'name' => $dto->name,
                'owner_id' => $owner->id,
                'db_mode' => $dto->dbMode,
                'status' => $isOwn ? WorkspaceStatus::Provisioning : WorkspaceStatus::Ready,
            ]);

            $workspace->users()->attach($owner->id);

            return $workspace;
        });

        // Provision asynchronously after commit so the row is visible to the
        // worker; the job flips status to Ready/Failed when it finishes.
        if ($isOwn) {
            ProvisionWorkspaceJob::dispatch($workspace);
        }

        return $workspace;
    }

    /**
     * Apply the settings this request actually spoke about (see WorkspaceSettingsDTO for why "absent"
     * and "null" have to stay distinguishable). A request that named nothing writes nothing.
     */
    public function updateSettings(Workspace $workspace, WorkspaceSettingsDTO $dto): Workspace
    {
        $attributes = $dto->toAttributes();

        if ($attributes !== []) {
            $workspace->update($attributes);
        }

        return $workspace;
    }

    /**
     * The workspace's members: the owner plus every attached user, deduplicated.
     * Eager-loads the owner and members so the resource resolves is_owner in PHP
     * without per-row queries.
     */
    public function members(Workspace $workspace): Collection
    {
        $workspace->loadMissing(['owner', 'users']);

        return $workspace->users
            ->push($workspace->owner)
            ->filter()
            ->unique('id')
            ->values();
    }

    public function addMember(Workspace $workspace, User $user): void
    {
        $workspace->users()->syncWithoutDetaching([$user->id]);
    }

    public function removeMember(Workspace $workspace, User $user): void
    {
        // The owner is a structural member and cannot be detached; removing them
        // would orphan the workspace. Callers must transfer ownership instead.
        if ($user->id === $workspace->owner_id) {
            throw ValidationException::withMessages([
                'user' => ['cannot_remove_owner'],
            ]);
        }

        $workspace->users()->detach($user->id);
    }
}
