<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Modules\Bot\Models\Bot;
use App\Modules\Workflows\Models\WorkflowRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The AUTHORITATIVE, discriminated-union shape for a record's polymorphic creator
 * (App\Traits\HasCreator::creator morphTo -> User | WorkflowRun | Bot). Phase 3 (frontend)
 * mirrors the `type` tag and the per-type fields VERBATIM, so keep the field names STABLE.
 *
 * Always presented as `CreatorResource::make($model->whenLoaded('creator'))`, which relies on
 * Laravel's nested-resource null handling (ConditionallyLoadsAttributes::filter):
 *   - creator relation NOT loaded -> key OMITTED from the response;
 *   - creator loaded but NULL     -> key serializes to `null` (a legacy / unattributed row);
 *   - User        -> { type: 'user',         id, name, email, avatar }   (mirrors UserResource)
 *   - WorkflowRun -> { type: 'workflow_run', id, run_id, label }         (label = automation name)
 *   - Bot         -> { type: 'bot',          id, name, avatar }
 *
 * The `label` for a run is the parent workflow's name; eager-load it via a morphWith on the
 * creator relation (WorkflowRun => ['workflow']) to avoid an N+1 on list endpoints.
 */
class CreatorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $creator = $this->resource;

        return match (true) {
            $creator instanceof User => [
                'type' => 'user',
                'id' => $creator->id,
                'name' => $creator->name,
                'email' => $creator->email,
                'avatar' => null,
            ],
            $creator instanceof WorkflowRun => [
                'type' => 'workflow_run',
                'id' => $creator->id,
                'run_id' => $creator->id,
                'label' => $creator->workflow?->name,
            ],
            $creator instanceof Bot => [
                'type' => 'bot',
                'id' => $creator->id,
                'name' => $creator->name,
                'avatar' => null,
            ],
            // Defensive: an unknown/unresolved morph target. (null is handled upstream as `null`.)
            default => [],
        };
    }
}
