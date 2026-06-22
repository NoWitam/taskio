<?php

namespace App\Modules\FilterTabs\Policies;

use App\Models\User;
use App\Modules\FilterTabs\Models\FilterTab;

class FilterTabPolicy
{
    public function create(?User $user): bool
    {
        return $user !== null;
    }

    public function update(?User $user, FilterTab $tab): bool
    {
        return $user !== null && $tab->user_id === $user->id;
    }

    public function delete(?User $user, FilterTab $tab): bool
    {
        return $user !== null && $tab->user_id === $user->id;
    }
}
