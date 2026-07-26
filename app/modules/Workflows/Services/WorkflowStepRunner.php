<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Variables\Support\FunctionScope;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Enums\WorkflowRunStepStatus;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Models\WorkflowRunStep;
use Throwable;

/**
 * Executes a CLAIMED run's ordered steps and finalizes the run. For each step (in the
 * workflow definition's order):
 *
 *   1. resolve the step config's `{{...}}` references against the live context,
 *   2. run the step (through its domain service),
 *   3. record a WorkflowRunStep audit row (succeeded + output | failed + error),
 *   4. merge the step output into context.steps.<key>, persisted on the run as it goes.
 *
 * Steps commit INDEPENDENTLY — there is no all-or-nothing transaction around the run (same
 * intended design as the bot tools): a workflow that created a task and then failed to
 * assign a bot leaves the task in place, and the timeline shows exactly where it stopped.
 *
 * First step failure stops the run (later steps do not execute) and releases it `failed`
 * with the step error. All steps succeeding releases it `completed`. The active run is
 * published to WorkflowRunContext for the duration and cleared in a finally (the seam Batch
 * 3's dispatcher reads to origin-tag workflow-authored re-triggers).
 */
class WorkflowStepRunner
{
    public function __construct(
        private WorkflowStepFactory $steps,
        private WorkflowVariableResolver $resolver,
        private WorkflowRunManager $runManager,
        private WorkflowRunContext $runContext,
        private WorkflowVariableCatalogService $catalog,
    ) {}

    public function run(WorkflowRun $run): void
    {
        $run->loadMissing('workflow');
        $workflow = $run->workflow;
        $definition = $workflow?->steps ?? [];

        // The context steps read: `trigger` = the run's trigger payload, `steps` grows as
        // each step returns its output, `globals` = the workspace's user-created LITERAL constants
        // as a `{<key>: <value>}` map (form-independent, resolvable in every workflow). The run
        // executes with the workspace active (QueueTenancy), so globalValues() is workspace-scoped.
        $context = [
            'trigger' => $run->trigger_payload ?? [],
            'steps' => [],
            'globals' => $this->catalog->globalValues(),
        ];

        // The workspace's custom FUNCTIONS (workspace-scoped like globalValues), fetched once. They ride
        // the resolver/executor context under FunctionScope so a `fn:<uuid>` op in a step config resolves +
        // executes — but are kept OUT of the persisted $context (a VO is not JSON), by threading them only
        // into a per-step $execContext while $context stays the clean, persistable run state.
        $functions = $this->catalog->customFunctionOperations();

        // The run's path → variable-type map lets the resolver execute directive / if-block
        // pipelines against each reference's REAL type (recovered from the catalog, not the
        // degraded editor primitive). Built once for the whole run.
        $typeMap = $workflow !== null ? $this->catalog->runtimeTypeMap($workflow) : [];

        $this->runContext->set($run);

        try {
            foreach (array_values($definition) as $position => $step) {
                $key = (string) ($step['key'] ?? $position);
                $type = (string) ($step['type'] ?? '');
                $rawConfig = is_array($step['config'] ?? null) ? $step['config'] : [];

                // Thread the functions into the context the resolver + step see (never the persisted one).
                $execContext = $functions === [] ? $context : FunctionScope::forFunctions($functions)->writeInto($context);

                try {
                    $config = $this->resolver->resolve($rawConfig, $execContext, $typeMap);
                    $output = $this->steps->makeFromValue($type)->run($config, $run, $execContext);
                } catch (Throwable $e) {
                    $this->recordStep($run, $position, $type, $key, WorkflowRunStepStatus::FAILED, null, $e->getMessage());
                    $this->runManager->release($run, WorkflowRunState::FAILED, $e->getMessage());

                    return;
                }

                $this->recordStep($run, $position, $type, $key, WorkflowRunStepStatus::SUCCEEDED, $output, null);

                // Publish this step's output so later steps can `{{steps.<key>.*}}` it, and
                // persist the accumulated context on the run as it advances.
                $context['steps'][$key] = $output;
                $run->update(['context' => $context]);
            }

            $this->runManager->release($run, WorkflowRunState::COMPLETED);
        } finally {
            $this->runContext->clear();
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function recordStep(
        WorkflowRun $run,
        int $position,
        string $type,
        string $key,
        WorkflowRunStepStatus $status,
        ?array $payload,
        ?string $error,
    ): void {
        WorkflowRunStep::create([
            'workflow_run_id' => $run->id,
            'position' => $position,
            'type' => $type,
            'key' => $key,
            'status' => $status,
            'payload' => $payload,
            'error' => $error,
        ]);
    }
}
