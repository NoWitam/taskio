<?php

namespace App\Modules\Workspaces\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-facing invitation shape. Deliberately never exposes the token or its
 * hash — only the metadata an owner needs to manage pending invites.
 *
 * @mixin \App\Modules\Workspaces\Models\WorkspaceInvitation
 */
class WorkspaceInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'status' => $this->status->value,
            'invited_by' => [
                'id' => $this->invitedBy?->id,
                'name' => $this->invitedBy?->name,
            ],
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
        ];
    }
}
