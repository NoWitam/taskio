<?php

namespace App\Tenancy;

use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;

/**
 * Lets a queued job carry the dispatching request's workspace across the queue
 * boundary. Call rememberTenant() when constructing the job, and add the
 * RestoreTenantContext middleware so the context is re-applied before handle().
 */
trait InteractsWithTenantContext
{
    public ?string $tenantWorkspaceId = null;

    public function rememberTenant(): static
    {
        $this->tenantWorkspaceId = app(TenantContext::class)->id();

        return $this;
    }

    public function restoreTenant(): void
    {
        if ($this->tenantWorkspaceId === null) {
            return;
        }

        $workspace = Workspace::find($this->tenantWorkspaceId);

        if ($workspace === null) {
            return;
        }

        app(TenantContext::class)->set($workspace);

        if ($workspace->db_mode === WorkspaceDbMode::Own) {
            app(TenantManager::class)->configure($workspace);
        }
    }
}
