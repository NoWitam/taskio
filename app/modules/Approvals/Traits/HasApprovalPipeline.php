<?php

namespace App\Modules\Approvals\Traits;

use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Approvals\Models\ApprovalProcess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait HasApprovalPipeline
{
    public function approvalPipeline(): BelongsTo
    {
        return $this->belongsTo(ApprovalPipeline::class, 'approval_pipeline_id');
    }

    public function approvalProcesses(): MorphMany
    {
        return $this->morphMany(ApprovalProcess::class, 'approvable');
    }

    public function pendingApprovalProcess(): MorphOne
    {
        return $this->morphOne(ApprovalProcess::class, 'approvable')
            ->where('status', ApprovalProcessStatus::Pending)
            ->latest();
    }

    public function isInApproval(): bool
    {
        if ($this->relationLoaded('pendingApprovalProcess')) {
            return $this->pendingApprovalProcess !== null;
        }

        return $this->approvalProcesses()
            ->where('status', ApprovalProcessStatus::Pending)
            ->exists();
    }

    public function hasApprovalPipeline(): bool
    {
        return $this->approval_pipeline_id !== null;
    }

    public function latestApprovalRunId(): ?string
    {
        if ($this->relationLoaded('pendingApprovalProcess') && $this->pendingApprovalProcess !== null) {
            return $this->pendingApprovalProcess->run_id;
        }

        return $this->approvalProcesses()->latest()->value('run_id');
    }

    public function currentApprovalRun(): Collection
    {
        $pending = $this->pendingApprovalProcess;

        if (!$pending) {
            return new Collection;
        }

        return $this->approvalProcesses()
            ->where('run_id', $pending->run_id)
            ->orderBy('created_at')
            ->get();
    }
}
