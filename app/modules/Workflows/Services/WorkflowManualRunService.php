<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Forms\Models\FormSubmission;
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
        if ($this->dispatcher->capReached($workflow)) {
            throw ValidationException::withMessages([
                'workflow' => ['Ten workflow osiągnął limit uruchomień na ten miesiąc. Uruchomienie zostało zablokowane.'],
            ]);
        }

        return $this->dispatcher->dispatchManual($workflow, $payload, $creatorId);
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
