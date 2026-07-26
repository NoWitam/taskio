<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Variables\Enums\VariableType as WorkflowVariableType;
use App\Modules\Variables\Models\Constant;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workspaces\Models\Workspace;
use Database\Factories\ConstantFactory;
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
                // A REPEATER (array<object>) — a condition source since F2 (filter/count/map via the pipeline
                // builder, its element subfields exposed as `element.<field>`).
                ['id' => 'line_items', 'type' => 'repeater', 'config' => ['name' => 'Line items', 'children' => [
                    ['id' => 'amount', 'type' => 'number', 'config' => ['label' => 'Amount']],
                    ['id' => 'sku', 'type' => 'short_text', 'config' => ['label' => 'SKU']],
                ]]],
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

    /** A one-condition tree wrapping the given source + pipeline; $extra merges onto the condition. */
    private function tree(string $source, string $sourceType, array $pipeline, array $extra = []): array
    {
        return [
            'logic' => 'and',
            'children' => [
                array_merge(
                    ['kind' => 'condition', 'source' => $source, 'source_type' => $sourceType, 'pipeline' => $pipeline],
                    $extra,
                ),
            ],
        ];
    }

    /** A variable-union operation ARGUMENT (B6) — the same wire shape a step's value pipeline uses. */
    private function argVariable(string $source, string $path, string $type): array
    {
        return ['kind' => 'variable', 'ref' => ['source' => $source, 'path' => $path, 'type' => $type]];
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

    // ── array transforms (wave 1): descriptor-tracking walker ─────────────────

    public function test_accepts_array_ops_over_a_multi_source_with_precise_element_typing(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // The descriptor-tracking walker gates count/at on the running value being an ARRAY (the multi
        // source), then types each terminal precisely: count → number (chains into num_*), at → the
        // MULTI's ELEMENT (an enum, so enum_is with a source option is valid). A LEGACY text pipeline in
        // the same tree still validates unchanged (the walker change is a no-op for non-array ops).
        // The non-terminal array_at carries a typed element DEFAULT (F4) — an enum matching the element
        // base — since a following op (enum_is) would otherwise consume its nullable element.
        $tree = [
            'logic' => 'and',
            'children' => [
                ['kind' => 'condition', 'source' => 'fields.channels', 'source_type' => 'multi', 'pipeline' => [
                    ['op' => 'array_count', 'args' => []],
                    ['op' => 'num_gt', 'args' => ['value' => 0]],
                ]],
                ['kind' => 'condition', 'source' => 'fields.channels', 'source_type' => 'multi', 'pipeline' => [
                    ['op' => 'array_at', 'args' => ['index' => 1, 'default' => ['type' => 'enum', 'value' => 'fb']]],
                    ['op' => 'enum_is', 'args' => ['value' => 'fb']],
                ]],
                // legacy no-op anchor: an ordinary text pipeline is untouched by the descriptor walker.
                ['kind' => 'condition', 'source' => 'fields.imie', 'source_type' => 'text', 'pipeline' => [
                    ['op' => 'text_trim', 'args' => []],
                    ['op' => 'text_is_not_empty', 'args' => []],
                ]],
            ],
        ];

        $this->postWorkflow($owner, $workspace, $this->payload($form, $tree))->assertCreated();
    }

    public function test_rejects_an_array_op_over_a_non_array_source(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // array_count/at gate on `$descriptor['array'] === true`; a scalar (text) source fails the gate
        // with a granular op error — flat-type gating could not have expressed "any array" here.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.imie', 'text', [
            ['op' => 'array_count', 'args' => []],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.op']);

        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.age', 'number', [
            ['op' => 'array_at', 'args' => ['index' => 1]],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.op']);
    }

    public function test_rejects_a_bad_index_arg_on_array_at(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // `at`'s index is a NUMBER literal (or arg-variable) — a non-numeric literal is a granular arg 422.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.channels', 'multi', [
            ['op' => 'array_at', 'args' => ['index' => 'nope']],
            ['op' => 'enum_is', 'args' => ['value' => 'fb']],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.index']);
    }

    // ── array transforms (wave 2): element pipelines + terminal gating ────────

    /** A scoped element/index reference — valid ONLY inside an element pipeline. */
    private function scopeVariable(string $leaf, string $type): array
    {
        return ['kind' => 'variable', 'ref' => ['source' => 'scope', 'path' => $leaf, 'type' => $type]];
    }

    public function test_accepts_higher_order_array_ops_with_correct_terminals(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // map (element enum → text base), filter (element enum → boolean), sort (element enum → number),
        // reduce (seed number + reducer folding the scoped INDEX) — each terminates correctly and chains
        // to the required boolean condition terminal. The scoped `index` resolves inside the reducer.
        $tree = [
            'logic' => 'and',
            'children' => [
                ['kind' => 'condition', 'source' => 'fields.channels', 'source_type' => 'multi', 'pipeline' => [
                    ['op' => 'array_map', 'args' => ['pipeline' => [
                        ['op' => 'enum_to_text', 'args' => ['mapping' => ['fb' => 'F', 'ig' => 'I']]],
                    ]]],
                    ['op' => 'array_count', 'args' => []],
                    ['op' => 'num_gt', 'args' => ['value' => 0]],
                ]],
                ['kind' => 'condition', 'source' => 'fields.channels', 'source_type' => 'multi', 'pipeline' => [
                    ['op' => 'array_filter', 'args' => ['pipeline' => [
                        ['op' => 'enum_is', 'args' => ['value' => 'fb']],
                    ]]],
                    ['op' => 'array_count', 'args' => []],
                    ['op' => 'num_gte', 'args' => ['value' => 0]],
                ]],
                ['kind' => 'condition', 'source' => 'fields.channels', 'source_type' => 'multi', 'pipeline' => [
                    ['op' => 'array_sort', 'args' => ['pipeline' => [
                        ['op' => 'enum_to_number', 'args' => ['mapping' => ['fb' => 1, 'ig' => 2]]],
                    ]]],
                    ['op' => 'array_count', 'args' => []],
                    ['op' => 'num_gte', 'args' => ['value' => 0]],
                ]],
                ['kind' => 'condition', 'source' => 'fields.channels', 'source_type' => 'multi', 'pipeline' => [
                    ['op' => 'array_reduce', 'args' => [
                        'seed' => ['type' => 'number', 'value' => 0],
                        'reducer' => [
                            ['op' => 'num_add', 'args' => ['value' => $this->scopeVariable('index', 'number')]],
                        ],
                    ]],
                    ['op' => 'num_gte', 'args' => ['value' => 0]],
                ]],
            ],
        ];

        $this->postWorkflow($owner, $workspace, $this->payload($form, $tree))->assertCreated();
    }

    public function test_rejects_a_filter_element_pipeline_that_does_not_terminate_boolean(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // filter demands a BOOLEAN terminal; an enum→text element pipeline is a granular arg 422.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.channels', 'multi', [
            ['op' => 'array_filter', 'args' => ['pipeline' => [
                ['op' => 'enum_to_text', 'args' => ['mapping' => ['fb' => 'x', 'ig' => 'y']]],
            ]]],
            ['op' => 'array_count', 'args' => []],
            ['op' => 'num_gt', 'args' => ['value' => 0]],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.pipeline']);
    }

    public function test_rejects_a_sort_element_pipeline_that_does_not_terminate_number(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // sort demands a NUMBER key; an enum→boolean element pipeline is rejected.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.channels', 'multi', [
            ['op' => 'array_sort', 'args' => ['pipeline' => [
                ['op' => 'enum_is', 'args' => ['value' => 'fb']],
            ]]],
            ['op' => 'array_count', 'args' => []],
            ['op' => 'num_gt', 'args' => ['value' => 0]],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.pipeline']);
    }

    public function test_rejects_a_reduce_reducer_whose_terminal_is_not_the_seed_type(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // seed is number, so the reducer must terminate in number; a num→text reducer is rejected.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.channels', 'multi', [
            ['op' => 'array_reduce', 'args' => [
                'seed' => ['type' => 'number', 'value' => 0],
                'reducer' => [['op' => 'num_to_text', 'args' => []]],
            ]],
            ['op' => 'text_is_not_empty', 'args' => []],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.reducer']);
    }

    public function test_rejects_a_reduce_seed_whose_value_mismatches_its_type(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // A number-typed seed with a non-numeric value is a granular seed.value 422.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.channels', 'multi', [
            ['op' => 'array_reduce', 'args' => [
                'seed' => ['type' => 'number', 'value' => 'abc'],
                'reducer' => [['op' => 'num_add', 'args' => ['value' => $this->scopeVariable('index', 'number')]]],
            ]],
            ['op' => 'num_gte', 'args' => ['value' => 0]],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.seed.value']);
    }

    public function test_rejects_a_scope_reference_outside_an_element_pipeline(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // `element`/`index` are ONLY in scope inside an element pipeline. A scope reference used as an
        // ordinary condition-pipeline argument is REJECTED at write (fail-closed) — no scope leak.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.age', 'number', [
            ['op' => 'num_gt', 'args' => ['value' => $this->scopeVariable('element', 'number')]],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.value.ref.source']);
    }

    // ── array-of-* condition SOURCES (F2) ─────────────────────────────────────

    /** A map/filter/sort element pipeline ROOTED at a scope subfield (an object/file array). */
    private function elementUnion(string $path, string $type, array $steps = []): array
    {
        return ['kind' => 'variable', 'ref' => ['source' => 'scope', 'path' => $path, 'type' => $type], 'pipeline' => $steps];
    }

    public function test_accepts_a_condition_over_a_repeater_source(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // F2: a repeater (array<object>) IS a condition source riding `multi`. filter its rows by the
        // numeric element subfield, count the survivors, compare → boolean terminal. The threaded descriptor
        // exposes `element.amount` so the scope-rooted element pipeline validates.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.line_items', 'multi', [
            ['op' => 'array_filter', 'args' => ['pipeline' => $this->elementUnion('element.amount', 'number', [
                ['op' => 'num_gt', 'args' => ['value' => 100]],
            ])]],
            ['op' => 'array_count', 'args' => []],
            ['op' => 'num_gt', 'args' => ['value' => 0]],
        ])))->assertCreated();
    }

    public function test_rejects_an_unknown_element_subfield_in_a_repeater_condition(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // The element scope exposes exactly the repeater's declared subfields — element.nope is not one.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.line_items', 'multi', [
            ['op' => 'array_filter', 'args' => ['pipeline' => $this->elementUnion('element.nope', 'number', [
                ['op' => 'num_gt', 'args' => ['value' => 100]],
            ])]],
            ['op' => 'array_count', 'args' => []],
            ['op' => 'num_gt', 'args' => ['value' => 0]],
        ])))->assertUnprocessable();
    }

    public function test_accepts_a_condition_over_an_array_of_object_global(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        Constant::factory()->objectList('pozycje', [
            ConstantFactory::field('amount', WorkflowVariableType::NUMBER->descriptor()),
        ], [['amount' => 150], ['amount' => 50]])->create([
            'creator_id' => $owner->id,
            'workspace_id' => $workspace->id,
        ]);

        // F2: an array<object> global gates via the pipeline builder exactly like a repeater — emitted as a
        // `multi` source with its full descriptor, so the element pipeline over `element.amount` validates.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('globals.pozycje', 'multi', [
            ['op' => 'array_filter', 'args' => ['pipeline' => $this->elementUnion('element.amount', 'number', [
                ['op' => 'num_gt', 'args' => ['value' => 100]],
            ])]],
            ['op' => 'array_count', 'args' => []],
            ['op' => 'num_gt', 'args' => ['value' => 0]],
        ])))->assertCreated();
    }

    // ── array_at REQUIRED typed default (F4) ──────────────────────────────────

    public function test_rejects_a_non_terminal_array_at_without_a_default(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // array_at |> enum_is: a FOLLOWING op consumes array_at's nullable element, so a typed default is
        // REQUIRED (owner directive: enforce at write). A granular 422 under the step's `default` arg.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.channels', 'multi', [
            ['op' => 'array_at', 'args' => ['index' => 1]],
            ['op' => 'enum_is', 'args' => ['value' => 'fb']],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.default']);
    }

    public function test_rejects_an_array_at_default_whose_type_mismatches_the_element(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // The element of a checklist is ENUM; a `number` default is locked-type mismatch → a granular
        // 422 under the default's `type`.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.channels', 'multi', [
            ['op' => 'array_at', 'args' => ['index' => 1, 'default' => ['type' => 'number', 'value' => 5]]],
            ['op' => 'enum_is', 'args' => ['value' => 'fb']],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.default.type']);
    }

    public function test_accepts_a_non_terminal_array_at_with_a_valid_typed_default(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // The same non-terminal array_at with a valid enum default (a member of the element option set) is
        // accepted — the default guarantees the element the following op consumes.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.channels', 'multi', [
            ['op' => 'array_at', 'args' => ['index' => 1, 'default' => ['type' => 'enum', 'value' => 'ig']]],
            ['op' => 'enum_is', 'args' => ['value' => 'fb']],
        ])))->assertCreated();
    }

    // ── structural-element-array membership backstop (adversarial review) ──────

    public function test_rejects_a_membership_op_over_a_repeater_source_under_the_arg_key(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // A repeater (array<object>) has NO element option set, so a membership op is meaningless over it —
        // a stored `multi_excludes ''` would be an always-open gate. The write-backstop rejects the option
        // arg under its own key (scalar sourceOption `value`).
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.line_items', 'multi', [
            ['op' => 'multi_excludes', 'args' => ['value' => '']],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.value']);

        // The list-shaped membership op (sourceOptions `values`) is rejected under its key too.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.line_items', 'multi', [
            ['op' => 'multi_includes_any', 'args' => ['values' => []]],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.values']);
    }

    public function test_accepts_an_element_agnostic_array_count_over_a_repeater(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // The backstop targets only the option-membership args — array_count (element-agnostic, no option
        // arg) stays valid over a repeater, so counting its rows still works.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.line_items', 'multi', [
            ['op' => 'array_count', 'args' => []],
            ['op' => 'num_gt', 'args' => ['value' => 0]],
        ])))->assertCreated();
    }

    public function test_accepts_a_membership_op_over_a_real_enum_multi(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // A checklist's element is an ENUM (an option set) — the backstop never fires; a valid option passes.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.channels', 'multi', [
            ['op' => 'multi_excludes', 'args' => ['value' => 'fb']],
        ])))->assertCreated();
    }

    public function test_rejects_a_non_terminal_array_at_with_a_blank_text_default(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // `{type:'text', value:''}` is NOT an ENTERED default (an empty string is not a value — owner
        // directive), so the following op still consumes a nullable element → 422 under the default arg,
        // exactly like a missing default. Number 0 / boolean false would remain valid values.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.channels', 'multi', [
            ['op' => 'array_at', 'args' => ['index' => 1, 'default' => ['type' => 'text', 'value' => '']]],
            ['op' => 'enum_is', 'args' => ['value' => 'fb']],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.default']);
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

    public function test_accepts_a_source_map_with_a_variable_entry(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // PER-ENTRY (Defect-3): a condition's enum_to_text mapping entry may be a value-or-variable union.
        // The 'blog' target is a text variable (another form field); 'news' stays a literal.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.category', 'enum', [
            ['op' => 'enum_to_text', 'args' => ['mapping' => [
                'blog' => $this->argVariable('trigger', 'fields.imie', 'text'),
                'news' => 'x',
            ]]],
            ['op' => 'text_is_not_empty', 'args' => []],
        ])))->assertCreated();
    }

    public function test_rejects_a_source_map_entry_variable_referencing_a_step_output(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // A condition's reference index is built with NO prior steps, so a `steps.*` ENTRY ref is rejected
        // exactly like a top-level `steps.*` argument — the gate runs before any step executes.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.category', 'enum', [
            ['op' => 'enum_to_text', 'args' => ['mapping' => [
                'blog' => $this->argVariable('steps', 'make_task.task_id', 'text'),
            ]]],
            ['op' => 'text_is_not_empty', 'args' => []],
        ])))->assertUnprocessable()
            ->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.mapping.blog.ref.path']);
    }

    public function test_rejects_a_choice_rules_then_entry_variable_referencing_a_step_output(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // The same for a match_to_choice rule `then` ENTRY: a `steps.*` ref is not a legal condition
        // reference (no prior steps), rejected under the entry's own indexed ref.path.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.imie', 'text', [
            ['op' => 'match_to_choice', 'args' => [
                'rules' => [['when' => [['op' => 'text_equals', 'args' => ['value' => 'x']]], 'then' => $this->argVariable('steps', 'make_task.task_id', 'enum')]],
                'fallback' => 'low',
            ]],
            ['op' => 'enum_is', 'args' => ['value' => 'low']],
        ])))->assertUnprocessable()
            ->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.rules.0.then.ref.path']);
    }

    public function test_rejects_a_match_to_choice_rule_when_that_is_not_a_boolean_pipeline(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // A rule's `when` is now a boolean-terminal pipeline over the op's TEXT input, not a scalar. A
        // non-pipeline `when` is a granular error under the rule's own …when key.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.imie', 'text', [
            ['op' => 'match_to_choice', 'args' => [
                'rules' => [['when' => 'x', 'then' => 'low']],
                'fallback' => 'low',
            ]],
            ['op' => 'enum_is', 'args' => ['value' => 'low']],
        ])))->assertUnprocessable()
            ->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.rules.0.when']);
    }

    public function test_accepts_a_match_to_choice_rule_with_a_boolean_when_pipeline(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // A valid boolean-terminal `when` (text_equals → boolean) drives the rule; the pipeline ends in a
        // boolean via enum_is, so the whole condition write-validates.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.imie', 'text', [
            ['op' => 'match_to_choice', 'args' => [
                'rules' => [['when' => [['op' => 'text_equals', 'args' => ['value' => 'Jan']]], 'then' => 'low']],
                'fallback' => 'low',
            ]],
            ['op' => 'enum_is', 'args' => ['value' => 'low']],
        ])))->assertCreated();
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

    // ══════════════════════════════════════════════════════════════════════════
    //  B6 — the three owner-approved capabilities, at WRITE time
    // ══════════════════════════════════════════════════════════════════════════

    // ── 1. the opt-in per-condition `default` ─────────────────────────────────

    public function test_accepts_a_type_compatible_default_on_a_condition(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        $tree = [
            'logic' => 'and',
            'children' => [
                ['kind' => 'condition', 'source' => 'fields.imie', 'source_type' => 'text', 'default' => 'Anonim', 'pipeline' => [
                    ['op' => 'text_equals', 'args' => ['value' => 'Anonim']],
                ]],
                ['kind' => 'condition', 'source' => 'fields.age', 'source_type' => 'number', 'default' => 18, 'pipeline' => [
                    ['op' => 'num_gte', 'args' => ['value' => 18]],
                ]],
                ['kind' => 'condition', 'source' => 'fields.due', 'source_type' => 'date', 'default' => '2026-01-01', 'pipeline' => [
                    ['op' => 'date_after', 'args' => ['value' => '2025-01-01']],
                ]],
                ['kind' => 'condition', 'source' => 'fields.agree', 'source_type' => 'boolean', 'default' => false, 'pipeline' => []],
                ['kind' => 'condition', 'source' => 'fields.category', 'source_type' => 'enum', 'default' => 'blog', 'pipeline' => [
                    ['op' => 'enum_is', 'args' => ['value' => 'blog']],
                ]],
            ],
        ];

        $this->postWorkflow($owner, $workspace, $this->payload($form, $tree))->assertCreated();

        // The key round-trips through the json column, so the runtime sees the same opt-in.
        $this->assertSame('Anonim', Workflow::firstOrFail()->conditions['children'][0]['default']);
    }

    public function test_rejects_a_default_that_does_not_match_the_source_type(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        $cases = [
            ['fields.age', 'number', [['op' => 'num_gte', 'args' => ['value' => 1]]], 'abc'],
            ['fields.due', 'date', [['op' => 'date_after', 'args' => ['value' => '2025-01-01']]], '2026-1-1'],
            ['fields.agree', 'boolean', [], 'yes'],
        ];

        foreach ($cases as [$source, $type, $pipeline, $default]) {
            $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree($source, $type, $pipeline, ['default' => $default])))
                ->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.default']);
        }
    }

    public function test_rejects_a_non_scalar_default_and_one_outside_the_source_options(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // A default is a single LITERAL — never a list/object the runtime could not type.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.imie', 'text', [
            ['op' => 'text_is_not_empty', 'args' => []],
        ], ['default' => ['a', 'b']])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.default']);

        // An enum default must be one of the FIELD's options — the same membership check a literal
        // option argument passes.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.category', 'enum', [
            ['op' => 'enum_is', 'args' => ['value' => 'blog']],
        ], ['default' => 'not_an_option'])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.default']);
    }

    // ── 2. argument variables inside a condition pipeline ─────────────────────

    public function test_accepts_an_argument_variable_referencing_another_form_field(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // "does imie equal the other text field" — a field-to-field comparison, which the gate could
        // not express before B6 (a variable argument was rejected as a non-literal).
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.imie', 'text', [
            ['op' => 'text_equals', 'args' => ['value' => $this->argVariable('trigger', 'fields.imie', 'text')]],
        ])))->assertCreated();
    }

    public function test_rejects_an_argument_variable_referencing_a_step_output(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // THE point of building the gate's reference index with NO prior steps: the gate is evaluated
        // BEFORE any step runs, so `steps.*` can never be a legal reference in a condition.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.imie', 'text', [
            ['op' => 'text_equals', 'args' => ['value' => $this->argVariable('steps', 'make_task.task_id', 'text')]],
        ])))->assertUnprocessable()
            ->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.value.ref.path']);
    }

    public function test_rejects_an_argument_variable_with_an_unknown_path_or_a_disagreeing_type(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // A field that is not in the form's catalog.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.imie', 'text', [
            ['op' => 'text_equals', 'args' => ['value' => $this->argVariable('trigger', 'fields.ghost', 'text')]],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.value.ref.path']);

        // A real field whose declared ref type disagrees with the catalog (age is a number).
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.imie', 'text', [
            ['op' => 'text_equals', 'args' => ['value' => $this->argVariable('trigger', 'fields.age', 'text')]],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.pipeline.0.args.value.ref.type']);
    }

    // ── 3. globals as condition sources ───────────────────────────────────────

    /** A workspace text global. */
    private function textGlobal(User $owner, Workspace $workspace, string $key, string $value): Constant
    {
        return Constant::factory()->text($key, $value)->create([
            'creator_id' => $owner->id,
            'workspace_id' => $workspace->id,
        ]);
    }

    public function test_accepts_a_workspace_global_as_a_condition_source(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);
        $this->textGlobal($owner, $workspace, 'nazwa_marki', 'Taskio');

        // A global keeps its FULL catalog path (`globals.<key>`) while a form field keeps the legacy
        // unprefixed `fields.<id>` — the one source-vocabulary rule, both accepted by the same table.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('globals.nazwa_marki', 'text', [
            ['op' => 'text_equals', 'args' => ['value' => 'Taskio']],
        ])))->assertCreated();
    }

    public function test_rejects_a_global_source_whose_type_disagrees_with_the_catalog(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);
        $this->textGlobal($owner, $workspace, 'nazwa_marki', 'Taskio');

        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('globals.nazwa_marki', 'number', [
            ['op' => 'num_gt', 'args' => ['value' => 1]],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.source_type']);
    }

    public function test_rejects_an_unknown_global_and_the_object_container_itself_but_accepts_its_leaf(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        Constant::factory()->object('firma', [
            ConstantFactory::field('nazwa', WorkflowVariableType::TEXT->descriptor()),
            ConstantFactory::field('miasto', WorkflowVariableType::TEXT->descriptor()),
        ], ['nazwa' => 'Taskio', 'miasto' => 'Warszawa'])->create([
            'creator_id' => $owner->id,
            'workspace_id' => $workspace->id,
        ]);

        // A global that does not exist is not a source.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('globals.nie_istnieje', 'text', [
            ['op' => 'text_is_not_empty', 'args' => []],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.source']);

        // The OBJECT itself is not conditionable (no meaningful operator set) — exactly like a form
        // section container.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('globals.firma', 'text', [
            ['op' => 'text_is_not_empty', 'args' => []],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.source']);

        // …but each of its SCALAR leaves is.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('globals.firma.miasto', 'text', [
            ['op' => 'text_equals', 'args' => ['value' => 'Warszawa']],
        ])))->assertCreated();
    }

    public function test_accepts_an_argument_variable_referencing_a_global(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);
        $this->textGlobal($owner, $workspace, 'nazwa_marki', 'Taskio');

        // The other direction: a FORM FIELD compared against a workspace constant.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.imie', 'text', [
            ['op' => 'text_equals', 'args' => ['value' => $this->argVariable('globals', 'nazwa_marki', 'text')]],
        ])))->assertCreated();
    }

    // ── 4. the SOURCE vocabulary is exactly two roots ─────────────────────────

    public function test_rejects_the_full_trigger_path_spelling_of_a_form_field_source(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // THE MISTAKE AN AUTHOR WILL MAKE now that two vocabularies coexist in one editor: a condition
        // SOURCE uses the legacy UNPREFIXED `fields.<id>` (what the runtime payload carries and every
        // stored row spells), while an operation ARGUMENT ref uses the module-wide FULL path
        // (`trigger.fields.<id>`, resolved against a run context). Spelling a source the argument way
        // is not a source at all — and it must be a 422, not a gate that silently never fires.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('trigger.fields.imie', 'text', [
            ['op' => 'text_is_not_empty', 'args' => []],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.source']);

        // …and the SAME field under its real spelling is accepted, so the rejection is about the
        // vocabulary and nothing else.
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('fields.imie', 'text', [
            ['op' => 'text_is_not_empty', 'args' => []],
        ])))->assertCreated();
    }

    public function test_rejects_a_trigger_system_variable_and_a_step_output_as_a_condition_source(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->conditionForm($owner, $workspace);

        // A trigger SYSTEM variable is referenceable in a step config but is not a condition source
        // (it is not in the condition-source table).
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('trigger.submitted_at', 'date', [
            ['op' => 'date_is_past', 'args' => []],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.source']);

        // A `steps.*` output can NEVER be a condition source: the gate is evaluated BEFORE any step has
        // run. This is the source-side twin of the argument-side rejection above
        // (test_rejects_an_argument_variable_referencing_a_step_output).
        $this->postWorkflow($owner, $workspace, $this->payload($form, $this->tree('steps.make_task.task_id', 'text', [
            ['op' => 'text_is_not_empty', 'args' => []],
        ])))->assertUnprocessable()->assertJsonValidationErrors(['conditions.children.0.source']);
    }
}
