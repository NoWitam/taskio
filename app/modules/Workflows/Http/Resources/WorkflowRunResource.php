<?php

namespace App\Modules\Workflows\Http\Resources;

use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Support\Recurrence\LegacyScheduleUpgrader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workflow RUN (one execution of a workflow's steps). One resource serves three shapes:
 *
 *   - manual-run 202 (Batch 3): the stable core (id/state/origin/trigger_type/created_at)
 *     plus timing/error — enough to identify and poll the run just started. `steps_count`
 *     is absent (not counted), `steps`/`trigger_payload` are absent (not loaded).
 *   - run INDEX (Batch 4): the core + `steps_count` (via withCount) + `duration_seconds`.
 *     The potentially-large `trigger_payload` and the `steps` collection are EXCLUDED so
 *     the list stays lean (see the payload-on-index decision in the batch notes). The GLOBAL
 *     feed additionally carries a `workflow` block (id/name/icon/status/trigger_type), gated
 *     on the `workflow` relation being eager-loaded — absent on the per-workflow index.
 *   - run SHOW (Batch 4): everything, including `trigger_payload` and the ordered `steps`
 *     timeline. Both are gated on the `steps` relation being eager-loaded, which only the
 *     show endpoint does. A SCHEDULE run also carries `schedule_descriptor` — the workflow's
 *     full v2 schedule block (incl. `tz`) — so the global drawer can explain its cadence.
 *
 * state/status carry their label + tone so the frontend StatusBadge map renders directly.
 *
 * @mixin WorkflowRun
 */
class WorkflowRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'state_tone' => $this->state->tone(),
            'origin' => $this->origin->value,
            'trigger_type' => $this->trigger_type,
            'depth' => $this->depth,
            'origin_run_id' => $this->origin_run_id,
            'error' => $this->error,
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),

            // Wall-clock run duration; null unless the run both started and finished.
            'duration_seconds' => $this->durationSeconds(),

            // Present only on the index (withCount('steps')); absent on the 202 + show.
            'steps_count' => $this->whenCounted('steps'),

            // Workflow context for a row. Present ONLY when the run's `workflow` relation is
            // eager-loaded — i.e. on the GLOBAL feed (with('workflow')); absent on the
            // per-workflow index/202/show, where the caller already has the workflow.
            'workflow' => $this->whenLoaded('workflow', fn () => [
                'id' => $this->workflow->id,
                'name' => $this->workflow->name,
                'icon' => $this->workflow->icon,
                'status' => $this->workflow->status->value,
                'trigger_type' => $this->workflow->trigger_type->value,
            ]),

            // Detail-only (steps eager-loaded => show endpoint). trigger_payload may be
            // large, so it rides with the detail, not the list.
            'trigger_payload' => $this->when($this->relationLoaded('steps'), fn () => $this->trigger_payload),
            'steps' => WorkflowRunStepResource::collection($this->whenLoaded('steps')),

            // Detail-only, form_submitted runs only: the trigger form resolved LIVE (name +
            // description + icon), so the drawer renders a lean form item WITHOUT a second FE call.
            // Absent when the form was deleted/foreign — the FE falls back to trigger_payload.form.
            'form' => $this->when(
                $this->relationLoaded('triggerForm') && $this->triggerForm !== null,
                fn () => [
                    'id' => $this->triggerForm->id,
                    'name' => $this->triggerForm->name,
                    'description' => $this->triggerForm->description,
                    'icon' => $this->triggerForm->icon,
                ],
            ),

            // Detail-only, SCHEDULE runs only: the workflow's full v2 schedule descriptor
            // (incl. `tz`), so the global drawer can compute a schedule run's "reason" without
            // a workflow loaded on the row. Data only, no prose. Upgraded to v2 on the way out
            // (same read-shim as WorkflowResource) so a legacy row still emits one shape.
            'schedule_descriptor' => $this->when(
                $this->relationLoaded('steps') && $this->isScheduleRun(),
                fn () => $this->scheduleDescriptor(),
            ),
        ];
    }

    /** Whether this run was started by a schedule trigger (the run's trigger_type is a plain string). */
    private function isScheduleRun(): bool
    {
        return $this->trigger_type === WorkflowTriggerType::SCHEDULE->value;
    }

    /**
     * The parent workflow's v2 schedule descriptor, or null when the workflow is not loaded
     * or carries no schedule block. Upgraded via LegacyScheduleUpgrader so a pre-rebuild row
     * emits the same shape (incl. `tz`) the FE editor sees.
     *
     * @return array<string, mixed>|null
     */
    private function scheduleDescriptor(): ?array
    {
        if (!$this->relationLoaded('workflow') || $this->workflow === null) {
            return null;
        }

        $schedule = $this->workflow->trigger_config['schedule'] ?? null;

        if (!is_array($schedule)) {
            return null;
        }

        return app(LegacyScheduleUpgrader::class)->toV2($schedule);
    }

    /** Wall-clock seconds between started_at and finished_at, or null if either is unset. */
    private function durationSeconds(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return $this->started_at->diffInSeconds($this->finished_at);
    }
}
