<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Write-validation of the NEW condition-TREE shape ({ logic, children[] } of groups + typed
 * pipelines) on POST /api/workflows. Pins: a happy mixed and/or tree with an enum sourceMap and a
 * date-between predicate persists; every structural / type-flow / source / arg / limit violation is a
 * granular 422 under an indexed `conditions.*` key; a tree on a schedule trigger is rejected; and the
 * LEGACY flat clause list still validates unchanged.
 */
class WorkflowConditionTreeValidationTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    /** A form whose fields cover every condition source type used below. */
    private function conditionForm(User $owner, Workspace $workspace): Form
    {
        return Form::factory()->create([
            'creator_id' => $owner->id,
            'workspace_id' => $workspace->id,
            'content' => [
                ['id' => 'imie', 'type' => 'short_text', 'config' => ['label' => 'Imie']],
                ['id' => 'age', 'type' => 'number', 'config' => ['label' => 'Age', 'step' => 1]],
                ['id' => 'due', 'type' => 'date', 'config' => ['label' => 'Due']],
                ['id' => 'agree', 'type' => 'checkbox', 'config' => ['label' => 'Agree']],
                ['id' => 'category', 'type' => 'select', 'config' => [
                    'label' => 'Category', 'multiple' => false,
                    'options' => [['value' => 'blog'], ['value' => 'news']],
                ]],
                ['id' => 'channels', 'type' => 'select', 'config' => [
                    'label' => 'Channels', 'multiple' => true,
                    'options' => [['value' => 'fb'], ['value' => 'ig']],
                ]],
            ],
        ]);
    }

    /** Build a form_submitted create payload gated by the given $conditions tree. */
    private function payload(Form $form, array $conditions): array
    {
        return [
            'name' => 'Conditioned workflow',
            'trigger_type' => 'form_submitted',
            'trigger_config' => ['form_id' => $form->id],
            'conditions' => $conditions,
            'steps' => [
                ['type' => 'create_task', 'key' => 'make_task', 'config' => ['title' => 'Follow up']],
            ],
        ];
    }

    /** POST the payload as an authenticated member of the form's workspace. */
    private function postWorkflow(User $owner, Workspace $workspace, array $payload)
    {
        return $this->actingAs($owner)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/workflows', $payload);
    }

    /** A one-condition tree wrapping the given source + pipeline. */
    private function tree(string $source, string $sourceType, array $pipeline): array
    {
        return [
            'logic' => 'and',
            'children' => [
                ['kind' => 'condition', 'source' => $source, 'source_type' => $sourceType, 'pipeline' => $pipeline],
            ],
        ];
    }

    // ── happy path ────────────────────────────────────────────────────────────

    public function test_accepts_and_persists_a_mixed_and_or_tree(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        $tree = [
            'logic' => 'and',
            'children' => [
                ['kind' => 'condition', 'source' => 'fields.imie', 'source_type' => 'text', 'pipeline' => [
                    ['op' => 'text_trim', 'args' => []],
                    ['op' => 'text_equals', 'args' => ['value' => 'Jan']],
                ]],
                ['kind' => 'group', 'logic' => 'or', 'children' => [
                    // enum sourceMap (date targets) → date predicate.
                    ['kind' => 'condition', 'source' => 'fields.category', 'source_type' => 'enum', 'pipeline' => [
                        ['op' => 'enum_to_date', 'args' => ['mapping' => ['blog' => '2026-01-01', 'news' => '2026-06-01']]],
                        ['op' => 'date_before', 'args' => ['value' => '2026-12-31']],
                    ]],
                    // date between.
                    ['kind' => 'condition', 'source' => 'fields.due', 'source_type' => 'date', 'pipeline' => [
                        ['op' => 'date_between', 'args' => ['from' => '2026-01-01', 'to' => '2026-12-31']],
                    ]],
                ]],
            ],
        ];

        $response = $this->postWorkflow($owner, $workspace, $this->payload($form, $tree))->assertCreated();

        $response->assertJsonPath('data.conditions.logic', 'and');
        $this->assertSame('condition', $response->json('data.conditions.children.0.kind'));
        $this->assertSame('or', $response->json('data.conditions.children.1.logic'));

        // The tree round-trips through the json-cast column unchanged.
        $workflow = Workflow::firstOrFail();
        $this->assertSame($tree, $workflow->conditions);
    }

    public function test_accepts_enum_and_multi_option_args(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        $tree = [
            'logic' => 'or',
            'children' => [
                ['kind' => 'condition', 'source' => 'fields.category', 'source_type' => 'enum', 'pipeline' => [
                    ['op' => 'enum_in', 'args' => ['values' => ['blog', 'news']]],
                ]],
                ['kind' => 'condition', 'source' => 'fields.channels', 'source_type' => 'multi', 'pipeline' => [
                    ['op' => 'multi_includes', 'args' => ['value' => 'fb']],
                ]],
                ['kind' => 'condition', 'source' => 'fields.agree', 'source_type' => 'boolean', 'pipeline' => []],
            ],
        ];

        $this->postWorkflow($owner, $workspace, $this->payload($form, $tree))->assertCreated();
    }

    // ── structural / type-flow 422s ───────────────────────────────────────────

    public function test_rejects_an_unknown_operation(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.imie', 'text', [
            ['op' => 'not_a_real_op', 'args' => []],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.op']);
    }

    public function test_rejects_an_operation_whose_input_type_mismatches(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // num_add expects a number, but the source is text.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.imie', 'text', [
            ['op' => 'num_add', 'args' => ['value' => 1]],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.op']);
    }

    public function test_rejects_a_pipeline_that_does_not_end_in_boolean(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.imie', 'text', [
            ['op' => 'text_uppercase', 'args' => []],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline']);
    }

    // ── source / catalog 422s ─────────────────────────────────────────────────

    public function test_rejects_a_source_outside_the_form_catalog(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.ghost', 'text', [
            ['op' => 'text_is_not_empty', 'args' => []],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.source']);
    }

    public function test_rejects_a_source_type_that_disagrees_with_the_catalog(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // fields.imie is text in the catalog, but the tree declares it number.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.imie', 'number', [
            ['op' => 'num_gt', 'args' => ['value' => 1]],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.source_type']);
    }

    // ── argument 422s ─────────────────────────────────────────────────────────

    public function test_rejects_a_source_option_outside_the_field_options(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.category', 'enum', [
            ['op' => 'enum_is', 'args' => ['value' => 'not_an_option']],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.value']);
    }

    public function test_rejects_a_source_map_with_a_foreign_key(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.category', 'enum', [
            ['op' => 'enum_to_text', 'args' => ['mapping' => ['ghost' => 'x']]],
            ['op' => 'text_is_not_empty', 'args' => []],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.mapping.ghost']);
    }

    public function test_rejects_a_source_map_with_an_empty_value_and_a_bad_typed_target(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // number mapType: 'blog' has an empty value, 'news' has a non-numeric value.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.category', 'enum', [
            ['op' => 'enum_to_number', 'args' => ['mapping' => ['blog' => '', 'news' => 'abc']]],
            ['op' => 'num_gte', 'args' => ['value' => 0]],
        ])))->assertUnprocessable()->assertJsonValidationErrors([
            'conditions.children.0.pipeline.0.args.mapping.blog',
            'conditions.children.0.pipeline.0.args.mapping.news',
        ]);
    }

    public function test_rejects_a_missing_required_arg(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // text_equals requires a `value` arg.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.imie', 'text', [
            ['op' => 'text_equals', 'args' => []],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.value']);
    }

    // ── hard limits 422s ──────────────────────────────────────────────────────

    public function test_rejects_an_empty_group(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        $this->postWorkflow($owner, $workspace, $this->payload($form, ['logic' => 'and', 'children' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors(['conditions.children']);
    }

    public function test_rejects_too_many_children(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        $child = ['kind' => 'condition', 'source' => 'fields.imie', 'source_type' => 'text', 'pipeline' => [['op' => 'text_is_not_empty', 'args' => []]]];

        $this->postWorkflow($owner, $workspace, $this->payload($form, ['logic' => 'or', 'children' => array_fill(0, 11, $child)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['conditions.children']);
    }

    public function test_rejects_too_many_pipeline_steps(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        $steps = array_fill(0, 10, ['op' => 'text_trim', 'args' => []]);
        $steps[] = ['op' => 'text_is_not_empty', 'args' => []];

        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.imie', 'text', $steps)))
            ->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline']);
    }

    public function test_rejects_a_tree_nested_too_deeply(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        $node = ['kind' => 'condition', 'source' => 'fields.imie', 'source_type' => 'text', 'pipeline' => [['op' => 'text_is_not_empty', 'args' => []]]];
        for ($i = 0; $i < 6; $i++) {
            $node = ['kind' => 'group', 'logic' => 'and', 'children' => [$node]];
        }

        $response = $this->postWorkflow($owner, $workspace, $this->payload($form, $node))->assertUnprocessable();

        $conditionErrors = preg_grep('/^conditions/', array_keys($response->json('errors')));
        $this->assertNotEmpty($conditionErrors);
    }

    // ── trigger gate + legacy compatibility ───────────────────────────────────

    public function test_rejects_a_tree_on_a_schedule_trigger(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);

        $this->actingAs($owner)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/workflows', [
                'name' => 'Scheduled',
                'trigger_type' => 'schedule',
                'trigger_config' => ['schedule' => ['time' => ['mode' => 'at', 'at' => ['09:00']]]],
                'conditions' => $this->tree('fields.imie', 'text', [['op' => 'text_is_not_empty', 'args' => []]]),
                'steps' => [['type' => 'create_task', 'key' => 'k', 'config' => ['title' => 'x']]],
            ])
            ->assertUnprocessable()->assertJsonValidationErrors(['conditions']);
    }

    public function test_tree_requires_a_selected_form(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);

        $this->actingAs($owner)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/workflows', [
                'name' => 'No form',
                'trigger_type' => 'form_submitted',
                'trigger_config' => ['form_id' => null],
                'conditions' => $this->tree('fields.imie', 'text', [['op' => 'text_is_not_empty', 'args' => []]]),
                'steps' => [['type' => 'create_task', 'key' => 'k', 'config' => ['title' => 'x']]],
            ])
            ->assertUnprocessable()->assertJsonValidationErrors(['trigger_config.form_id']);
    }

    public function test_legacy_flat_clause_list_still_validates(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // The old flat shape is untouched — a valid clause list still creates the workflow.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            ['field' => 'fields.imie', 'field_type' => 'text', 'operator' => 'equals', 'value' => 'Jan'],
        ]))->assertCreated()->assertJsonPath('data.conditions.0.operator', 'equals');
    }
}
