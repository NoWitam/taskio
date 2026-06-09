<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\Group;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class GroupService
{
    public function __construct(
        private AuthContextCache $cache
    ) {}

    public function forWorkspace(Workspace $workspace): Collection
    {
        return Group::query()
            ->where('workspace_id', $workspace->id)
            ->with(['users', 'permissions'])
            ->latest('created_at')
            ->get();
    }

    /**
     * @param  list<string>  $userIds
     * @param  list<string>  $permissions
     */
    public function create(Workspace $workspace, string $name, array $userIds, array $permissions): Group
    {
        $group = DB::transaction(function () use ($workspace, $name, $userIds, $permissions) {
            $group = Group::create([
                'workspace_id' => $workspace->id,
                'name' => $name,
            ]);

            $group->users()->sync($userIds);
            $this->syncPermissions($group, $permissions);

            return $group;
        });

        $this->flush($userIds);

        return $group->load(['users', 'permissions']);
    }

    /**
     * @param  list<string>  $userIds
     * @param  list<string>  $permissions
     */
    public function update(Group $group, string $name, array $userIds, array $permissions): Group
    {
        $affected = $this->affectedUserIds($group, $userIds);

        DB::transaction(function () use ($group, $name, $userIds, $permissions) {
            $group->update(['name' => $name]);
            $group->users()->sync($userIds);
            $this->syncPermissions($group, $permissions);
        });

        $this->flush($affected);

        return $group->load(['users', 'permissions']);
    }

    public function delete(Group $group): void
    {
        $affected = $group->users()->pluck('users.id')->all();

        DB::transaction(function () use ($group) {
            $group->permissions()->delete();
            $group->users()->detach();
            $group->delete();
        });

        $this->flush($affected);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function syncPermissions(Group $group, array $permissions): void
    {
        $group->permissions()->delete();

        $rows = collect($permissions)
            ->unique()
            ->map(fn (string $permission): array => ['permission' => $permission])
            ->all();

        if ($rows !== []) {
            $group->permissions()->createMany($rows);
        }
    }

    /**
     * @param  list<string>  $newUserIds
     * @return list<string>
     */
    private function affectedUserIds(Group $group, array $newUserIds): array
    {
        $current = $group->users()->pluck('users.id')->all();

        return array_values(array_unique([...$current, ...$newUserIds]));
    }

    /**
     * @param  list<string>  $userIds
     */
    private function flush(array $userIds): void
    {
        foreach ($userIds as $userId) {
            $this->cache->forget($userId);
        }
    }
}
