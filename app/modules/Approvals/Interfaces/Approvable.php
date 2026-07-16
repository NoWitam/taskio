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

    /**
     * Relations the approvable needs hydrated to build its queue item. Lets the
     * approval queue batch-load them grouped by type instead of lazy-loading per
     * row (N+1), without coupling the Approvals module to concrete approvables.
     *
     * @return array<int, string>
     */
    public function approvalQueueRelations(): array;

    public function isInApproval(): bool;

    public function getApprovalContext(): array;
}
