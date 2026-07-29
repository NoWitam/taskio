<?php

namespace App\Modules\Workflows\Steps;

use App\Modules\Workflows\Models\WorkflowRun;

/**
 * A step that can PARK its run while external work settles. Its `run()` starts that work and throws
 * {@see \App\Modules\Workflows\Exceptions\StepSuspended}; much later — after the work settled — the
 * runner calls `resume()` at exactly the same position, and the step collects the outcome and
 * publishes its outputs like any other step.
 *
 * The base {@see WorkflowStep} contract is deliberately UNTOUCHED: a plain step knows nothing about
 * suspension, and the runner only looks for this interface at the one position a resume targets.
 *
 * CONTRACT for implementers:
 * - `resume()` runs in a FRESH process. Everything it needs must be reachable from the run row, the
 *   rebuilt context, or `$wait['payload']` — never from instance state carried across the wait.
 * - It receives the EXACT `$config` `run()` was given: the resolved value is captured at suspend and
 *   REPLAYED here, never resolved a second time. So a spend-incurring directive (`@[ai-text]`) in a
 *   suspendable step's config is paid once, and the outcome is collected under the very config the
 *   external work was started with. (Everything AFTER the wait is still re-read live — see
 *   WorkflowStepRunner.) A wait parked before this became true falls back to re-resolution.
 * - Like `run()`, it MUST throw a clear RuntimeException when the settled work is unusable; the
 *   runner records that as a normal step failure and fails the run.
 * - It may throw StepSuspended AGAIN to park the run once more (e.g. a multi-leg wait).
 */
interface SuspendableWorkflowStep extends WorkflowStep
{
    /**
     * @param  array<string, mixed>  $config  the step config with all references resolved
     * @param  array<string, mixed>  $context  the run context rebuilt from the database
     * @param  array<string, mixed>  $wait  the persisted `waiting_on` record (kind, step_key,
     *                                      step_type, position, payload)
     * @return array<string, mixed> this step's output, merged under steps.<key>
     */
    public function resume(array $config, WorkflowRun $run, array $context, array $wait): array;
}
