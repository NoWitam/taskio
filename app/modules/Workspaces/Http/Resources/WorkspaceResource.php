<?php

namespace App\Modules\Workspaces\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkspaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'db_mode' => $this->db_mode->value,
            'status' => $this->status->value,
            'is_owner' => $request->user() !== null && $this->owner_id === $request->user()->id,
            'can_manage_members' => $request->user()?->can('manageMembers', $this->resource) ?? false,
            // AI budget (R2 sub-stage 4). ai_cap is the raw per-workspace override (null = inherit the env
            // default; 0 = explicit unlimited); the effective cap + usage live on the ai-usage summary.
            'ai_cap' => $this->ai_monthly_cost_cap !== null ? (float) $this->ai_monthly_cost_cap : null,
            'can_manage_ai_budget' => $request->user()?->can('manageAiBudget', $this->resource) ?? false,
            // The workspace's IANA timezone (R3 Calendar). NULL is meaningful and is sent as null: it
            // says "inherit the application timezone", which is not the same statement as any concrete
            // identifier and must stay distinguishable in the settings UI. The EFFECTIVE zone actually
            // used to draw a grid is reported per-response in the calendar's own `meta.timezone`.
            'timezone' => $this->timezone,
            // Uses the eager-loaded users_count when present (withCount), otherwise
            // falls back to a lightweight count query — never loads the collection.
            // Bypass the User member scope: this counts THIS workspace's members, but
            // the workspace shown is often not the active one (e.g. the workspace
            // list rendered while a different workspace is active), so the scope
            // would otherwise narrow the count to the active workspace's members.
            'member_count' => $this->whenCounted(
                'users',
                default: fn () => $this->users()->withoutWorkspaceMemberScope()->count()
            ),
            'created_at' => $this->created_at,
        ];
    }
}
