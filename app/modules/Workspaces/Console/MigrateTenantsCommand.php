<?php

namespace App\Modules\Workspaces\Console;

use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Modules\Workspaces\Services\WorkspaceProvisioner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs the tenant schema against EVERY existing own-database workspace.
 *
 * WorkspaceProvisioner only migrates a tenant once, at provision time — so a tenant database
 * created last month never receives migrations added since. Without this command every new
 * tenant migration silently applies to new workspaces only, and the first query touching a
 * missing table blows up in production for everyone else. Run it after deploying any
 * migration under database/migrations/tenant.
 *
 * Mirrors the sweep commands: one workspace's failure is logged and skipped, never fatal to
 * the rest. Idempotent — migrations already applied are tracked per tenant database.
 */
class MigrateTenantsCommand extends Command
{
    protected $signature = 'tenants:migrate {--workspace= : Migrate a single workspace by id}';

    protected $description = 'Run the tenant schema against every own-database workspace (or one, with --workspace).';

    public function handle(WorkspaceProvisioner $provisioner, TenantManager $tenants): int
    {
        $workspaces = $this->targets();

        if ($workspaces->isEmpty()) {
            $this->info('No own-database workspaces to migrate.');

            return self::SUCCESS;
        }

        $migrated = 0;
        $failed = 0;

        foreach ($workspaces as $workspace) {
            try {
                // Ready implies a provisioned database (ProvisionWorkspaceJob only flips the
                // status after createDatabase persisted its name), so connectionConfig() can
                // describe the tenant. A provisioning/failed workspace has nothing to migrate.
                $provisioner->migrate($workspace);
                $migrated++;

                $this->line("  migrated: {$workspace->name} [{$workspace->id}]");
            } catch (Throwable $e) {
                $failed++;

                $this->error("  FAILED: {$workspace->name} [{$workspace->id}] — {$e->getMessage()}");
            }
        }

        $tenants->forget();

        $this->info("Tenant migrations: {$migrated} migrated, {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return \Illuminate\Support\Collection<int, Workspace> */
    private function targets()
    {
        $query = Workspace::query()
            ->where('db_mode', WorkspaceDbMode::Own)
            ->where('status', WorkspaceStatus::Ready);

        if ($id = $this->option('workspace')) {
            $query->whereKey($id);
        }

        return $query->get();
    }
}
