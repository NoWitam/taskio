<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Variables\Models\CustomFunction;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CUSTOM FUNCTIONS ↔ WORKFLOWS (Phase 3b): a function is surfaced in the workflow operation catalog, a
 * workflow pipeline may reference it (write-validated against its declared input/return), and a function
 * cannot be deleted while a workflow still references it.
 */
class WorkflowCustomFunctionTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    private function form(User $owner, Workspace $workspace): Form
    {
        return Form::factory()->enabled()->create([
            'creator_id' => $owner->id,
            'workspace_id' => $workspace->id,
            'content' => [
                ['id' => 'due', 'type' => 'date', 'config' => ['label' => 'Due']],
            ],
        ]);
    }

    private function fn(User $owner, Workspace $workspace, array $attributes): CustomFunction
    {
        return CustomFunction::factory()->create(array_merge([
            'creator_id' => $owner->id,
            'workspace_id' => $workspace->id,
        ], $attributes));
    }

    /** A create_task workflow whose single step's deadline runs $pipeline over the form's `due` date field. */
    private function deadlinePayload(Form $form, array $pipeline): array
    {
        return [
            'name' => 'Fn workflow',
            'trigger_type' => 'form_submitted',
            'trigger_config' => ['form_id' => $form->id],
            'steps' => [['type' => 'create_task', 'key' => 'make_task', 'config' => [
                'title' => 'T',
                'deadline' => [
                    'kind' => 'variable',
                    'ref' => ['source' => 'trigger', 'path' => 'fields.due', 'type' => 'date'],
                    'pipeline' => $pipeline,
                ],
            ]]],
        ];
    }

    private function postWorkflow(User $owner, Workspace $workspace, array $payload)
    {
        return $this->actingAs($owner)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/workflows', $payload);
    }

    // ---- catalog merge --------------------------------------------------------

    public function test_the_catalog_emits_the_function_op_with_label_and_description(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);

        $fn = $this->fn($owner, $workspace, [
            'name' => 'Is over budget',
            'description' => 'Whether the amount exceeds a threshold',
            'input_type' => 'number',
            'args' => [['name' => 'threshold', 'type' => 'number']],
            'return_type' => 'boolean',
            'body' => [['op' => 'num_gt', 'args' => ['value' => 100]]],
        ]);

        $operations = collect(
            $this->actingAs($owner)->withHeader('X-Workspace-Id', $workspace->id)
                ->getJson('/api/workflows/catalog?trigger_type=schedule')
                ->assertOk()
                ->json('data.operations')
        );

        $op = $operations->firstWhere('id', 'fn:' . $fn->id);

        $this->assertNotNull($op, 'the function op must be merged into the operation catalog');
        $this->assertSame('number', $op['input']);
        $this->assertSame('boolean', $op['output']);
        $this->assertSame('Is over budget', $op['label']);
        $this->assertSame('Whether the amount exceeds a threshold', $op['description']);
        $this->assertSame([['id' => 'threshold', 'type' => 'number']], $op['args']);
    }

    // ---- write validation of a workflow that references a function ------------

    public function test_a_workflow_referencing_a_type_compatible_function_is_accepted(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // fn:shift is date -> date, so it type-flows the deadline pipeline (date source → date terminal).
        // Acceptance proves the function is threaded into the write-validator's refCtx (an unresolved
        // function would be an "unknown op" 422).
        $shift = $this->fn($owner, $workspace, [
            'input_type' => 'date', 'return_type' => 'date', 'args' => [],
            'body' => [['op' => 'date_add_days', 'args' => ['value' => 1]]],
        ]);

        $this->postWorkflow($owner, $workspace, $this->deadlinePayload($form, [
            ['op' => 'fn:' . $shift->id],
        ]))->assertCreated();
    }

    public function test_a_workflow_referencing_a_type_incompatible_function_is_write_rejected(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // fn:shout expects TEXT, but the deadline pipeline runs from the DATE `due` field → the function's
        // input type does not match the running type → a granular 422 under the pipeline key.
        $shout = $this->fn($owner, $workspace, [
            'input_type' => 'text', 'return_type' => 'text', 'args' => [],
            'body' => [['op' => 'text_uppercase']],
        ]);

        $this->postWorkflow($owner, $workspace, $this->deadlinePayload($form, [
            ['op' => 'fn:' . $shout->id],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.deadline.pipeline.0.op']);
    }

    // ---- delete guard (workflow references) ----------------------------------

    public function test_a_function_referenced_by_a_workflow_cannot_be_deleted(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        $shift = $this->fn($owner, $workspace, [
            'input_type' => 'date', 'return_type' => 'date', 'args' => [],
            'body' => [['op' => 'date_add_days', 'args' => ['value' => 1]]],
        ]);

        // A live workflow now references fn:shift in its step config.
        $this->postWorkflow($owner, $workspace, $this->deadlinePayload($form, [
            ['op' => 'fn:' . $shift->id],
        ]))->assertCreated();

        // The delete is blocked (422) while the workflow references it — the function survives.
        $this->actingAs($owner)->withHeader('X-Workspace-Id', $workspace->id)
            ->deleteJson("/api/functions/{$shift->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('custom_functions', ['id' => $shift->id]);
    }

    public function test_an_unreferenced_function_is_deletable(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);

        $orphan = $this->fn($owner, $workspace, [
            'input_type' => 'text', 'return_type' => 'text', 'args' => [],
            'body' => [['op' => 'text_uppercase']],
        ]);

        $this->actingAs($owner)->withHeader('X-Workspace-Id', $workspace->id)
            ->deleteJson("/api/functions/{$orphan->id}")
            ->assertOk();
    }
}
