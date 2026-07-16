<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workspaces\Jobs\ProvisionWorkspaceJob;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class WorkspaceProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_workspace_is_ready_immediately_and_dispatches_no_job(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->postJson('/api/workspaces', [
            'name' => 'Shared Space',
            'db_mode' => 'shared',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.db_mode', 'shared')
            ->assertJsonPath('data.status', 'ready');

        $workspace = Workspace::query()->firstOrFail();
        $this->assertSame('ready', $workspace->status->value);
        $this->assertNull($workspace->db_database);

        Bus::assertNothingDispatched();
    }

    public function test_default_db_mode_creates_a_ready_shared_workspace(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workspaces', ['name' => 'Defaulting'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'ready');

        Bus::assertNothingDispatched();
    }

    public function test_own_workspace_is_provisioning_and_dispatches_provision_job(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->postJson('/api/workspaces', [
            'name' => 'Own Space',
            'db_mode' => 'own',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.db_mode', 'own')
            ->assertJsonPath('data.status', 'provisioning');

        $workspace = Workspace::query()->firstOrFail();
        $this->assertSame('provisioning', $workspace->status->value);

        Bus::assertDispatched(
            ProvisionWorkspaceJob::class,
            fn (ProvisionWorkspaceJob $job) => true
        );
    }

    public function test_show_returns_status_and_capability_fields(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach([$owner->id, $member->id]);

        $this->actingAs($owner)
            ->getJson("/api/workspaces/{$workspace->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.can_manage_members', true)
            ->assertJsonPath('data.member_count', 2);

        $this->actingAs($member)
            ->getJson("/api/workspaces/{$workspace->id}")
            ->assertOk()
            ->assertJsonPath('data.can_manage_members', false)
            ->assertJsonPath('data.member_count', 2);
    }

    public function test_auth_context_carries_workspace_status(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_id' => $user->id,
            'status' => 'provisioning',
        ]);
        $workspace->users()->attach($user->id);

        $this->actingAs($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('workspaces.0.status', 'provisioning');
    }
}
