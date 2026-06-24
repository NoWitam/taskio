<?php

namespace App\Modules\Workspaces\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public, unauthenticated invitation preview. Minimal by design: no ids beyond
 * what the accept page needs, never the token/hash. `valid` and `account_exists`
 * are supplied by the service (passed via the resource constructor).
 *
 * @mixin \App\Modules\Workspaces\Models\WorkspaceInvitation
 */
class InvitationPreviewResource extends JsonResource
{
    public function __construct(
        $resource,
        private readonly bool $valid,
        private readonly bool $accountExists,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'workspace_name' => $this->workspace?->name,
            'invited_by_name' => $this->invitedBy?->name,
            'email' => $this->email,
            'status' => $this->status->value,
            'valid' => $this->valid,
            'account_exists' => $this->accountExists,
        ];
    }
}
