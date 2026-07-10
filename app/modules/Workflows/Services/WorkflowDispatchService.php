<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Enums\WorkflowRunOrigin;
use App\Modules\Workflows\Enums\WorkflowStatus;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * The event dispatcher: given a matched trigger type and its whitelisted payload, it starts a
 * run for every ACTIVE workflow of that type in the current workspace that ALSO passes
 * targeting, conditions and the loop/cost gates. This is the single seam the real trigger
 * (the FormSubmission observer) and the manual-run endpoint funnel through.
 *
 * PIPELINE (in order, cheapest gates first):
 *   1. trigger-type match — Workflow::query() where status=active AND trigger_type=$type.
 *      Tenant scope is automatic (Workflow is TenantAware); schedule workflows are never a
 *      $type an event passes in, so they are structurally excluded.
 *   2. TARGETING — the inline form_submitted match on the payload (form_id / source / anonymous).
 *      No extra queries: form.id, source and form.is_anonymous are already in the snapshot.
 *   3. CONDITIONS (WorkflowConditionEvaluator) — the {field, operator, value} gate, evaluated
 *      over the same payload (so dotted paths like `fields.<id>` work naturally).
 *   4. loop/cost gates — depth (re-trigger chain), per-workflow monthly cap, workspace hard
 *      cap. An event that trips a gate is skipped SILENTLY (Log::info), never surfaced.
 *   5. WorkflowRunManager::start() — creates the pending run and defers its job.
 *
 * afterCommit LAYERING (single layer, at the HOOK): the caller wraps its dispatch() in
 * DB::afterCommit, so dispatch() runs only after the authoring write commits. start() itself
 * ALSO defers the JOB via DB::afterCommit — but by the time dispatch() runs there is no open
 * transaction, so that inner afterCommit fires immediately (Laravel runs an afterCommit
 * callback synchronously when no transaction is active). No double-deferral: the run row is
 * created synchronously inside dispatch(), and only the job dispatch is (harmlessly)
 * re-wrapped. See the report for the full rationale.
 */
class WorkflowDispatchService
{
    public function __construct(
        private WorkflowRunManager $runManager,
        private WorkflowConditionEvaluator $conditions,
        private WorkflowRunContext $runContext,
    ) {}

    /**
     * Fire every eligible active workflow of $type for $payload. Silent on every skip — an
     * event trigger must never throw into the authoring write path.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(WorkflowTriggerType $type, array $payload): void
    {
        // A schedule workflow is never event-driven; guard so a caller cannot mis-route one.
        if ($type === WorkflowTriggerType::SCHEDULE) {
            return;
        }

        // The active workflows of a type in one workspace are few, so a single ordered read
        // is cheaper and simpler than paging (and sidesteps chunkById's UUID-key edge cases).
        Workflow::query()
            ->where('status', WorkflowStatus::ACTIVE->value)
            ->where('trigger_type', $type->value)
            ->orderBy('created_at')
            ->get()
            ->each(fn (Workflow $workflow) => $this->maybeStart($workflow, $type, $payload));
    }

    /**
     * Run one workflow through targeting -> conditions -> gates and start it, or skip. This is
     * the per-workflow body of the event pipeline; the manual endpoint bypasses it (it targets
     * ONE workflow explicitly and enforces its own 422 caps).
     *
     * @param  array<string, mixed>  $payload
     */
    private function maybeStart(Workflow $workflow, WorkflowTriggerType $type, array $payload): void
    {
        if (!$this->matchesTrigger($workflow, $type, $payload)) {
            return;
        }

        if (!$this->conditions->passes($workflow->conditions, $payload)) {
            return;
        }

        [$depth, $originRunId] = $this->originForNewRun();

        if ($depth > (int) config('workflows.max_depth')) {
            Log::info('Workflow re-trigger refused: max depth exceeded.', [
                'workflow_id' => $workflow->id,
                'depth' => $depth,
                'origin_run_id' => $originRunId,
            ]);

            return;
        }

        if ($this->capReached($workflow)) {
            Log::info('Workflow trigger skipped: run budget reached.', [
                'workflow_id' => $workflow->id,
            ]);

            return;
        }

        $this->runManager->start(
            $workflow,
            WorkflowRunOrigin::EVENT,
            $payload,
            depth: $depth,
            originRunId: $originRunId,
            creatorId: null,
        );
    }

    /**
     * The cheap in-memory targeting gate. With the task/approval triggers gone, the only
     * event-driven type is form_submitted; its match reads directly off the whitelisted
     * payload (no extra queries). Any other type (a stray schedule) conservatively does not
     * match — schedule workflows are never event-dispatched.
     *
     * @param  array<string, mixed>  $payload
     */
    private function matchesTrigger(Workflow $workflow, WorkflowTriggerType $type, array $payload): bool
    {
        return match ($type) {
            WorkflowTriggerType::FORM_SUBMITTED => $this->matchesFormSubmitted($workflow->trigger_config ?? [], $payload),
            default => false,
        };
    }

    /**
     * form_submitted targeting (all clauses AND-combined; an absent clause always matches):
     *   - form_id   (null = any form)          -> equals payload form.id
     *   - source.in (absent = any source)      -> payload source ∈ the set
     *   - anonymous (null = any)               -> equals the payload's form.is_anonymous
     *
     * `form.is_anonymous` is carried in the payload so this stays query-free.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $payload
     */
    private function matchesFormSubmitted(array $config, array $payload): bool
    {
        $formId = Arr::get($config, 'form_id');

        if ($formId !== null && (string) $formId !== (string) Arr::get($payload, 'form.id')) {
            return false;
        }

        $sourceIn = Arr::get($config, 'source.in');

        if (is_array($sourceIn) && $sourceIn !== [] && !in_array(Arr::get($payload, 'source'), $sourceIn, true)) {
            return false;
        }

        $anonymous = Arr::get($config, 'anonymous');

        if ($anonymous !== null && (bool) $anonymous !== (bool) Arr::get($payload, 'form.is_anonymous')) {
            return false;
        }

        return true;
    }

    /**
     * Depth + origin for a run started NOW. When a run is currently executing on this worker
     * (a workflow step authored the change that re-triggered), the new run is its CHILD:
     * depth = parent depth + 1, origin_run_id = parent id. Otherwise a top-level run: depth 0,
     * no origin.
     *
     * @return array{0: int, 1: string|null}
     */
    private function originForNewRun(): array
    {
        $parent = $this->runContext->current();

        if ($parent === null) {
            return [0, null];
        }

        return [(int) $parent->depth + 1, $parent->id];
    }

    /**
     * Whether starting a run would exceed a budget: the per-workflow monthly SOFT cap
     * (max_runs_per_month) or the workspace-wide HARD cap (max_runs_hard_cap). Either being
     * reached refuses the run. Shared with the manual endpoint's 422 check so event and
     * manual paths enforce the same ceiling.
     */
    public function capReached(Workflow $workflow): bool
    {
        if ($this->runManager->runsThisMonth($workflow) >= (int) config('workflows.max_runs_per_month')) {
            return true;
        }

        return $this->runManager->runsThisMonthAcrossWorkspace() >= (int) config('workflows.max_runs_hard_cap');
    }

    /**
     * Start a MANUAL run for a single workflow with a pre-built payload. Unlike an event
     * trigger, a manual run: is always top-level (depth 0, no origin), carries the acting
     * user as creator, and works on an INACTIVE workflow (test-before-activate). The caller
     * (the controller) has already resolved+validated the target AND enforced the cap 422
     * via capReached(), so no targeting/condition gate applies — a manual run is an explicit,
     * deliberate execution.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatchManual(Workflow $workflow, array $payload, string $creatorId): WorkflowRun
    {
        return $this->runManager->start(
            $workflow,
            WorkflowRunOrigin::MANUAL,
            $payload,
            depth: 0,
            originRunId: null,
            creatorId: $creatorId,
        );
    }
}
