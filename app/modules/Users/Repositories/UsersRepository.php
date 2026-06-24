<?php

namespace App\Modules\Users\Repositories;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class UsersRepository
{
    public function __construct(
        private readonly TenantContext $tenant
    ) {}

    public function getByIds(array $ids): Collection
    {
        if (empty($ids)) {
            return collect();
        }

        return $this->membersQuery()
            ->whereIn('id', $ids)
            ->get();
    }

    public function cursorPaginate(?string $search): CursorPaginator
    {
        $search = is_string($search) ? trim($search) : null;

        return $this->membersQuery()
            ->search(['name', 'email'], $search)
            ->orderBy('id')
            ->cursorPaginate(8);
    }

    /**
     * Base query for the active workspace's MEMBERS. The member filtering (owner +
     * `workspace_user` pivot) is now enforced globally by
     * {@see \App\Models\Scopes\WorkspaceMemberScope}, so a plain `User::query()`
     * already returns only members of the active workspace.
     *
     * One thing the scope does NOT do is fail closed when no workspace is active —
     * it is inert there, which would return EVERY user. The directory must never
     * leak across workspaces, so we keep an explicit no-workspace empty guard.
     */
    private function membersQuery(): Builder
    {
        if (!$this->tenant->hasWorkspace()) {
            return User::query()->whereRaw('1 = 0');
        }

        return User::query();
    }
}
