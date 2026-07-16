<?php

namespace App\Modules\Workflows\Http\Resources;

use App\Http\Resources\CreatorResource;
use App\Modules\Workflows\Services\LegacyScheduleUpgrader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full workflow DEFINITION shape (detail / after-write). Exposes the complete config plus
 * capability flags. Scheduling fields (next_due_at, last_scheduled_run_at) are surfaced
 * now but only become meaningful once the scheduler ships.
 *
 * READ-SHIM: a schedule's `trigger_config.schedule` is upgraded to the v2 compositional descriptor
 * on the way out (LegacyScheduleUpgrader), so the FE editor always seeds from ONE shape even for a
 * row written before the schedule rebuild — no data migration, no dual FE code path.
 */
class WorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'description' => $this->description,
            'icon' => $this->icon,

            // Trigger definition (schedule block upgraded to v2 for the FE editor seed).
            'trigger_type' => $this->trigger_type,
            'trigger_config' => $this->triggerConfig(),

            // Optional gate conditions and the ordered step list.
            'conditions' => $this->conditions ?? [],
            'steps' => $this->steps ?? [],

            // Scheduling (populated by the scheduler in a later batch).
            'last_scheduled_run_at' => $this->last_scheduled_run_at?->toISOString(),
            'next_due_at' => $this->next_due_at?->toISOString(),

            'creator' => CreatorResource::make($this->whenLoaded('creator')),

            // Capability flags (mirrors the Bot/Approvals convention).
            'is_owner' => $this->isOwnedBy($request->user()),
            'can_be_edited' => $request->user()?->can('update', $this->resource) ?? false,
            'can_be_deleted' => $request->user()?->can('delete', $this->resource) ?? false,
            'can_change_status' => $request->user()?->can('changeStatus', $this->resource) ?? false,
            'can_run' => $request->user()?->can('run', $this->resource) ?? false,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * The trigger config with a legacy schedule block upgraded to v2. A non-schedule config (no
     * `schedule` key) passes through untouched; the upgrader is idempotent on an already-v2 block.
     *
     * @return array<string, mixed>
     */
    private function triggerConfig(): array
    {
        $config = $this->trigger_config ?? [];

        if (is_array($config['schedule'] ?? null)) {
            $config['schedule'] = app(LegacyScheduleUpgrader::class)->toV2($config['schedule']);
        }

        return $config;
    }
}
