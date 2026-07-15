<?php

namespace App\Modules\Approvals\Policies;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Policies\Concerns\ChecksRecordOwnership;

class ApprovalPipelinePolicy
{
    use ChecksRecordOwnership;

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
        return $this->ownsOrManagesSystemRecord($pipeline, $user) && $pipeline->canBeEdited();
    }

    public function delete(?User $user, ApprovalPipeline $pipeline): bool
    {
        return $this->ownsOrManagesSystemRecord($pipeline, $user) && $pipeline->canBeDeleted();
    }
}
