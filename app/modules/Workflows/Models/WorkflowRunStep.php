<?php

namespace App\Modules\Workflows\Models;

use App\Models\AbstractModel;
use App\Modules\Workflows\Enums\WorkflowRunStepStatus;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit-log row for a single step executed within a workflow run (mirrors bot_actions):
 * its position in the ordered step list, its type + key, the resolved outcome (succeeded
 * with an output payload, or failed with an error). No creator_id — a run step is always
 * engine-performed and owned by its parent run.
 */
class WorkflowRunStep extends AbstractModel
{
    use HasFactory, HasUuids, TenantAware;

    protected $table = 'workflow_run_steps';

    protected $fillable = [
        'workflow_run_id',
        'position',
        'type',
        'key',
        'status',
        'payload',
        'error',
    ];

    protected $casts = [
        'type' => WorkflowStepType::class,
        'status' => WorkflowRunStepStatus::class,
        'position' => 'integer',
        'payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    protected static function newFactory()
    {
        return \Database\Factories\WorkflowRunStepFactory::new();
    }
}
