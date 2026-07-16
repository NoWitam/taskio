<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Models\WorkflowRunStep;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batch 4: read-only monitoring endpoints for workflow runs
 *   GET /api/workflows/{workflow}/runs        (index)
 *   GET /api/workflows/{workflow}/runs/{run}  (show)
 */
class WorkflowRunReadTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    public function test_index_returns_runs_newest_first_with_cursor_shape(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        $older = WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'created_at' => now()->subHour(),
        ]);
        $newer = WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data', 'links', 'meta' => ['next_cursor']]);

        // Newest first.
        $response->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_index_carries_steps_count_and_omits_payload_and_steps(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        $run = WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'trigger_payload' => ['task' => ['id' => 'abc']],
        ]);
        WorkflowRunStep::factory()->count(3)->create(['workflow_run_id' => $run->id]);

        $response = $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs")
            ->assertOk()
            ->assertJsonPath('data.0.steps_count', 3)
            ->assertJsonPath('data.0.state_tone', WorkflowRunState::from($run->state->value)->tone());

        // The heavy trigger_payload and the steps collection are detail-only.
        $item = $response->json('data.0');
        $this->assertArrayNotHasKey('trigger_payload', $item);
        $this->assertArrayNotHasKey('steps', $item);
    }

    public function test_index_paginates_beyond_one_page(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        // Page size is 15; create more so a next_cursor exists.
        WorkflowRun::factory()->count(20)->create(['workflow_id' => $workflow->id]);

        $first = $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs")
            ->assertOk()
            ->assertJsonCount(15, 'data');

        $cursor = $first->json('meta.next_cursor');
        $this->assertNotNull($cursor);

        $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs?cursor={$cursor}")
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_index_filters_by_state(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        $completed = WorkflowRun::factory()->completed()->create(['workflow_id' => $workflow->id]);
        WorkflowRun::factory()->failed()->create(['workflow_id' => $workflow->id]);

        $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs?state=completed")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $completed->id)
            ->assertJsonPath('data.0.state', 'completed');
    }

    public function test_index_filters_by_origin(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        $manual = WorkflowRun::factory()->create(['workflow_id' => $workflow->id, 'origin' => 'manual']);
        WorkflowRun::factory()->create(['workflow_id' => $workflow->id, 'origin' => 'event']);

        $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs?origin=manual")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $manual->id)
            ->assertJsonPath('data.0.origin', 'manual');
    }

    public function test_index_ignores_invalid_filter_values(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        WorkflowRun::factory()->count(2)->create(['workflow_id' => $workflow->id]);

        // Invalid enum values are silently ignored (no filtering, no error) — mirrors Bot inbox.
        $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs?state=bogus&origin=nope")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_only_returns_runs_of_this_workflow(): void
    {
        $user = User::factory()->create();
        $workflowA = Workflow::factory()->create(['creator_id' => $user->id]);
        $workflowB = Workflow::factory()->create(['creator_id' => $user->id]);

        WorkflowRun::factory()->create(['workflow_id' => $workflowA->id]);
        WorkflowRun::factory()->count(2)->create(['workflow_id' => $workflowB->id]);

        $this->actingAs($user)
            ->getJson("/api/workflows/{$workflowA->id}/runs")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_index_does_not_lazy_load_steps(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);
        $run = WorkflowRun::factory()->create(['workflow_id' => $workflow->id]);
        WorkflowRunStep::factory()->count(2)->create(['workflow_run_id' => $run->id]);

        // Guard against N+1: the index must NOT eager- or lazy-load the steps relation.
        // Disabling lazy loading makes any accidental step access throw during the request.
        Model::preventLazyLoading(true);

        try {
            $this->actingAs($user)
                ->getJson("/api/workflows/{$workflow->id}/runs")
                ->assertOk()
                ->assertJsonPath('data.0.steps_count', 2);
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    public function test_show_returns_steps_ordered_by_position_with_payload_and_error(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);
        $run = WorkflowRun::factory()->failed()->create([
            'workflow_id' => $workflow->id,
            'trigger_payload' => ['task' => ['id' => 'xyz']],
        ]);

        // Insert out of order to prove the response orders by position.
        WorkflowRunStep::factory()->failed()->create([
            'workflow_run_id' => $run->id,
            'position' => 1,
            'key' => 'second',
        ]);
        WorkflowRunStep::factory()->succeeded()->create([
            'workflow_run_id' => $run->id,
            'position' => 0,
            'key' => 'first',
            'payload' => ['ok' => true],
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $run->id)
            ->assertJsonPath('data.state', 'failed')
            ->assertJsonCount(2, 'data.steps');

        // Ordered by position: first (0) then second (1).
        $response->assertJsonPath('data.steps.0.key', 'first')
            ->assertJsonPath('data.steps.0.position', 0)
            ->assertJsonPath('data.steps.0.status', 'succeeded')
            ->assertJsonPath('data.steps.0.payload.ok', true)
            ->assertJsonPath('data.steps.1.key', 'second')
            ->assertJsonPath('data.steps.1.position', 1)
            ->assertJsonPath('data.steps.1.status', 'failed')
            ->assertJsonPath('data.steps.1.error', 'Step failed.');

        // trigger_payload present on the detail.
        $response->assertJsonPath('data.trigger_payload.task.id', 'xyz');
    }

    public function test_show_includes_duration_seconds_when_started_and_finished(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);
        $run = WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'state' => 'completed',
            'started_at' => '2026-07-07 10:00:00',
            'finished_at' => '2026-07-07 10:00:42',
        ]);

        $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.duration_seconds', 42);
    }

    public function test_show_duration_is_null_when_unfinished(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);
        $run = WorkflowRun::factory()->running()->create(['workflow_id' => $workflow->id]);

        $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.duration_seconds', null);
    }

    public function test_show_404s_a_run_of_another_workflow(): void
    {
        $user = User::factory()->create();
        $workflowA = Workflow::factory()->create(['creator_id' => $user->id]);
        $workflowB = Workflow::factory()->create(['creator_id' => $user->id]);

        // A run that belongs to workflow B must 404 under workflow A's URL.
        $foreignRun = WorkflowRun::factory()->create(['workflow_id' => $workflowB->id]);

        $this->actingAs($user)
            ->getJson("/api/workflows/{$workflowA->id}/runs/{$foreignRun->id}")
            ->assertNotFound();
    }

    public function test_show_404s_a_missing_run(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs/00000000-0000-0000-0000-000000000000")
            ->assertNotFound();
    }

    public function test_run_index_returns_only_active_workspace_runs(): void
    {
        // The runs LIST is filtered at query time by WorkspaceScope on WorkflowRun, so the
        // list only ever contains the active workspace's runs. (This query-time scope is the
        // reliable isolation guarantee; the parent {workflow} route binding is NOT scoped —
        // see the known cross-workspace binding risk in the batch notes.)
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $workflow = Workflow::factory()->create([
            'creator_id' => $user->id,
            'workspace_id' => $workspace->id,
        ]);
        WorkflowRun::factory()->count(2)->create([
            'workflow_id' => $workflow->id,
            'workspace_id' => $workspace->id,
        ]);

        // A run tagged to the SAME workflow id but a FOREIGN workspace must not leak into the
        // list — the WorkspaceScope on the query drops it.
        $foreignWorkspace = $this->workspaceFor($user);
        WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'workspace_id' => $foreignWorkspace->id,
        ]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson("/api/workflows/{$workflow->id}/runs")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_non_member_cannot_access_runs(): void
    {
        $owner = User::factory()->create();
        $foreignWorkspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workflow = Workflow::factory()->create([
            'creator_id' => $owner->id,
            'workspace_id' => $foreignWorkspace->id,
        ]);

        $outsider = User::factory()->create();

        $this->actingAs($outsider)->withHeader('X-Workspace-Id', $foreignWorkspace->id)
            ->getJson("/api/workflows/{$workflow->id}/runs")
            ->assertForbidden();
    }

    public function test_guest_cannot_access_runs(): void
    {
        $workflow = Workflow::factory()->create();

        $this->getJson("/api/workflows/{$workflow->id}/runs")->assertUnauthorized();
        $this->getJson("/api/workflows/{$workflow->id}/runs/some-id")->assertUnauthorized();
    }
}
