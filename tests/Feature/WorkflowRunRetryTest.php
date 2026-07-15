<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workflows\Enums\WorkflowRunOrigin;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Jobs\WorkflowRunJob;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Retry a FAILED workflow run: POST /api/workflows/{workflow}/runs/{run}/retry.
 *
 * The engine has no mid-run resume — a "retry" STARTS A NEW run for the same workflow reusing
 * the failed run's stored trigger_payload, as a MANUAL run attributed to the acting user.
 */
class WorkflowRunRetryTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    public function test_failed_run_retries_into_a_new_manual_run_with_the_same_payload(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $workflow = Workflow::factory()->create([
            'creator_id' => $user->id,
            'trigger_type' => WorkflowTriggerType::FORM_SUBMITTED,
        ]);

        $payload = ['submission' => ['id' => 'sub-123'], 'form' => ['id' => 'form-1', 'is_anonymous' => false]];
        $failed = WorkflowRun::factory()->failed()->create([
            'workflow_id' => $workflow->id,
            'origin' => WorkflowRunOrigin::EVENT,
            'trigger_type' => WorkflowTriggerType::FORM_SUBMITTED->value,
            'trigger_payload' => $payload,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/workflows/{$workflow->id}/runs/{$failed->id}/retry")
            ->assertAccepted();

        $newRunId = $response->json('data.id');

        // A NEW run row, not the failed one.
        $this->assertNotSame($failed->id, $newRunId);
        $response->assertJsonPath('data.origin', WorkflowRunOrigin::MANUAL->value)
            ->assertJsonPath('data.state', WorkflowRunState::PENDING->value);

        $newRun = WorkflowRun::findOrFail($newRunId);
        $this->assertSame($workflow->id, $newRun->workflow_id);
        $this->assertSame($payload, $newRun->trigger_payload);
        $this->assertSame(WorkflowRunOrigin::MANUAL, $newRun->origin);
        $this->assertSame(WorkflowRunState::PENDING, $newRun->state);
        // Creator is the acting user (like run-now), not the workflow author fallback.
        $this->assertSame($user->id, $newRun->creator_id);
        $this->assertSame('user', $newRun->creator_type);
        // Top-level manual run: no re-trigger chain carried over from the failed run.
        $this->assertSame(0, $newRun->depth);
        $this->assertNull($newRun->origin_run_id);

        // The failed run is untouched (retry never mutates the original).
        $this->assertSame(WorkflowRunState::FAILED, $failed->fresh()->state);

        // The new run's job is dispatched (deferred to afterCommit; Queue::fake captures it).
        Queue::assertPushed(WorkflowRunJob::class);
    }

    public function test_retry_preserves_a_schedule_runs_trigger_context(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $workflow = Workflow::factory()->create([
            'creator_id' => $user->id,
            'trigger_type' => WorkflowTriggerType::SCHEDULE,
        ]);

        $payload = ['scheduled_at' => '2026-07-15T09:00:00+00:00'];
        $failed = WorkflowRun::factory()->failed()->create([
            'workflow_id' => $workflow->id,
            'origin' => WorkflowRunOrigin::SCHEDULE,
            'trigger_type' => WorkflowTriggerType::SCHEDULE->value,
            'trigger_payload' => $payload,
        ]);

        $newRunId = $this->actingAs($user)
            ->postJson("/api/workflows/{$workflow->id}/runs/{$failed->id}/retry")
            ->assertAccepted()
            ->json('data.id');

        $newRun = WorkflowRun::findOrFail($newRunId);
        $this->assertSame($payload, $newRun->trigger_payload);
        $this->assertSame(WorkflowTriggerType::SCHEDULE->value, $newRun->trigger_type);
    }

    public function test_running_run_cannot_be_retried(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);
        $run = WorkflowRun::factory()->running()->create(['workflow_id' => $workflow->id]);

        $this->actingAs($user)
            ->postJson("/api/workflows/{$workflow->id}/runs/{$run->id}/retry")
            ->assertStatus(422)
            ->assertJsonValidationErrors('run');

        // No new run was created.
        $this->assertSame(1, WorkflowRun::where('workflow_id', $workflow->id)->count());
    }

    public function test_pending_run_cannot_be_retried(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);
        $run = WorkflowRun::factory()->pending()->create(['workflow_id' => $workflow->id]);

        $this->actingAs($user)
            ->postJson("/api/workflows/{$workflow->id}/runs/{$run->id}/retry")
            ->assertStatus(422)
            ->assertJsonValidationErrors('run');
    }

    public function test_completed_run_cannot_be_retried(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);
        $run = WorkflowRun::factory()->completed()->create(['workflow_id' => $workflow->id]);

        $this->actingAs($user)
            ->postJson("/api/workflows/{$workflow->id}/runs/{$run->id}/retry")
            ->assertStatus(422)
            ->assertJsonValidationErrors('run');
    }

    public function test_retry_404s_a_run_of_another_workflow(): void
    {
        $user = User::factory()->create();
        $workflowA = Workflow::factory()->create(['creator_id' => $user->id]);
        $workflowB = Workflow::factory()->create(['creator_id' => $user->id]);

        // A failed run belonging to workflow B must 404 under workflow A's URL.
        $foreignRun = WorkflowRun::factory()->failed()->create(['workflow_id' => $workflowB->id]);

        $this->actingAs($user)
            ->postJson("/api/workflows/{$workflowA->id}/runs/{$foreignRun->id}/retry")
            ->assertNotFound();
    }

    public function test_retry_404s_a_missing_run(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->postJson("/api/workflows/{$workflow->id}/runs/00000000-0000-0000-0000-000000000000/retry")
            ->assertNotFound();
    }

    public function test_retry_404s_a_cross_workspace_run(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);
        $workflow = Workflow::factory()->create([
            'creator_id' => $user->id,
            'workspace_id' => $workspace->id,
        ]);

        // A run tagged to the same workflow id but a FOREIGN workspace never binds (WorkspaceScope
        // drops it), so it 404s before the request is authorized.
        $foreignWorkspace = $this->workspaceFor($user);
        $foreignRun = WorkflowRun::factory()->failed()->create([
            'workflow_id' => $workflow->id,
            'workspace_id' => $foreignWorkspace->id,
        ]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson("/api/workflows/{$workflow->id}/runs/{$foreignRun->id}/retry")
            ->assertNotFound();
    }

    public function test_non_member_cannot_retry(): void
    {
        $owner = User::factory()->create();
        $foreignWorkspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workflow = Workflow::factory()->create([
            'creator_id' => $owner->id,
            'workspace_id' => $foreignWorkspace->id,
        ]);
        $failed = WorkflowRun::factory()->failed()->create([
            'workflow_id' => $workflow->id,
            'workspace_id' => $foreignWorkspace->id,
        ]);

        $outsider = User::factory()->create();

        $this->actingAs($outsider)->withHeader('X-Workspace-Id', $foreignWorkspace->id)
            ->postJson("/api/workflows/{$workflow->id}/runs/{$failed->id}/retry")
            ->assertForbidden();
    }

    public function test_guest_cannot_retry(): void
    {
        $workflow = Workflow::factory()->create();
        $run = WorkflowRun::factory()->failed()->create(['workflow_id' => $workflow->id]);

        $this->postJson("/api/workflows/{$workflow->id}/runs/{$run->id}/retry")
            ->assertUnauthorized();
    }

    public function test_retry_is_refused_when_the_run_budget_is_reached(): void
    {
        // Same ceiling run-now enforces: at the workspace hard cap, a retry 422s (it is not
        // silently skipped like an event trigger).
        config(['workflows.max_runs_hard_cap' => 1]);

        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);
        $failed = WorkflowRun::factory()->failed()->create(['workflow_id' => $workflow->id]);

        // One existing run this month already meets the hard cap of 1.
        $this->actingAs($user)
            ->postJson("/api/workflows/{$workflow->id}/runs/{$failed->id}/retry")
            ->assertStatus(422)
            ->assertJsonValidationErrors('workflow');
    }
}
