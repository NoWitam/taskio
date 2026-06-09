<?php

namespace App\Modules\Approvals\Models;

use App\Enums\IconEnum;
use App\Models\AbstractModel;
use App\Models\User;
use App\Modules\Approvals\Enums\ApproverType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalStage extends AbstractModel
{
    use HasUuids;

    protected $table = 'approval_stages';

    protected $fillable = [
        'approval_pipeline_id',
        'name',
        'icon',
        'description',
        'approver_type',
        'approver_id',
        'order',
    ];

    protected $casts = [
        'icon' => IconEnum::class,
        'approver_type' => ApproverType::class,
        'order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(ApprovalPipeline::class, 'approval_pipeline_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function isAiApprover(): bool
    {
        return $this->approver_type === ApproverType::Ai;
    }
}
