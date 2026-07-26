<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\WorkspaceProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * `tenants:migrate` — the rollout path for tenant schema changes.
 *
 * WorkspaceProvisioner migrates a tenant database exactly once, at provision time, so every
 * migration added afterwards reaches NEW workspaces only. Without this command a tenant
 * database created before a release silently lacks the new tables and the first query against
 * one blows up in production. R1 adds `folders` + new `files` columns, so this is the batch
 * that has to ship it.
 *
 * The real DDL path (provisioner->migrate against a live tenant database) is covered by
 * WorkspaceProvisioningIntegrationTest; what is asserted here is the command's own contract:
 * WHICH workspaces it targets and that one bad tenant never aborts the rest.
 */
class MigrateTenantsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(WorkspaceDbMode $mode, WorkspaceStatus $status, string $name = 'ws'): Workspace
    {
        return Workspace::factory()->create([
            'owner_id' => User::factory(),
            'name' => $name,
            'db_mode' => $mode,
            'status' => $status,
            'db_database' => $mode === WorkspaceDbMode::Own && $status === WorkspaceStatus::Ready
                ? 'tenant_' . fake()->uuid()
                : null,
        ]);
    }

    public function test_it_migrates_every_ready_own_database_workspace(): void
    {
        $first = $this->workspace(WorkspaceDbMode::Own, WorkspaceStatus::Ready, 'own-a');
        $second = $this->workspace(WorkspaceDbMode::Own, WorkspaceStatus::Ready, 'own-b');

        $migrated = [];
        $this->mock(WorkspaceProvisioner::class)
            ->shouldReceive('migrate')->twice()
            ->andReturnUsing(function (Workspace $workspace) use (&$migrated) {
                $migrated[] = $workspace->id;
            });

        $this->artisan('tenants:migrate')->assertSuccessful();

        sort($migrated);
        $expected = [$first->id, $second->id];
        sort($expected);
        $this->assertSame($expected, $migrated);
    }

    public function test_it_skips_shared_and_unprovisioned_workspaces(): void
    {
        // Shared workspaces live in the central DB (already migrated by `migrate`), and a
        // provisioning/failed workspace has no tenant database to migrate at all —
        // connectionConfig() would refuse to describe it.
        $this->workspace(WorkspaceDbMode::Shared, WorkspaceStatus::Ready, 'shared');
        $this->workspace(WorkspaceDbMode::Own, WorkspaceStatus::Provisioning, 'still-provisioning');
        $this->workspace(WorkspaceDbMode::Own, WorkspaceStatus::Failed, 'failed');

        $this->mock(WorkspaceProvisioner::class)->shouldNotReceive('migrate');

        $this->artisan('tenants:migrate')
            ->expectsOutputToContain('No own-database workspaces to migrate.')
            ->assertSuccessful();
    }

    public function test_one_broken_tenant_does_not_abort_the_others(): void
    {
        $broken = $this->workspace(WorkspaceDbMode::Own, WorkspaceStatus::Ready, 'broken');
        $healthy = $this->workspace(WorkspaceDbMode::Own, WorkspaceStatus::Ready, 'healthy');

        $migrated = [];
        $this->mock(WorkspaceProvisioner::class)
            ->shouldReceive('migrate')
            ->andReturnUsing(function (Workspace $workspace) use (&$migrated, $broken) {
                if ($workspace->is($broken)) {
                    throw new RuntimeException('tenant database unreachable');
                }
                $migrated[] = $workspace->id;
            });

        // A deploy must not stop half-way through the estate: report the failure, keep going.
        $this->artisan('tenants:migrate')->assertFailed();

        $this->assertSame([$healthy->id], $migrated);
    }

    public function test_a_single_workspace_can_be_targeted(): void
    {
        $target = $this->workspace(WorkspaceDbMode::Own, WorkspaceStatus::Ready, 'target');
        $this->workspace(WorkspaceDbMode::Own, WorkspaceStatus::Ready, 'other');

        $this->mock(WorkspaceProvisioner::class)
            ->shouldReceive('migrate')->once()
            ->with(Mockery::on(fn (Workspace $w) => $w->is($target)));

        $this->artisan('tenants:migrate', ['--workspace' => $target->id])->assertSuccessful();
    }
}
