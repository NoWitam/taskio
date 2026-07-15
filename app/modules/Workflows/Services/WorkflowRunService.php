<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Forms\Models\Form;
use App\Modules\Workflows\Enums\WorkflowRunOrigin;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Read-only queries for workflow RUNS (the monitoring / Runs view). Owns its Eloquent
 * directly (the module has no Repository layer). Runs are workspace-visible to any member —
 * WorkspaceScope already isolates rows to the active workspace, so a run from another
 * workspace never appears in any of these lists.
 */
class WorkflowRunService
{
    /**
     * One workflow's runs, newest first, cursor-paginated (page size 15). Same shared shape
     * as the global feed but scoped to a single workflow — the `workflow` relation is NOT
     * eager-loaded (the caller already has the workflow context), so the row omits its
     * `workflow` block.
     */
    public function runsFor(Workflow $workflow, Request $request): CursorPaginator
    {
        return $this->filteredRunsQuery($request)
            ->where('workflow_id', $workflow->id)
            ->cursorPaginate(15);
    }

    /**
     * The GLOBAL runs feed: every run in the active workspace (WorkspaceScope isolates it),
     * newest first, cursor-paginated. Honors an OPTIONAL multi-value `workflow_id[]` filter
     * (whereIn) and eager-loads `workflow` so each row can carry its workflow context
     * (name/icon/status/trigger_type) without an N+1.
     */
    public function runsGlobal(Request $request): CursorPaginator
    {
        return $this->filteredRunsQuery($request)
            ->with('workflow')
            ->when(
                $workflowIds = array_values(array_filter($request->array('workflow_id'), 'is_string')),
                fn (Builder $query) => $query->whereIn('workflow_id', $workflowIds),
            )
            ->cursorPaginate(15);
    }

    /**
     * A single run with its steps eager-loaded in `position` order plus its parent workflow
     * (the workflow feeds the detail resource's schedule_descriptor for a schedule run, where
     * the global drawer has no workflow otherwise loaded). The caller asserts the run belongs
     * to the workflow before calling (the controller 404s otherwise).
     */
    public function showRun(WorkflowRun $run): WorkflowRun
    {
        $run->load(['steps', 'workflow']);

        // Resolve the trigger FORM for a form_submitted run so the detail resource can render a
        // lean form item (name + description + icon) IN THE SAME response — the FE never makes a
        // second call for it. Tenant-scoped (Form is TenantAware); a deleted/foreign form → null,
        // and the resource falls back to the stored trigger_payload snapshot name. One extra query
        // on SHOW only (single run), never on a list.
        if ($run->trigger_type === WorkflowTriggerType::FORM_SUBMITTED->value) {
            $formId = $run->trigger_payload['form']['id'] ?? null;
            // Guard the UUID shape before querying — a malformed/legacy payload id (non-uuid)
            // must not crash the show with a Postgres uuid cast error; it just yields no form block.
            $run->setRelation('triggerForm', is_string($formId) && Str::isUuid($formId) ? Form::find($formId) : null);
        }

        return $run;
    }

    /**
     * The shared runs query: newest-first ordering, a `steps_count` (withCount, not the rows),
     * and the multi-value state/origin/trigger_type + created_at date-range filters. An empty/
     * absent filter array is a no-op (whereIn is skipped). Ordering is created_at DESC with an
     * id DESC tiebreak — ids are UUIDv7 (monotonic), so runs sharing a timestamp still sort
     * deterministically, keeping cursor pagination stable across a page boundary.
     */
    private function filteredRunsQuery(Request $request): Builder
    {
        return WorkflowRun::query()
            ->withCount('steps')
            ->when(
                $states = $this->enumValues($request, 'state', WorkflowRunState::class),
                fn (Builder $query) => $query->whereIn('state', $states),
            )
            ->when(
                $origins = $this->enumValues($request, 'origin', WorkflowRunOrigin::class),
                fn (Builder $query) => $query->whereIn('origin', $origins),
            )
            ->when(
                $triggerTypes = $this->enumValues($request, 'trigger_type', WorkflowTriggerType::class),
                fn (Builder $query) => $query->whereIn('trigger_type', $triggerTypes),
            )
            ->filterByDate('created_at', $request)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * The recognised enum VALUES for a repeated filter key, tolerantly parsed: a single scalar
     * is treated as a one-element array, and any member that is not a known enum case is
     * dropped (tryFrom returns null) rather than erroring — the module's long-standing "ignore
     * an unknown filter" behavior. Returns the backing string values for the whereIn.
     *
     * @param  class-string  $enumClass
     * @return array<int, string>
     */
    private function enumValues(Request $request, string $key, string $enumClass): array
    {
        return collect($request->array($key))
            ->map(fn ($value) => is_scalar($value) ? $enumClass::tryFrom((string) $value) : null)
            ->filter()
            ->map(fn ($enum) => $enum->value)
            ->unique()
            ->values()
            ->all();
    }
}
