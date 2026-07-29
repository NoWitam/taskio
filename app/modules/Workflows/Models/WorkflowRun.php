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
 * creator is a softer audit field and is now ALWAYS attributed: WorkflowRunManager::start
 * stamps the acting user for a MANUAL run and the workflow AUTHOR for an engine run (event/
 * schedule), so creator_id is never NULL for an engine run. Do not infer engine vs manual
 * from creator_id — read `origin`. The run row itself is a 'user'-typed HasCreator record;
 * the domain records its STEPS create are attributed to the RUN instead (via the polymorphic
 * HasCreator, creator_type='workflow_run'), so those are system records nobody owns.
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
        // Suspend/resume: what the run is parked on, its opaque correlation key, and when the
        // wait started. NULL for the whole life of a run without a suspending step.
        'waiting_on',
        'waiting_key',
        'waiting_since',
        'depth',
        'origin_run_id',
        'creator_id',
        'creator_type',
        'started_at',
        'finished_at',
        'error',
    ];

    protected $casts = [
        'state' => WorkflowRunState::class,
        'origin' => WorkflowRunOrigin::class,
        'trigger_payload' => 'array',
        'context' => 'array',
        'waiting_on' => 'array',
        'waiting_since' => 'datetime',
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
