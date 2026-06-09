<?php

namespace App\Modules\Auth\Http\Resources;

use App\Modules\Users\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'name' => $this->name,
            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->permissions->pluck('permission')->values()
            ),
            'users' => UserResource::collection($this->whenLoaded('users')),
            'created_at' => $this->created_at,
        ];
    }
}
