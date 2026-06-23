<?php

namespace App\Modules\Approvals\Models;

use App\Enums\IconEnum;
use App\Models\AbstractModel;
use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalPipeline extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, SoftDeletes, TenantAware;

    protected $table = 'approval_pipelines';

    protected $fillable = [
        'name',
        'icon',
        'description',
        'creator_id',
    ];

    protected $casts = [
        'icon' => IconEnum::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function stages(): HasMany
    {
        return $this->hasMany(ApprovalStage::class)->orderBy('order');
    }

    public function processes(): HasMany
    {
        return $this->hasMany(ApprovalProcess::class);
    }

    /**
     * Processes still awaiting a decision. Kept as a constrained relation (mirrors
     * Task::pendingApprovalProcess) so list queries can `withExists('pendingProcesses')`
     * and serve `hasActiveProcesses()` from a single batched sub-query.
     */
    public function pendingProcesses(): HasMany
    {
        return $this->hasMany(ApprovalProcess::class)
            ->where('status', ApprovalProcessStatus::Pending);
    }

    public function hasActiveProcesses(): bool
    {
        // Prefer the eager-loaded existence flag (list endpoints use withExists);
        // fall back to a direct EXISTS for single-model paths (update/delete guards).
        return (bool) ($this->pending_processes_exists ?? $this->pendingProcesses()->exists());
    }

    public function canBeEdited(): bool
    {
        return !$this->hasActiveProcesses();
    }

    public function canBeDeleted(): bool
    {
        return !$this->hasActiveProcesses();
    }

    protected static function newFactory()
    {
        return \Database\Factories\ApprovalPipelineFactory::new();
    }
}
