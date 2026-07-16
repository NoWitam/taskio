<?php

namespace App\Tenancy;

use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Models\Workspace;

/**
 * Holds the workspace (tenant) active for the current request. Registered as a
 * singleton; the ResolveWorkspace middleware populates it from the
 * X-Workspace-Id header, and tenant-aware models read it to scope/route queries.
 */
class TenantContext
{
    private ?Workspace $workspace = null;

    public function set(Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function clear(): void
    {
        $this->workspace = null;
    }

    public function workspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function id(): ?string
    {
        return $this->workspace?->id;
    }

    public function hasWorkspace(): bool
    {
        return $this->workspace !== null;
    }

    public function isShared(): bool
    {
        return $this->workspace?->db_mode === WorkspaceDbMode::Shared;
    }

    public function isOwn(): bool
    {
        return $this->workspace?->db_mode === WorkspaceDbMode::Own;
    }
}
