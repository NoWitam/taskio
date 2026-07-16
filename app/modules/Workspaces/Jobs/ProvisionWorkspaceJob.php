<?php

namespace App\Modules\Workspaces\Jobs;

use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\WorkspaceProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Asynchronously creates and migrates the dedicated database for an own-mode
 * workspace, then flips its status to Ready (or Failed on error).
 *
 * Self-contained: it operates on the passed central Workspace row directly and
 * lets the provisioner configure the tenant connection, so it does not depend on
 * the dispatching request's TenantContext.
 */
class ProvisionWorkspaceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private Workspace $workspace,
    ) {}

    public function handle(WorkspaceProvisioner $provisioner): void
    {
        try {
            $provisioner->provision($this->workspace);

            $this->workspace->forceFill(['status' => WorkspaceStatus::Ready])->save();
        } catch (Throwable $e) {
            $this->workspace->forceFill(['status' => WorkspaceStatus::Failed])->save();

            // Rethrow so the failure is logged and the job can be retried.
            throw $e;
        }
    }
}
