<?php

namespace App\Modules\Workspaces\Http\Resources;

use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class WorkspaceMemberResource extends JsonResource
{
    public function __construct($resource, private readonly Workspace $workspace)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Resolved in PHP against the already-loaded owner_id — no extra query.
            'is_owner' => $this->id === $this->workspace->owner_id,
        ];
    }
}
