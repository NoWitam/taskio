<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates a MANUAL workflow run: it resolves the caller-supplied target through the
 * TENANT-SCOPED model (never trusting the raw id — a cross-workspace / unknown id 422s),
 * builds the SAME whitelisted payload a real trigger would (via WorkflowTriggerPayloadFactory,
 * so `{{trigger.*}}` behaves identically), enforces the run-budget cap as a 422, and hands off
 * to WorkflowDispatchService::dispatchManual. The DB::afterCommit deferral of the run's job
 * lives in start() (no open transaction here, so it fires immediately — one afterCommit layer).
 *
 * Target requirement by trigger type:
 *   form_submitted  -> FormSubmission id (its form/task/fields derived; required)
 *   schedule        -> no target; minimal {scheduled_at} payload
 */
class WorkflowManualRunService
{
    public function __construct(
        private WorkflowTriggerPayloadFactory $payloads,
        private WorkflowDispatchService $dispatcher,
    ) {}

    /**
     * Build the payload for $workflow's trigger type from $targetId, then start a manual run
     * as $creatorId. Throws a 422 ValidationException on an unresolvable/absent target or a
     * reached cap.
     */
    public function run(Workflow $workflow, ?string $targetId, string $creatorId): WorkflowRun
    {
        $payload = $this->buildPayload($workflow->trigger_type, $targetId);

        // Manual runs COUNT toward caps and are REFUSED (not silently skipped) at the ceiling.
        $this->guardCap($workflow);

        return $this->dispatcher->dispatchManual($workflow, $payload, $creatorId);
    }

    /**
     * Retry a FAILED run: start a NEW manual run for the SAME workflow reusing the failed run's
     * STORED trigger_payload. There is no mid-run resume — WorkflowStepRunner always runs from the
     * first step — so a "retry" is a fresh run over the same trigger context (the form/submission
     * snapshot, or the schedule's scheduled_at), not a resume.
     *
     * Only a TERMINAL FAILED run may be retried; a pending/running/waiting/completed/cancelled run
     * is a 422 (a completed run is not "with an error"). Honors the SAME run-budget cap as run-now
     * (dispatchManual itself does not enforce it), and attributes the new run to the acting user as
     * a MANUAL run (reusing WorkflowRunOrigin::MANUAL — a user re-triggered it).
     */
    public function retry(WorkflowRun $run, string $creatorId): WorkflowRun
    {
        if ($run->state !== WorkflowRunState::FAILED) {
            throw ValidationException::withMessages([
                'run' => ['Ponowić można tylko uruchomienie zakończone błędem.'],
            ]);
        }

        $workflow = $run->workflow;

        $this->guardCap($workflow);

        return $this->dispatcher->dispatchManual($workflow, $run->trigger_payload ?? [], $creatorId);
    }

    /**
     * Refuse the run with a 422 when the run budget is reached — the per-workflow monthly soft
     * cap or the workspace-wide hard cap (WorkflowDispatchService::capReached). Shared by run-now
     * and retry so both surface the identical ceiling message rather than drifting apart.
     */
    private function guardCap(Workflow $workflow): void
    {
        if ($this->dispatcher->capReached($workflow)) {
            throw ValidationException::withMessages([
                'workflow' => ['Ten workflow osiągnął limit uruchomień na ten miesiąc. Uruchomienie zostało zablokowane.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(WorkflowTriggerType $type, ?string $targetId): array
    {
        return match ($type) {
            WorkflowTriggerType::FORM_SUBMITTED => $this->payloads->fromFormSubmission($this->requireSubmission($targetId)),
            WorkflowTriggerType::SCHEDULE => $this->payloads->fromSchedule(),
        };
    }

    /** Load a FormSubmission through the tenant scope; an unknown id is a 422. */
    private function requireSubmission(?string $targetId): FormSubmission
    {
        $submission = $targetId ? FormSubmission::find($targetId) : null;

        if ($submission === null) {
            throw ValidationException::withMessages([
                'target_id' => ['Wskazane wysłanie formularza nie istnieje w tej przestrzeni roboczej.'],
            ]);
        }

        return $submission;
    }
}
