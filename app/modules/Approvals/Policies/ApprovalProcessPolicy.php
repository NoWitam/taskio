<?php

namespace App\Modules\Approvals\Policies;

use App\Models\User;
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

        // Automated approvers (generic AI or a named bot) are decided by the AI
        // evaluation job, never by a human over HTTP — exclude them by intent, not by
        // relying on user/bot id non-collision.
        if ($process->isAutomatedApprover()) {
            return false;
        }

        return $process->approver_id === $user?->id;
    }
}
