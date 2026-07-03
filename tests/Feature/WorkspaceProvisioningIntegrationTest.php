<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Modules\Workspaces\Services\WorkspaceProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Real-DDL provisioning check. GUARDED: only runs when TENANT_DB_TESTS=1 because
 * it issues CREATE DATABASE / DROP DATABASE against the live Postgres server.
 *
 * It deliberately does NOT use RefreshDatabase: Postgres forbids CREATE DATABASE
 * inside a transaction, and the test must create the workspace row, the tenant
 * database, then drop ONLY that generated `tenant_<hex>` database. It never
 * touches the central application database.
 */
class WorkspaceProvisioningIntegrationTest extends TestCase
{
    private ?Workspace $workspace = null;

    private ?string $tenantDatabase = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('TENANT_DB_TESTS') !== '1') {
            $this->markTestSkipped('Set TENANT_DB_TESTS=1 to run real tenant-database provisioning (issues DDL).');
        }

        if (config('database.connections.' . config('database.default') . '.driver') !== 'pgsql') {
            $this->markTestSkipped('Tenant-database provisioning integration test requires the pgsql driver.');
        }
    }

    public function test_provisioning_creates_the_tenant_database_and_runs_migrations(): void
    {
        $user = User::factory()->create();

        $this->workspace = Workspace::factory()->create([
            'owner_id' => $user->id,
            'db_mode' => 'own',
            'status' => 'provisioning',
        ]);

        $provisioner = app(WorkspaceProvisioner::class);
        $this->tenantDatabase = $provisioner->databaseName($this->workspace);

        $provisioner->provision($this->workspace);

        $schema = Schema::connection(TenantManager::CONNECTION);

        // The full own-mode domain schema mirrors the central TenantAware tables.
        $expectedTables = [
            'labels',
            'labelables',
            'tasks',
            'forms',
            'form_content_versions',
            'form_submissions',
            'form_reports',
            'approval_pipelines',
            'approval_stages',
            'approval_processes',
            'comments',
            'files',
            'filter_tabs',
        ];

        foreach ($expectedTables as $table) {
            $this->assertTrue(
                $schema->hasTable($table),
                "Expected the {$table} table to exist on the provisioned tenant database."
            );

            // The whole database belongs to one workspace — no per-row scoping column.
            $this->assertFalse(
                $schema->hasColumn($table, 'workspace_id'),
                "Tenant table {$table} must not carry a workspace_id column."
            );
        }

        // Spot-check that intra-tenant links survive on tasks but workspace_id does not.
        $this->assertTrue($schema->hasColumn('tasks', 'form_id'));
        $this->assertTrue($schema->hasColumn('tasks', 'approval_pipeline_id'));
        $this->assertTrue($schema->hasColumn('tasks', 'creator_id'));
        // The single-target assigned_id was replaced by the polymorphic assignee pair.
        $this->assertFalse($schema->hasColumn('tasks', 'assigned_id'));
        $this->assertTrue($schema->hasColumn('tasks', 'assignee_type'));
        $this->assertTrue($schema->hasColumn('tasks', 'assignee_id'));

        // Spot-check the form versioning links that own-mode forms depend on.
        $this->assertTrue($schema->hasColumn('form_submissions', 'form_content_version_id'));
        $this->assertTrue($schema->hasColumn('form_content_versions', 'parent_id'));
    }

    protected function tearDown(): void
    {
        // Drop ONLY the generated tenant database — never the central app DB.
        if ($this->tenantDatabase !== null) {
            app(TenantManager::class)->forget();

            $default = config('database.default');
            DB::connection($default)->statement('drop database if exists "' . $this->tenantDatabase . '"');
        }

        if ($this->workspace !== null) {
            $this->workspace->forceDelete();
            $this->workspace->owner?->forceDelete();
        }

        parent::tearDown();
    }
}
