<?php

namespace App\Modules\Approvals\Policies;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalPipeline;

class ApprovalPipelinePolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, ApprovalPipeline $pipeline): bool
    {
        return true;
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }

    public function update(?User $user, ApprovalPipeline $pipeline): bool
    {
        return $pipeline->creator_id === $user?->id && $pipeline->canBeEdited();
    }

    public function delete(?User $user, ApprovalPipeline $pipeline): bool
    {
        return $pipeline->creator_id === $user?->id && $pipeline->canBeDeleted();
    }
}
