<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B1 + B2: multi-value / date-range filters on the runs index, and the GLOBAL runs feed
 * (GET workflows/runs) with resource enrichment (the `workflow` block + schedule_descriptor).
 */
class WorkflowRunGlobalAndFilterTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    // ---- B1: multi-value + date-range filters (per-workflow index) -----------------------

    public function test_index_filters_by_multiple_states(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        $completed = WorkflowRun::factory()->completed()->create(['workflow_id' => $workflow->id]);
        $failed = WorkflowRun::factory()->failed()->create(['workflow_id' => $workflow->id]);
        WorkflowRun::factory()->running()->create(['workflow_id' => $workflow->id]);

        $ids = $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs?state[]=completed&state[]=failed")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->json('data.*.id');

        $this->assertEqualsCanonicalizing([$completed->id, $failed->id], $ids);
    }

    public function test_index_filters_by_multiple_origins(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        $manual = WorkflowRun::factory()->create(['workflow_id' => $workflow->id, 'origin' => 'manual']);
        $schedule = WorkflowRun::factory()->create(['workflow_id' => $workflow->id, 'origin' => 'schedule']);
        WorkflowRun::factory()->create(['workflow_id' => $workflow->id, 'origin' => 'event']);

        $ids = $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs?origin[]=manual&origin[]=schedule")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->json('data.*.id');

        $this->assertEqualsCanonicalizing([$manual->id, $schedule->id], $ids);
    }

    public function test_index_filters_by_multiple_trigger_types(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        $schedule = WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'trigger_type' => WorkflowTriggerType::SCHEDULE->value,
        ]);
        WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'trigger_type' => WorkflowTriggerType::FORM_SUBMITTED->value,
        ]);

        $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs?trigger_type[]=schedule")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $schedule->id);
    }

    public function test_index_legacy_single_state_still_works(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        $completed = WorkflowRun::factory()->completed()->create(['workflow_id' => $workflow->id]);
        WorkflowRun::factory()->failed()->create(['workflow_id' => $workflow->id]);

        // A legacy single scalar deep-link ?state=completed is coerced to an array and works.
        $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs?state=completed")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $completed->id);
    }

    public function test_index_tolerates_an_unknown_state_member(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        WorkflowRun::factory()->count(2)->create(['workflow_id' => $workflow->id]);

        // An unrecognised member is dropped (not a 422); an all-unknown filter is a no-op.
        $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs?state[]=bogus")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_filters_by_created_from(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        $old = WorkflowRun::factory()->create(['workflow_id' => $workflow->id, 'created_at' => '2026-01-10 12:00:00']);
        $recent = WorkflowRun::factory()->create(['workflow_id' => $workflow->id, 'created_at' => '2026-05-10 12:00:00']);

        $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs?date_from=2026-03-01")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id);
    }

    public function test_index_filters_by_created_to(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        $old = WorkflowRun::factory()->create(['workflow_id' => $workflow->id, 'created_at' => '2026-01-10 12:00:00']);
        WorkflowRun::factory()->create(['workflow_id' => $workflow->id, 'created_at' => '2026-05-10 12:00:00']);

        $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs?date_to=2026-03-01")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $old->id);
    }

    // ---- B2: global feed ------------------------------------------------------------------

    public function test_global_returns_runs_across_workflows_newest_first(): void
    {
        $user = User::factory()->create();
        $workflowA = Workflow::factory()->create(['creator_id' => $user->id]);
        $workflowB = Workflow::factory()->create(['creator_id' => $user->id]);

        $older = WorkflowRun::factory()->create(['workflow_id' => $workflowA->id, 'created_at' => now()->subHour()]);
        $newer = WorkflowRun::factory()->create(['workflow_id' => $workflowB->id, 'created_at' => now()]);

        $this->actingAs($user)
            ->getJson('/api/workflows/runs')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data', 'links', 'meta' => ['next_cursor']])
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_global_row_carries_workflow_block(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->active()->create([
            'creator_id' => $user->id,
            'name' => 'Nightly report',
            'icon' => 'calendar',
            'trigger_type' => WorkflowTriggerType::SCHEDULE->value,
        ]);
        WorkflowRun::factory()->create(['workflow_id' => $workflow->id]);

        $this->actingAs($user)
            ->getJson('/api/workflows/runs')
            ->assertOk()
            ->assertJsonPath('data.0.workflow.id', $workflow->id)
            ->assertJsonPath('data.0.workflow.name', 'Nightly report')
            ->assertJsonPath('data.0.workflow.icon', 'calendar')
            ->assertJsonPath('data.0.workflow.status', 'active')
            ->assertJsonPath('data.0.workflow.trigger_type', 'schedule');
    }

    public function test_per_workflow_index_row_omits_workflow_block(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);
        WorkflowRun::factory()->create(['workflow_id' => $workflow->id]);

        $item = $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs")
            ->assertOk()
            ->json('data.0');

        $this->assertArrayNotHasKey('workflow', $item);
    }

    public function test_global_does_not_n_plus_1_the_workflow_relation(): void
    {
        $user = User::factory()->create();
        $workflowA = Workflow::factory()->create(['creator_id' => $user->id]);
        $workflowB = Workflow::factory()->create(['creator_id' => $user->id]);
        WorkflowRun::factory()->count(2)->create(['workflow_id' => $workflowA->id]);
        WorkflowRun::factory()->count(2)->create(['workflow_id' => $workflowB->id]);

        // with('workflow') must eager-load: accessing the row's workflow must not lazy-load.
        Model::preventLazyLoading(true);

        try {
            $this->actingAs($user)
                ->getJson('/api/workflows/runs')
                ->assertOk()
                ->assertJsonCount(4, 'data')
                ->assertJsonPath('data.0.workflow.id', fn ($id) => $id !== null);
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    public function test_global_honors_optional_workflow_id_filter(): void
    {
        $user = User::factory()->create();
        $workflowA = Workflow::factory()->create(['creator_id' => $user->id]);
        $workflowB = Workflow::factory()->create(['creator_id' => $user->id]);

        $runA = WorkflowRun::factory()->create(['workflow_id' => $workflowA->id]);
        WorkflowRun::factory()->count(2)->create(['workflow_id' => $workflowB->id]);

        $this->actingAs($user)
            ->getJson("/api/workflows/runs?workflow_id={$workflowA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $runA->id);
    }

    public function test_global_isolates_to_the_active_workspace(): void
    {
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

        // A run in a FOREIGN workspace must not leak into the global feed — WorkspaceScope drops it.
        $foreignWorkspace = $this->workspaceFor($user);
        $foreignWorkflow = Workflow::factory()->create([
            'creator_id' => $user->id,
            'workspace_id' => $foreignWorkspace->id,
        ]);
        WorkflowRun::factory()->create([
            'workflow_id' => $foreignWorkflow->id,
            'workspace_id' => $foreignWorkspace->id,
        ]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/workflows/runs')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_global_requires_authentication(): void
    {
        $this->getJson('/api/workflows/runs')->assertUnauthorized();
    }

    // ---- B2: route ordering regression ----------------------------------------------------

    public function test_workflows_runs_hits_global_not_show_with_workflow_named_runs(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);
        $run = WorkflowRun::factory()->create(['workflow_id' => $workflow->id]);

        // If `workflows/runs` were swallowed by `workflows/{workflow}` (@show), the binding
        // {workflow}="runs" would 404. Reaching @global returns the paginated runs collection.
        $this->actingAs($user)
            ->getJson('/api/workflows/runs')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta' => ['next_cursor']])
            ->assertJsonPath('data.0.id', $run->id);
    }

    // ---- B2: resource enrichment on SHOW --------------------------------------------------

    public function test_show_schedule_run_exposes_schedule_descriptor_with_tz(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create([
            'creator_id' => $user->id,
            'trigger_type' => WorkflowTriggerType::SCHEDULE->value,
            'trigger_config' => ['schedule' => [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'tz' => 'Europe/Warsaw',
            ]],
        ]);
        $run = WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'trigger_type' => WorkflowTriggerType::SCHEDULE->value,
        ]);

        $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.trigger_type', 'schedule')
            ->assertJsonPath('data.schedule_descriptor.tz', 'Europe/Warsaw')
            ->assertJsonPath('data.schedule_descriptor.time.mode', 'at');
    }

    public function test_show_form_submitted_run_omits_schedule_descriptor(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);
        $run = WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'trigger_type' => WorkflowTriggerType::FORM_SUBMITTED->value,
        ]);

        $data = $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs/{$run->id}")
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('schedule_descriptor', $data);
    }

    public function test_show_form_submitted_run_exposes_form_and_submission_ids_in_payload(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);
        $run = WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'trigger_type' => WorkflowTriggerType::FORM_SUBMITTED->value,
            'trigger_payload' => [
                'submission' => ['id' => 'submission-123'],
                'form' => ['id' => 'form-456', 'name' => 'Intake', 'is_anonymous' => false],
                'task' => null,
                'source' => 'manual',
                'submitted_at' => '2026-07-01T10:00:00+00:00',
                'fields' => ['fields.name' => 'Ada'],
            ],
        ]);

        $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.trigger_payload.form.id', 'form-456')
            ->assertJsonPath('data.trigger_payload.form.name', 'Intake')
            ->assertJsonPath('data.trigger_payload.submission.id', 'submission-123')
            ->assertJsonPath('data.trigger_payload.source', 'manual')
            ->assertJsonPath('data.trigger_payload.submitted_at', '2026-07-01T10:00:00+00:00');
    }

    public function test_show_form_submitted_run_resolves_the_form_block_in_one_call(): void
    {
        // The detail carries a LIVE `form` block (name + description + icon) resolved server-side
        // from the trigger form, so the FE renders the lean form item WITHOUT a second call. The
        // description/icon are NOT in the stored payload snapshot — they come from the form itself.
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'name' => 'Onboarding survey',
            'description' => 'Filled after 30 days.',
            'icon' => 'clipboard',
        ]);
        $run = WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'trigger_type' => WorkflowTriggerType::FORM_SUBMITTED->value,
            'trigger_payload' => [
                'submission' => ['id' => 'submission-123'],
                'form' => ['id' => $form->id, 'name' => 'Onboarding survey', 'is_anonymous' => false],
                'source' => 'manual',
            ],
        ]);

        $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}/runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.form.id', $form->id)
            ->assertJsonPath('data.form.name', 'Onboarding survey')
            ->assertJsonPath('data.form.description', 'Filled after 30 days.')
            ->assertJsonPath('data.form.icon', 'clipboard');
    }
}
