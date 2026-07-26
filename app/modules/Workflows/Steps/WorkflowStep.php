<?php

namespace App\Modules\Workflows\Steps;

use App\Modules\Variables\Enums\VariableType;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Models\WorkflowRun;

/**
 * Contract for a single workflow step. A step is ONE unit of work a workflow performs; it
 * acts ONLY through existing domain services (TaskService, ApprovalService, …) — never raw
 * model writes that bypass business logic.
 *
 * The runner passes an already-RESOLVED config (all `{{...}}` references replaced) plus the
 * live run and the full accumulated context. A step returns its OUTPUT array, which the
 * runner merges into the context under `steps.<key>` so later steps can reference it.
 *
 * A step that cannot proceed (a required id missing, a resolved reference that produced
 * null) MUST throw a clear RuntimeException; the runner records that as a step failure and
 * stops the run.
 */
interface WorkflowStep
{
    public function type(): WorkflowStepType;

    /**
     * @param  array<string, mixed>  $config  the step config with all references resolved
     * @param  array<string, mixed>  $context  the full run context (trigger + prior steps)
     * @return array<string, mixed> this step's output, merged under steps.<key>
     */
    public function run(array $config, WorkflowRun $run, array $context): array;

    /**
     * The OUTPUT keys this step publishes under `steps.<key>.*`, each with its workflow type —
     * the single source the variable catalog reads so a step's outputs become reference-able
     * variables (`steps.<key>.<name>`) WITHOUT the catalog knowing each step's internals. B4
     * adds new step outputs by implementing this on the new step class only.
     *
     * @return array<int, array{name: string, type: VariableType}>
     */
    public static function outputDescriptors(): array;
}
