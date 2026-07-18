<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Enums\FileType;
use App\Modules\Disk\Models\File;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * TENANCY ISOLATION for a HALF-PROVISIONED own-database workspace.
 *
 * The hole this pins: an own-mode workspace is created `provisioning` with db_database still
 * NULL and is provisioned asynchronously. Until that finishes — FOREVER if the worker is down
 * or provisioning failed — connectionConfig() array_filter'd the NULLs away and returned the
 * SHARED connection, while WorkspaceScope deliberately does not constrain own-mode queries
 * (the whole database is supposed to BE the tenant). Net effect: activating such a workspace
 * pointed the "tenant" connection at the shared database with no workspace_id filter — an
 * unscoped read of every other workspace's rows, reachable by any authenticated user, since
 * creating an own-mode workspace only requires being logged in.
 *
 * Two independent defences are asserted here, because either alone would still leave a way in:
 *   1. the DOOR   — an unprovisioned workspace can never become the active tenant;
 *   2. the FLOOR  — connectionConfig() refuses to describe a tenant that has no database,
 *                   instead of silently handing back the shared one (this also covers the
 *                   console sweeps and queue workers, which configure() outside a request).
 *
 * Note on scope: this asserts the REFUSALS rather than re-proving the byte leak. Under
 * RefreshDatabase the separate `tenant` connection cannot see the test's uncommitted rows, so
 * an end-to-end read cannot be reproduced transactionally — the leak itself was reproduced
 * manually against a live DB; these tests pin the fixes that close it.
 */
class UnprovisionedWorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** An own-mode workspace that has NOT been provisioned yet (no dedicated database). */
    private function unprovisionedOwnWorkspace(User $owner, WorkspaceStatus $status = WorkspaceStatus::Provisioning): Workspace
    {
        $workspace = Workspace::factory()->create([
            'owner_id' => $owner->id,
            'db_mode' => WorkspaceDbMode::Own,
            'status' => $status,
            'db_database' => null,
        ]);
        $workspace->users()->attach($owner->id);

        return $workspace;
    }

    // ---- The door: ResolveWorkspace -------------------------------------------

    public function test_an_unprovisioned_own_workspace_cannot_become_the_active_tenant(): void
    {
        Storage::fake();

        // A victim's ordinary shared workspace, with a file in it.
        $victim = User::factory()->create();
        $victimWorkspace = Workspace::factory()->create(['owner_id' => $victim->id]);
        $victimWorkspace->users()->attach($victim->id);

        $context = app(TenantContext::class);
        $context->set($victimWorkspace);
        $file = File::create([
            'name' => 'secret.pdf',
            'path' => 'uploads/' . Str::uuid() . '.pdf',
            'type' => FileType::DOCUMENT,
            'mime_type' => 'application/pdf',
            'size' => 10,
            'uploader_id' => $victim->id,
        ]);
        $context->clear();
        Storage::put($file->path, 'victim-bytes');

        // The attacker only has to be logged in to own an own-mode workspace.
        $attacker = User::factory()->create();
        $attackerWorkspace = $this->unprovisionedOwnWorkspace($attacker);

        $this->assertFalse($victimWorkspace->hasMember($attacker), 'Guard: the attacker is a stranger to the victim workspace.');

        $response = $this->actingAs($attacker)
            ->withHeader('X-Workspace-Id', $attackerWorkspace->id)
            ->getJson("/api/disk/{$file->id}");

        $response->assertStatus(409);
        $this->assertStringNotContainsString('victim-bytes', $response->baseResponse->getContent());
    }

    public function test_activating_an_unprovisioned_own_workspace_never_aims_the_tenant_connection_at_the_shared_database(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->unprovisionedOwnWorkspace($owner);

        $sharedDatabase = config('database.connections.' . config('database.default') . '.database');

        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/tasks');

        // THE MECHANISM, asserted directly: ResolveWorkspace used to hand this workspace to
        // TenantManager::configure(), which asked connectionConfig() for a description of a
        // tenant that has no database — and got the shared one back. Every tenant-aware model
        // then routed to `tenant` (= the shared DB) with WorkspaceScope inert for own-mode.
        // A byte-level read cannot be reproduced under RefreshDatabase (a second connection
        // cannot see this test's open transaction), but the misaimed connection can.
        $this->assertNotSame(
            $sharedDatabase,
            config('database.connections.tenant.database'),
            'The tenant connection must never resolve to the shared database.',
        );
    }

    public function test_a_failed_own_workspace_cannot_become_the_active_tenant_either(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->unprovisionedOwnWorkspace($owner, WorkspaceStatus::Failed);

        // Provisioning that errored leaves the workspace permanently database-less.
        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/tasks')
            ->assertStatus(409);
    }

    public function test_the_gate_applies_to_every_api_route_not_just_the_disk(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->unprovisionedOwnWorkspace($owner);

        $this->actingAs($owner)->withHeader('X-Workspace-Id', $workspace->id);

        // ResolveWorkspace is the api-group-wide door, so nothing tenant-aware slips past it.
        $this->getJson('/api/tasks')->assertStatus(409);
        $this->getJson('/api/forms')->assertStatus(409);
        $this->getJson('/api/workflows')->assertStatus(409);
    }

    public function test_a_stranger_still_gets_403_not_409_so_readiness_never_leaks(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->unprovisionedOwnWorkspace($owner);

        $stranger = User::factory()->create();

        // Membership is checked first: a non-member learns nothing about the workspace's state.
        $this->actingAs($stranger)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/tasks')
            ->assertStatus(403);
    }

    public function test_a_ready_shared_workspace_is_unaffected(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        // Shared workspaces are created Ready synchronously — the gate must not touch them.
        $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/tasks')
            ->assertOk();
    }

    // ---- The floor: connectionConfig() ----------------------------------------

    public function test_connection_config_refuses_to_describe_an_own_workspace_without_a_database(): void
    {
        $workspace = Workspace::factory()->make([
            'db_mode' => WorkspaceDbMode::Own,
            'db_database' => null,
        ]);

        // Silently returning the shared config here is what turned "tenant" into "everyone".
        $this->expectException(RuntimeException::class);

        $workspace->connectionConfig();
    }

    public function test_connection_config_still_layers_a_provisioned_own_workspace_over_the_base(): void
    {
        $workspace = Workspace::factory()->make([
            'db_mode' => WorkspaceDbMode::Own,
            'db_database' => 'tenant_deadbeef',
        ]);

        $config = $workspace->connectionConfig();

        $this->assertSame('tenant_deadbeef', $config['database']);
        // Host/driver/credentials are inherited from the base server connection.
        $this->assertSame(config('database.connections.' . config('database.default') . '.host'), $config['host']);
    }
}
