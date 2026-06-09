<?php

namespace App\Modules\Approvals\Interfaces;

use App\Modules\Approvals\DTOs\ApprovalQueueItem;
use App\Modules\Approvals\Models\ApprovalProcess;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

interface Approvable
{
    public function approvalPipeline(): BelongsTo;

    public function approvalProcesses(): MorphMany;

    public function onApprovalCompleted(ApprovalProcess $process): void;

    public function onApprovalRejected(ApprovalProcess $process): void;

    public function toApprovalQueueItem(): ApprovalQueueItem;

    public function isInApproval(): bool;

    public function getApprovalContext(): array;
}
