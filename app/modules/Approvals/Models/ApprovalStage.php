<?php

namespace App\Modules\Approvals\Models;

use App\Enums\IconEnum;
use App\Models\AbstractModel;
use App\Models\User;
use App\Modules\Approvals\Enums\ApproverType;
use App\Modules\Bot\Models\Bot;
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

    /**
     * The Bot approver for a `bot` stage (null otherwise). approver_id is a plain uuid
     * resolved by approver_type, so this relation is constrained to the bot branch.
     */
    public function approverBot(): BelongsTo
    {
        return $this->belongsTo(Bot::class, 'approver_id');
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
}
