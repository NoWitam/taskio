<?php

namespace App\Modules\Approvals\Policies;

use App\Models\User;
use App\Modules\Approvals\Enums\ApproverType;
use App\Modules\Approvals\Models\ApprovalProcess;

class ApprovalProcessPolicy
{
    public function view(?User $user, ApprovalProcess $process): bool
    {
        return true;
    }

    public function decide(?User $user, ApprovalProcess $process): bool
    {
        if (!$process->isPending()) {
            return false;
        }

        if ($process->approver_type === ApproverType::Ai) {
            return false;
        }

        return $process->approver_id === $user?->id;
    }
}
