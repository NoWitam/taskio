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
            'is_owner' => $request->user() !== null && $this->owner_id === $request->user()->id,
            'created_at' => $this->created_at,
        ];
    }
}
