<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Enums\WorkflowRunOrigin;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

/**
 * Read-only queries for workflow RUNS (the monitoring / Runs view, Batch 4). Owns its
 * Eloquent directly (the module has no Repository layer). Runs are workspace-visible to
 * any member — WorkspaceScope already isolates rows to the active workspace, so a run
 * from another workspace never appears here.
 */
class WorkflowRunService
{
    /**
     * The workflow's runs, newest first, cursor-paginated. The list carries `steps_count`
     * (withCount) but NOT the steps themselves — a runs list never needs the step rows, so
     * eager-loading them would be a pure N+1/overfetch cost.
     *
     * Ordering: created_at DESC with an id DESC tiebreak. Ids are UUIDv7 (monotonic), so on
     * an identical timestamp the newer row still sorts first — the same lesson the Bot inbox
     * "latest action" query encodes, and it keeps cursor pagination stable across a page
     * boundary where several runs share a timestamp.
     *
     * Filters (both optional). `$request->enum()` returns null for an absent OR invalid
     * value, so an unrecognised `?state=`/`?origin=` is silently ignored rather than
     * erroring — mirrors how the Bot inbox treats its `state` filter.
     *   ?state=  one of WorkflowRunState (pending|running|waiting|completed|failed|cancelled)
     *   ?origin= one of WorkflowRunOrigin (event|schedule|manual)
     */
    public function runsFor(Workflow $workflow, Request $request): CursorPaginator
    {
        return WorkflowRun::query()
            ->where('workflow_id', $workflow->id)
            ->withCount('steps')
            ->when(
                $state = $request->enum('state', WorkflowRunState::class),
                fn ($query) => $query->where('state', $state)
            )
            ->when(
                $origin = $request->enum('origin', WorkflowRunOrigin::class),
                fn ($query) => $query->where('origin', $origin)
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(15);
    }

    /**
     * A single run with its steps eager-loaded in `position` order (the model's `steps`
     * relation applies the order). The caller asserts the run belongs to the workflow
     * before calling (the controller 404s otherwise).
     */
    public function showRun(WorkflowRun $run): WorkflowRun
    {
        return $run->load('steps');
    }
}
