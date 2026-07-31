<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Models\Bot;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use App\Modules\Variables\Models\Constant;
use App\Modules\Workflows\Enums\WorkflowRunOrigin;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Enums\WorkflowStatus;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowRunContext;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\TestCase;

/**
 * The module goes LIVE (Etap 5.1 re-scope). The surviving domain event — a form submission
 * reaching approved — dispatches through WorkflowDispatchService into matching active
 * form_submitted workflows; a manual-run endpoint starts a run on demand.
 * QUEUE_CONNECTION=sync runs the dispatched WorkflowRunJob in-process the moment start()
 * commits, so a single trigger drives the whole run (claim -> steps -> terminal). Dispatch is
 * deferred to DB::afterCommit at the hook, so it fires only after the authoring write commits.
 *
 * NOTE on run authorship: `origin` (EVENT | SCHEDULE | MANUAL) is the authoritative signal for
 * how a run began — never `creator_id`. WorkflowRunManager::start attributes a MANUAL run to the
 * acting user and an ENGINE run (event/schedule) to the workflow AUTHOR, so creator_id is never
 * NULL and does not distinguish the two. Tests assert on origin.
 */
class WorkflowDispatchTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A create_task step no longer re-triggers any workflow (no task triggers survive), so
        // depth pinning is unnecessary; keep caps generous unless a cap test sets them.
        config([
            'workflows.max_depth' => 3,
            'workflows.max_runs_per_month' => 1000,
            'workflows.max_runs_hard_cap' => 1000,
        ]);
    }

    /**
     * A form_submitted workflow with one create_task step, so a dispatched run COMPLETES and
     * is easy to assert on.
     *
     * @param  array<int, array{type: string, key: string, config: array}>|null  $steps
     */
    private function formSubmittedWorkflow(
        User $owner,
        array $config = [],
        array $conditions = [],
        WorkflowStatus $status = WorkflowStatus::ACTIVE,
        ?array $steps = null,
    ): Workflow {
        return Workflow::factory()->create([
            'creator_id' => $owner->id,
            'status' => $status,
            'trigger_type' => WorkflowTriggerType::FORM_SUBMITTED->value,
            'trigger_config' => $config,
            'conditions' => $conditions,
            'steps' => $steps ?? [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'noop', 'config' => ['title' => 'Wf-created task']],
            ],
        ]);
    }

    /** Runs started for a workflow (its own runs, not the tasks it may create). */
    private function runsFor(Workflow $workflow): \Illuminate\Support\Collection
    {
        return WorkflowRun::query()->where('workflow_id', $workflow->id)->get();
    }

    /** An approved submission of $form (defaults to a manual/Form-attached submission). */
    private function submit(Form $form, User $owner, array $overrides = []): FormSubmission
    {
        return FormSubmission::factory()->create(array_merge([
            'form_id' => $form->id,
            'creator_id' => $owner->id,
            'submittable_type' => $form->getMorphClass(),
            'submittable_id' => $form->id,
            'approved_at' => now(),
        ], $overrides));
    }

    // ---- Trigger firing ------------------------------------------------------

    public function test_submitting_a_form_fires_form_submitted(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);
        $workflow = $this->formSubmittedWorkflow($owner);

        $submission = $this->submit($form, $owner);

        $runs = $this->runsFor($workflow);
        $this->assertCount(1, $runs);
        $run = $runs->first();
        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertSame(WorkflowRunOrigin::EVENT, $run->origin);
        $this->assertSame($submission->id, $run->trigger_payload['submission']['id']);
        $this->assertSame($form->id, $run->trigger_payload['form']['id']);
        $this->assertSame('manual', $run->trigger_payload['source']);
    }

    public function test_task_attached_submission_fires_with_source_task_and_snapshot(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);
        $task = Task::factory()->create(['creator_id' => $owner->id, 'assigned_id' => $owner->id, 'title' => 'Parent']);
        $workflow = $this->formSubmittedWorkflow($owner);

        $this->submit($form, $owner, [
            'submittable_type' => $task->getMorphClass(),
            'submittable_id' => $task->id,
        ]);

        $run = $this->runsFor($workflow)->first();
        $this->assertNotNull($run);
        $this->assertSame('task', $run->trigger_payload['source']);
        $this->assertSame($task->id, $run->trigger_payload['task']['id']);
        $this->assertSame('Parent', $run->trigger_payload['task']['title']);
    }

    /**
     * The bot path, end to end. A task's form submission is a DRAFT while the task is worked
     * on and is confirmed when the task reaches done (TaskObserver) — that approval is what
     * fires this trigger. A bot completing its run must confirm the submission exactly like a
     * human completing the task, otherwise the form the bot filled silently never "submits"
     * and the workflow behind it never runs.
     */
    public function test_a_bot_completing_a_task_confirms_its_form_and_fires_form_submitted(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);
        $workflow = $this->formSubmittedWorkflow($owner, config: ['form_id' => $form->id]);
        $bot = Bot::factory()->executesTasks()->create(['creator_id' => $owner->id]);

        $this->scriptBotRun([
            ['fill_form', ['answers' => ['subject' => 'Bot answer']]],
            ['finish'],
        ]);

        // No approval pipeline, so finish completes the task outright — see
        // BotTaskInteractionService::finish().
        $taskId = $this->postJson('/api/tasks', [
            'title' => 'Bot task with a form',
            'priority' => 'medium',
            'assignee_type' => 'bot',
            'assignee_id' => $bot->id,
            'form_id' => $form->id,
        ])->assertCreated()->json('data.id');

        $this->assertSame(TaskStatus::DONE, Task::findOrFail($taskId)->status);

        $submission = FormSubmission::where('submittable_id', $taskId)->firstOrFail();
        $this->assertTrue(
            $submission->isApproved(),
            'Completing the task must confirm the form submission the bot filled.'
        );

        $run = $this->runsFor($workflow)->first();
        $this->assertNotNull($run, 'The confirmed submission must fire the form_submitted trigger.');
        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertSame('task', $run->trigger_payload['source']);
        $this->assertSame($taskId, $run->trigger_payload['task']['id']);
    }

    public function test_draft_form_submission_does_not_fire(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);
        $workflow = $this->formSubmittedWorkflow($owner);

        $this->submit($form, $owner, ['approved_at' => null]); // draft

        $this->assertCount(0, $this->runsFor($workflow));
    }

    public function test_inactive_workflow_never_fires(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);
        $workflow = $this->formSubmittedWorkflow($owner, status: WorkflowStatus::INACTIVE);

        $this->submit($form, $owner);

        $this->assertCount(0, $this->runsFor($workflow));
    }

    public function test_schedule_workflow_never_fires_on_a_form_submission(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);
        $workflow = Workflow::factory()->active()->scheduled()->create(['creator_id' => $owner->id]);

        $this->submit($form, $owner);

        $this->assertCount(0, $this->runsFor($workflow));
    }

    // ---- Targeting -----------------------------------------------------------

    public function test_empty_config_matches_every_form(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);
        $workflow = $this->formSubmittedWorkflow($owner, config: []);

        $this->submit($form, $owner);

        $this->assertCount(1, $this->runsFor($workflow));
    }

    public function test_form_id_targeting_include_and_exclude(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $formIncluded = Form::factory()->enabled()->create(['creator_id' => $owner->id]);
        $formOther = Form::factory()->enabled()->create(['creator_id' => $owner->id]);

        $workflow = $this->formSubmittedWorkflow($owner, config: ['form_id' => $formIncluded->id]);

        // Included form fires; other form does not.
        $this->submit($formIncluded, $owner);
        $this->submit($formOther, $owner);

        $this->assertCount(1, $this->runsFor($workflow));
    }

    public function test_source_in_filter_matches_only_listed_sources(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);
        $task = Task::factory()->create(['creator_id' => $owner->id, 'assigned_id' => $owner->id]);

        // Only task-sourced submissions count.
        $workflow = $this->formSubmittedWorkflow($owner, config: ['source' => ['in' => ['task']]]);

        // A manual submission is filtered out.
        $this->submit($form, $owner);
        $this->assertCount(0, $this->runsFor($workflow));

        // A task-attached submission matches.
        $this->submit($form, $owner, [
            'submittable_type' => $task->getMorphClass(),
            'submittable_id' => $task->id,
        ]);
        $this->assertCount(1, $this->runsFor($workflow));
    }

    public function test_anonymous_flag_matches_the_forms_flag(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $anonForm = Form::factory()->enabled()->create(['creator_id' => $owner->id, 'is_anonymous' => true]);
        $namedForm = Form::factory()->enabled()->create(['creator_id' => $owner->id, 'is_anonymous' => false]);

        // Only anonymous forms count.
        $workflow = $this->formSubmittedWorkflow($owner, config: ['anonymous' => true]);

        $this->submit($namedForm, $owner);
        $this->assertCount(0, $this->runsFor($workflow), 'a named form must not match anonymous=true');

        $this->submit($anonForm, $owner);
        $this->assertCount(1, $this->runsFor($workflow), 'an anonymous form matches anonymous=true');
    }

    // ---- Conditions ----------------------------------------------------------

    public function test_conditions_gate_the_run_over_a_field_value(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);

        // Fires only when the submission's `priority` answer equals `high`. The condition path
        // reads the new payload's whitelisted `fields.<id>` map (dotted path over the payload),
        // typed as a text field with the equals operator.
        $workflow = $this->formSubmittedWorkflow($owner, conditions: [
            ['field' => 'fields.priority', 'field_type' => 'text', 'operator' => 'equals', 'value' => 'high'],
        ]);

        $this->submit($form, $owner, ['data' => ['priority' => 'low']]);
        $this->assertCount(0, $this->runsFor($workflow));

        $this->submit($form, $owner, ['data' => ['priority' => 'high']]);
        $this->assertCount(1, $this->runsFor($workflow));
    }

    public function test_condition_tree_gates_the_run_over_and_or_pipelines(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);

        // Fires when category IS 'blog', OR (priority CONTAINS 'high' AND score > 10) — a mixed
        // and/or tree with typed pipelines, evaluated over the same whitelisted `fields.<id>` map.
        $tree = [
            'logic' => 'or',
            'children' => [
                ['kind' => 'condition', 'source' => 'fields.category', 'source_type' => 'enum', 'pipeline' => [
                    ['op' => 'enum_is', 'args' => ['value' => 'blog']],
                ]],
                ['kind' => 'group', 'logic' => 'and', 'children' => [
                    ['kind' => 'condition', 'source' => 'fields.priority', 'source_type' => 'text', 'pipeline' => [
                        ['op' => 'text_contains', 'args' => ['value' => 'high']],
                    ]],
                    ['kind' => 'condition', 'source' => 'fields.score', 'source_type' => 'number', 'pipeline' => [
                        ['op' => 'num_gt', 'args' => ['value' => 10]],
                    ]],
                ]],
            ],
        ];
        $workflow = $this->formSubmittedWorkflow($owner, conditions: $tree);

        // Neither branch satisfied → no run.
        $this->submit($form, $owner, ['data' => ['category' => 'news', 'priority' => 'low', 'score' => 5]]);
        $this->assertCount(0, $this->runsFor($workflow));

        // The enum branch is satisfied → a run starts.
        $this->submit($form, $owner, ['data' => ['category' => 'blog', 'priority' => 'low', 'score' => 5]]);
        $this->assertCount(1, $this->runsFor($workflow));

        // The and-group branch is satisfied → another run starts.
        $this->submit($form, $owner, ['data' => ['category' => 'news', 'priority' => 'high-value', 'score' => 42]]);
        $this->assertCount(2, $this->runsFor($workflow));
    }

    // ---- Conditions: the B6 capabilities, end to end -------------------------

    /** A one-condition tree; $extra merges onto the condition (e.g. a `default`). */
    private function conditionTree(string $source, string $sourceType, array $pipeline, array $extra = []): array
    {
        return [
            'logic' => 'and',
            'children' => [array_merge(
                ['kind' => 'condition', 'source' => $source, 'source_type' => $sourceType, 'pipeline' => $pipeline],
                $extra,
            )],
        ];
    }

    /**
     * Activate a workspace for the rest of the test, exactly as the ResolveWorkspace middleware does
     * for a real submit. Required for any GLOBALS-touching gate: a workspace global is a workspace-
     * scoped constant, and WorkflowVariableCatalogService::globalValues() refuses to answer with no
     * active workspace rather than collapse every workspace's globals into one map (WorkspaceScope
     * leaves a query unconstrained in that state). Called BEFORE the fixtures so they are stamped.
     */
    private function activateWorkspace(User $owner): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach($owner->id);
        app(TenantContext::class)->set($workspace);

        return $workspace;
    }

    public function test_a_condition_gated_on_a_workspace_global_fires_only_when_it_matches(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $this->activateWorkspace($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);
        Constant::factory()->text('nazwa_marki', 'Taskio')->create(['creator_id' => $owner->id]);

        // A `globals.<key>` SOURCE reads the workspace's own constants — nothing about the submission
        // decides this gate, which is exactly the point (e.g. "only while this brand is active").
        $matching = $this->formSubmittedWorkflow($owner, conditions: $this->conditionTree('globals.nazwa_marki', 'text', [
            ['op' => 'text_equals', 'args' => ['value' => 'Taskio']],
        ]));
        $notMatching = $this->formSubmittedWorkflow($owner, conditions: $this->conditionTree('globals.nazwa_marki', 'text', [
            ['op' => 'text_equals', 'args' => ['value' => 'Inna marka']],
        ]));
        $unknownGlobal = $this->formSubmittedWorkflow($owner, conditions: $this->conditionTree('globals.nie_istnieje', 'text', [
            ['op' => 'text_is_empty', 'args' => []],
        ]));

        $this->submit($form, $owner, ['data' => ['priority' => 'high']]);

        $this->assertCount(1, $this->runsFor($matching));
        $this->assertCount(0, $this->runsFor($notMatching));
        $this->assertCount(0, $this->runsFor($unknownGlobal), 'an absent global fails the gate closed');
    }

    public function test_a_condition_can_compare_a_submitted_field_against_a_global(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $this->activateWorkspace($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);
        Constant::factory()->number('limit', 1000)->create(['creator_id' => $owner->id]);

        // An operation ARGUMENT is itself a variable — the gate pre-resolves it through the same
        // resolver the step runtime uses. "Fire when this submission's budget exceeds the workspace
        // ceiling" was inexpressible before B6.
        $workflow = $this->formSubmittedWorkflow($owner, conditions: $this->conditionTree('fields.budget', 'number', [
            ['op' => 'num_gt', 'args' => ['value' => [
                'kind' => 'variable',
                'ref' => ['source' => 'globals', 'path' => 'limit', 'type' => 'number'],
            ]]],
        ]));

        $this->submit($form, $owner, ['data' => ['budget' => 100]]);
        $this->assertCount(0, $this->runsFor($workflow));

        $this->submit($form, $owner, ['data' => ['budget' => 5000]]);
        $this->assertCount(1, $this->runsFor($workflow));
    }

    public function test_a_condition_can_compare_two_submitted_fields(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);

        $workflow = $this->formSubmittedWorkflow($owner, conditions: $this->conditionTree('fields.a', 'text', [
            ['op' => 'text_equals', 'args' => ['value' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.b', 'type' => 'text'],
            ]]],
        ]));

        $this->submit($form, $owner, ['data' => ['a' => 'x', 'b' => 'y']]);
        $this->assertCount(0, $this->runsFor($workflow));

        $this->submit($form, $owner, ['data' => ['a' => 'x', 'b' => 'x']]);
        $this->assertCount(1, $this->runsFor($workflow));
    }

    public function test_an_opt_in_default_lets_an_unanswered_field_still_gate(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);

        $withDefault = $this->formSubmittedWorkflow($owner, conditions: $this->conditionTree('fields.priority', 'text', [
            ['op' => 'text_equals', 'args' => ['value' => 'low']],
        ], ['default' => 'low']));
        $withoutDefault = $this->formSubmittedWorkflow($owner, conditions: $this->conditionTree('fields.priority', 'text', [
            ['op' => 'text_equals', 'args' => ['value' => 'low']],
        ]));

        // The submission does not answer `priority` at all.
        $this->submit($form, $owner, ['data' => ['other' => 'x']]);

        $this->assertCount(1, $this->runsFor($withDefault));
        $this->assertCount(0, $this->runsFor($withoutDefault), 'without the key the missing path is still false');
    }

    // ---- Loop protection (depth cap machinery) -------------------------------

    public function test_dispatch_refuses_a_child_run_beyond_max_depth(): void
    {
        // The re-trigger chain is bounded by max_depth. With no task triggers to chain
        // organically, we simulate a running parent AT the depth limit via WorkflowRunContext
        // and fire a form_submitted event: the dispatcher must refuse the depth+1 child run.
        config(['workflows.max_depth' => 1]);

        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);
        $workflow = $this->formSubmittedWorkflow($owner);

        // A parent run already at the max depth: a child would be depth 2 > max_depth 1.
        $parent = WorkflowRun::factory()->running()->create([
            'workflow_id' => $workflow->id,
            'depth' => 1,
        ]);
        app(WorkflowRunContext::class)->set($parent);

        try {
            $this->submit($form, $owner);
        } finally {
            app(WorkflowRunContext::class)->clear();
        }

        // No child run was started for the workflow (only the pre-planted parent exists).
        $this->assertCount(1, $this->runsFor($workflow));
        $this->assertSame($parent->id, $this->runsFor($workflow)->first()->id);
    }

    // ---- Caps ----------------------------------------------------------------

    public function test_monthly_cap_reached_skips_event_dispatch_silently(): void
    {
        config(['workflows.max_runs_per_month' => 1]);
        config(['workflows.max_runs_hard_cap' => 1000]);

        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);
        $workflow = $this->formSubmittedWorkflow($owner);

        // First submission -> one run (hits the cap of 1).
        $this->submit($form, $owner);
        $this->assertCount(1, $this->runsFor($workflow));

        // Second submission -> cap reached, silently skipped (still exactly one run).
        $this->submit($form, $owner);
        $this->assertCount(1, $this->runsFor($workflow));
    }

    public function test_hard_cap_reached_skips_event_dispatch_silently(): void
    {
        config(['workflows.max_runs_per_month' => 1000]);
        config(['workflows.max_runs_hard_cap' => 1]);

        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);
        $workflow = $this->formSubmittedWorkflow($owner);

        $this->submit($form, $owner);
        $this->assertCount(1, $this->runsFor($workflow));

        $this->submit($form, $owner);
        $this->assertCount(1, $this->runsFor($workflow));
    }

    // ---- Manual run ----------------------------------------------------------

    public function test_member_can_manually_run_a_workflow_and_it_executes(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->actingAs($member); // NOT the creator — any member may run.

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);
        $workflow = $this->formSubmittedWorkflow($owner, status: WorkflowStatus::INACTIVE);
        $target = $this->submit($form, $owner);

        $response = $this->postJson("/api/workflows/{$workflow->id}/run", ['target_id' => $target->id]);
        $response->assertStatus(202);

        $runId = $response->json('data.id');
        $run = WorkflowRun::findOrFail($runId);
        $this->assertSame(WorkflowRunOrigin::MANUAL, $run->origin);
        $this->assertSame(0, $run->depth);
        $this->assertNull($run->origin_run_id);
        $this->assertSame($member->id, $run->creator_id);
        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertSame('manual', $response->json('data.origin'));
    }

    public function test_manual_form_submitted_run_requires_a_resolvable_target(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->formSubmittedWorkflow($owner);

        // Missing target.
        $this->postJson("/api/workflows/{$workflow->id}/run", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_id']);

        // Unresolvable (well-formed uuid, no such submission).
        $this->postJson("/api/workflows/{$workflow->id}/run", ['target_id' => \Illuminate\Support\Str::uuid()->toString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_id']);
    }

    public function test_manual_run_payload_matches_a_real_trigger_shape(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);

        // A workflow whose step echoes {{trigger.form.id}} into a created task's title, so we
        // can prove the manual payload carries the same form snapshot a real trigger would.
        $workflow = $this->formSubmittedWorkflow($owner, steps: [
            ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'echo', 'config' => [
                'title' => 'echo-{{trigger.form.id}}',
            ]],
        ]);

        $target = $this->submit($form, $owner);

        $this->postJson("/api/workflows/{$workflow->id}/run", ['target_id' => $target->id])->assertStatus(202);

        $this->assertDatabaseHas('tasks', ['title' => 'echo-' . $form->id]);
    }

    public function test_manual_run_at_cap_returns_422(): void
    {
        config(['workflows.max_runs_per_month' => 0]);

        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);
        $workflow = $this->formSubmittedWorkflow($owner);
        $target = $this->submit($form, $owner);

        $this->postJson("/api/workflows/{$workflow->id}/run", ['target_id' => $target->id])
            ->assertStatus(422)
            // The cap failure keys on `workflow` — the FE maps 422s by key, so this is contract.
            ->assertJsonValidationErrors(['workflow']);

        // No run was created.
        $this->assertCount(0, $this->runsFor($workflow));
    }

    public function test_manual_schedule_run_needs_no_target(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = Workflow::factory()->active()->scheduled()->create([
            'creator_id' => $owner->id,
            'steps' => [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'noop', 'config' => ['title' => 'Scheduled task']],
            ],
        ]);

        $response = $this->postJson("/api/workflows/{$workflow->id}/run", []);
        $response->assertStatus(202);

        $run = WorkflowRun::findOrFail($response->json('data.id'));
        $this->assertSame(WorkflowRunOrigin::MANUAL, $run->origin);
        $this->assertArrayHasKey('scheduled_at', $run->trigger_payload);
    }

    // ---- {{trigger.fields.<id>}} end-to-end ----------------------------------

    public function test_create_task_title_can_reference_a_form_field_value_end_to_end(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);

        // The step's title references a submission answer via the flat dotted path
        // {{trigger.fields.<id>}} — proving a form field value flows from submission -> payload
        // -> WorkflowVariableResolver -> created task, unchanged by the reshape.
        $workflow = $this->formSubmittedWorkflow($owner, steps: [
            ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'echo', 'config' => [
                'title' => 'Report: {{trigger.fields.subject}}',
            ]],
        ]);

        $this->submit($form, $owner, ['data' => ['subject' => 'Q3 metrics']]);

        $this->assertDatabaseHas('tasks', ['title' => 'Report: Q3 metrics']);
    }

    /**
     * The two steps chained end to end: a form_submitted run creates a task (title from a field
     * value) AND a form report (name from a field value), and the report's output id is
     * referenceable as steps.<key>.report_id from a later step. Only the AI report job is faked
     * (via Queue::fake with an explicit list) so the WorkflowRunJob still runs synchronously.
     */
    public function test_create_task_and_create_form_report_chain_end_to_end(): void
    {
        // Fake ONLY the AI report job — the WorkflowRunJob must still run synchronously so the
        // whole run executes; the report step is fire-and-forget and never triggers a real AI call.
        \Illuminate\Support\Facades\Queue::fake([\App\Modules\Forms\Jobs\CreateFormReport::class]);

        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);

        $workflow = $this->formSubmittedWorkflow($owner, config: ['form_id' => $form->id], steps: [
            ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'make_task', 'config' => [
                'title' => 'Task: {{trigger.fields.subject}}',
            ]],
            ['type' => WorkflowStepType::CREATE_FORM_REPORT->value, 'key' => 'make_report', 'config' => [
                'form_id' => $form->id,
                'name' => 'Report: {{trigger.fields.subject}}',
                'sources' => ['form'],
            ]],
            // A third step proves steps.make_report.report_id is referenceable downstream: it
            // echoes the report id into a task title.
            ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'echo_report', 'config' => [
                'title' => 'From report {{steps.make_report.report_id}}',
            ]],
        ]);

        $this->submit($form, $owner, ['data' => ['subject' => 'Q3 metrics']]);

        $run = $this->runsFor($workflow)->first();
        $this->assertNotNull($run);
        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);

        // The task and the report were both created with the resolved field value.
        $this->assertDatabaseHas('tasks', ['title' => 'Task: Q3 metrics']);
        $this->assertDatabaseHas('form_reports', ['name' => 'Report: Q3 metrics', 'form_id' => $form->id]);

        // The report id flowed into the third step's task title via steps.make_report.report_id.
        $report = \App\Modules\Forms\Models\FormReport::where('name', 'Report: Q3 metrics')->firstOrFail();
        $this->assertDatabaseHas('tasks', ['title' => 'From report ' . $report->id]);

        // The AI report job was queued (fire-and-forget) but never executed in the test.
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Modules\Forms\Jobs\CreateFormReport::class);
    }
}
