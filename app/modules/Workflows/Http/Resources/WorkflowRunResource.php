<?php

namespace App\Modules\Workflows\Http\Resources;

use App\Modules\Workflows\Models\WorkflowRun;
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
 *     the list stays lean (see the payload-on-index decision in the batch notes).
 *   - run SHOW (Batch 4): everything, including `trigger_payload` and the ordered `steps`
 *     timeline. Both are gated on the `steps` relation being eager-loaded, which only the
 *     show endpoint does.
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

            // Detail-only (steps eager-loaded => show endpoint). trigger_payload may be
            // large, so it rides with the detail, not the list.
            'trigger_payload' => $this->when($this->relationLoaded('steps'), fn () => $this->trigger_payload),
            'steps' => WorkflowRunStepResource::collection($this->whenLoaded('steps')),
        ];
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
