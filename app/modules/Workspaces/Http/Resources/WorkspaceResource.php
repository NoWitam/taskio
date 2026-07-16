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
