<?php

namespace App\Modules\Users\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Collection;

class UsersRepository
{
    public function getByIds(array $ids): Collection
    {
        if(empty($ids)) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ids)
            ->get();
    }

    public function cursorPaginate(?string $search): CursorPaginator
    {
        $search = is_string($search) ? trim($search) : null;

        return User::query()
            ->search(['name', 'email'], $search)
            ->orderBy('id')
            ->cursorPaginate(8);
    }
}
