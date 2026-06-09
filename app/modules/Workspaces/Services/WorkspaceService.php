<?php

namespace App\Modules\Workspaces\Services;

use App\Models\User;
use App\Modules\Workspaces\DTOs\WorkspaceDTO;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WorkspaceService
{
    public function forUser(User $user): Collection
    {
        return Workspace::query()
            ->where(fn (Builder $query) => $query
                ->where('owner_id', $user->id)
                ->orWhereHas('users', fn (Builder $members) => $members->whereKey($user->id)))
            ->latest('created_at')
            ->get();
    }

    public function create(WorkspaceDTO $dto, User $owner): Workspace
    {
        return DB::transaction(function () use ($dto, $owner) {
            $workspace = Workspace::create([
                'name' => $dto->name,
                'owner_id' => $owner->id,
                'db_mode' => $dto->dbMode,
            ]);

            $workspace->users()->attach($owner->id);

            return $workspace;
        });
    }

    public function rename(Workspace $workspace, string $name): Workspace
    {
        $workspace->update(['name' => $name]);

        return $workspace;
    }

    public function addMember(Workspace $workspace, User $user): void
    {
        $workspace->users()->syncWithoutDetaching([$user->id]);
    }

    public function removeMember(Workspace $workspace, User $user): void
    {
        $workspace->users()->detach($user->id);
    }
}
