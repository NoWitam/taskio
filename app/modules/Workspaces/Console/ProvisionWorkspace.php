<?php

namespace App\Modules\Workspaces\Console;

use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\WorkspaceProvisioner;
use Illuminate\Console\Command;
use Throwable;

class ProvisionWorkspace extends Command
{
    protected $signature = 'workspace:provision {workspace : The workspace id}';

    protected $description = 'Create and migrate the tenant database for an own-database workspace.';

    public function handle(WorkspaceProvisioner $provisioner): int
    {
        $workspace = Workspace::find($this->argument('workspace'));

        if ($workspace === null) {
            $this->error('Workspace not found.');

            return self::FAILURE;
        }

        if ($workspace->db_mode !== WorkspaceDbMode::Own) {
            $this->error('Workspace is not in own-database mode.');

            return self::FAILURE;
        }

        $this->info("Provisioning tenant database for workspace {$workspace->id}...");

        try {
            $provisioner->provision($workspace);

            $workspace->forceFill(['status' => WorkspaceStatus::Ready])->save();
        } catch (Throwable $e) {
            $workspace->forceFill(['status' => WorkspaceStatus::Failed])->save();

            $this->error("Provisioning failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
