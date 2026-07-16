<?php

namespace App\Modules\Workflows\Http\Resources;

use App\Modules\Workflows\Models\WorkflowRunStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One executed step within a workflow run (audit row), shaped for the run-detail timeline.
 * status carries its label/tone so the frontend StatusBadge map can render it directly.
 * payload is the step's resolved output (null until it runs / on failure); error is set
 * only on a failed step. Steps are always returned ordered by `position` (the model's
 * `steps` relation applies the order).
 *
 * @mixin WorkflowRunStep
 */
class WorkflowRunStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'type' => $this->type->value,
            'key' => $this->key,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->tone(),
            'payload' => $this->payload,
            'error' => $this->error,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
