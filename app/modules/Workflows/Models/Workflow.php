<?php

namespace App\Modules\Workflows\Models;

use App\Models\AbstractModel;
use App\Modules\Workflows\Enums\WorkflowStatus;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A workflow DEFINITION: a trigger + optional conditions + an ordered list of steps.
 * This is the stored configuration only — the engine that reacts to triggers, evaluates
 * conditions and runs steps (plus the scheduler that fills next_due_at) ships in later
 * batches. A workflow is created INACTIVE and toggled via the dedicated status endpoint.
 */
class Workflow extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, SoftDeletes, TenantAware;

    protected $table = 'workflows';

    protected $fillable = [
        'name',
        'status',
        'description',
        'icon',
        'trigger_type',
        'trigger_config',
        'conditions',
        'steps',
        'last_scheduled_run_at',
        'next_due_at',
        'creator_id',
    ];

    protected $casts = [
        'status' => WorkflowStatus::class,
        'trigger_type' => WorkflowTriggerType::class,
        'trigger_config' => 'array',
        'conditions' => 'array',
        'steps' => 'array',
        'last_scheduled_run_at' => 'datetime',
        'next_due_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function newFactory()
    {
        return \Database\Factories\WorkflowFactory::new();
    }
}
