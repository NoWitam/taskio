<?php

namespace App\Modules\Workspaces\Console;

use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use Illuminate\Console\Command;

class ProvisionWorkspace extends Command
{
    protected $signature = 'workspace:provision {workspace : The workspace id}';

    protected $description = 'Run the tenant migrations for an own-database workspace.';

    public function handle(TenantManager $tenants): int
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

        $tenants->configure($workspace);

        $this->info("Migrating tenant database for workspace {$workspace->id}...");

        // The tenant database must already exist; this runs the own-mode schema.
        $this->call('migrate', [
            '--database' => TenantManager::CONNECTION,
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        return self::SUCCESS;
    }
}
