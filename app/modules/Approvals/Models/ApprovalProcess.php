<?php

namespace App\Modules\Approvals\Models;

use App\Models\AbstractModel;
use App\Models\User;
use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Approvals\Enums\ApproverType;
use App\Modules\Bot\Models\Bot;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalProcess extends AbstractModel
{
    use HasCreator, HasUuids, TenantAware;

    protected $table = 'approval_processes';

    protected $fillable = [
        'run_id',
        'approval_pipeline_id',
        'approval_stage_id',
        'approvable_type',
        'approvable_id',
        'approver_type',
        'approver_id',
        'status',
        'note',
        'context',
        'decided_at',
        'creator_id',
    ];

    protected $casts = [
        'status' => ApprovalProcessStatus::class,
        'approver_type' => ApproverType::class,
        'context' => 'array',
        'decided_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(ApprovalPipeline::class, 'approval_pipeline_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ApprovalStage::class, 'approval_stage_id');
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /** The Bot approver for a `bot` process (null otherwise). */
    public function approverBot(): BelongsTo
    {
        return $this->belongsTo(Bot::class, 'approver_id');
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalProcessStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === ApprovalProcessStatus::Approved;
    }

    public function isRejected(): bool
    {
        return $this->status === ApprovalProcessStatus::Rejected;
    }

    public function isAiApprover(): bool
    {
        return $this->approver_type === ApproverType::Ai;
    }

    /** A named bot approver (its persona colors the AI verdict). */
    public function isBotApprover(): bool
    {
        return $this->approver_type === ApproverType::Bot;
    }

    /** Evaluated automatically by the AI pipeline (generic AI or a named bot). */
    public function isAutomatedApprover(): bool
    {
        return $this->approver_type->isAutomated();
    }

    public function scopeOnlyPending($query)
    {
        return $query->where('status', ApprovalProcessStatus::Pending);
    }

    public function scopeForApprover($query, User $user)
    {
        return $query->where('approver_type', ApproverType::User)
            ->where('approver_id', $user->id);
    }

    public function scopeForRun($query, string $runId)
    {
        return $query->where('run_id', $runId);
    }
}
