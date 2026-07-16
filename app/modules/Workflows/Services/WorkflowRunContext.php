<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Models\WorkflowRun;

/**
 * In-process holder for the workflow run CURRENTLY executing on this worker (shape mirrors
 * TenantContext). The step runner sets it around a run and clears it in a finally, so any
 * code reached while steps execute can tell it is running inside a workflow.
 *
 * The trigger dispatcher READS this via `current()`: when a change authored by a workflow
 * step matches another workflow's trigger, the dispatcher origin-tags the re-trigger —
 * incrementing depth and refusing runaway loops past config('workflows.max_depth'). With
 * only schedule/form_submitted triggers no MVP step can re-trigger, but the gate stays live
 * (and tested) for future triggers. Registered as a singleton in the module provider.
 */
class WorkflowRunContext
{
    private ?WorkflowRun $run = null;

    public function set(WorkflowRun $run): void
    {
        $this->run = $run;
    }

    public function clear(): void
    {
        $this->run = null;
    }

    public function current(): ?WorkflowRun
    {
        return $this->run;
    }

    public function hasRun(): bool
    {
        return $this->run !== null;
    }

    public function id(): ?string
    {
        return $this->run?->id;
    }

    public function depth(): ?int
    {
        return $this->run?->depth;
    }
}
