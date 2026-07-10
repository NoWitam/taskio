<?php

namespace App\Modules\Workflows\Models;

use App\Models\AbstractModel;
use App\Modules\Workflows\Enums\WorkflowRunOrigin;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One EXECUTION of a workflow definition: its ordered steps run against a trigger payload,
 * accumulating each step's output into `context` (read by `{{steps.<key>.*}}` references).
 * The run-state machine (claim / release / reap) lives in WorkflowRunManager; this model
 * is just the persisted row.
 *
 * origin (EVENT | SCHEDULE | MANUAL) is the AUTHORITATIVE record of how the run began.
 * creator_id is a softer audit field: HasCreator's saving hook stamps auth()->id() whenever
 * it is unset, so an engine run started OUTSIDE a request (queue/console/schedule) records
 * NULL, while an engine run whose trigger fired INSIDE an authenticated request carries the
 * triggering user. A MANUAL run explicitly carries the acting user. Do not infer engine vs
 * manual from creator_id — read `origin`.
 */
class WorkflowRun extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, TenantAware;

    protected $table = 'workflow_runs';

    protected $fillable = [
        'workflow_id',
        'state',
        'origin',
        'trigger_type',
        'trigger_payload',
        'context',
        'depth',
        'origin_run_id',
        'creator_id',
        'started_at',
        'finished_at',
        'error',
    ];

    protected $casts = [
        'state' => WorkflowRunState::class,
        'origin' => WorkflowRunOrigin::class,
        'trigger_payload' => 'array',
        'context' => 'array',
        'depth' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowRunStep::class, 'workflow_run_id')->orderBy('position');
    }

    protected static function newFactory()
    {
        return \Database\Factories\WorkflowRunFactory::new();
    }
}
