<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Workflows\Enums\WorkflowVariableType;
use App\Modules\Workflows\Models\WorkflowGlobal;
use App\Modules\Workspaces\Models\Workspace;
use Database\Factories\WorkflowGlobalFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Write-validation of the SB1 value-or-variable PIPELINE on a step's structured fields (create_task
 * priority/deadline, create_form_report submissions_*). Pins: a pipeline is type-flowed from the
 * ref's declared type to the field's REQUIRED terminal type; a wrong terminal / bad op arg / foreign
 * ref is a granular 422 under an indexed `steps.*.config.<field>.pipeline.*` key; and a pipeline-less
 * variable ref (the pre-SB1 shape) still validates unchanged for a plain value field (deadline).
 *
 * CHOICE fields (priority → TaskPriority::ids()) are stricter: a variable ref MUST run a mapping
 * pipeline that ENDS in a choice-producing op (enum_to_choice / match_to_choice) and whose target
 * option values are all ⊆ the destination field's option set; a bare/identity ref or a non-choice
 * terminal is rejected. The target options are injected per-field (not part of the op descriptor).
 */
class WorkflowStepValuePipelineValidationTest extends TestCase
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
                ['id' => 'category', 'type' => 'select', 'config' => [
                    'label' => 'Category', 'multiple' => false,
                    'options' => [['value' => 'blog'], ['value' => 'news']],
                ]],
                // A MULTI-select (multiple:true → a `multi` variable) — the sourceOptions arg-variable
                // target (phase-4b): a multi op arg supplied by a variable needs a multi ref in the index.
                ['id' => 'tags', 'type' => 'select', 'config' => [
                    'label' => 'Tags', 'multiple' => true,
                    'options' => [['value' => 'a'], ['value' => 'b']],
                ]],
                ['id' => 'headline', 'type' => 'short_text', 'config' => ['label' => 'Headline']],
                ['id' => 'upload', 'type' => 'image', 'config' => ['label' => 'Upload']],
                // A SECTION with a scalar leaf (a flat top-level path) + a REPEATER whose element field
                // is NOT a top-level path — the two regression anchors for the subfield-index scope.
                ['id' => 'details', 'type' => 'section', 'config' => ['name' => 'Details', 'children' => [
                    ['id' => 'note', 'type' => 'long_text', 'config' => ['label' => 'Note']],
                ]]],
                ['id' => 'items', 'type' => 'repeater', 'config' => ['name' => 'Items', 'children' => [
                    ['id' => 'item_name', 'type' => 'short_text', 'config' => ['label' => 'Item name']],
                ]]],
            ],
        ]);
    }

    /** A create_task workflow whose single step carries $config, gated on the given form. */
    private function payload(Form $form, array $config): array
    {
        return [
            'name' => 'Piped workflow',
            'trigger_type' => 'form_submitted',
            'trigger_config' => ['form_id' => $form->id],
            'steps' => [['type' => 'create_task', 'key' => 'make_task', 'config' => array_merge(['title' => 'T'], $config)]],
        ];
    }

    private function postWorkflow(User $owner, Workspace $workspace, array $payload)
    {
        return $this->actingAs($owner)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/workflows', $payload);
    }

    public function test_accepts_a_deadline_pipeline_that_terminates_in_a_date(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'deadline' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.due', 'type' => 'date'],
                'pipeline' => [['op' => 'date_add_days', 'args' => ['value' => 3]]],
            ],
        ]))->assertCreated();
    }

    public function test_accepts_a_priority_pipeline_that_terminates_in_a_choice(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // Priority is a CHOICE field: enum_to_choice maps each source option to a TaskPriority option.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.category', 'type' => 'enum'],
                'pipeline' => [['op' => 'enum_to_choice', 'args' => ['mapping' => ['blog' => 'high', 'news' => 'low']]]],
            ],
        ]))->assertCreated();
    }

    public function test_an_array_valued_literal_priority_is_a_clean_422_not_a_500(): void
    {
        // REGRESSION (review): the priority literal predicate cast the value to string
        // unconditionally, so a `{kind:'literal', value:[...]}` union raised an "Array to string
        // conversion" ErrorException (HTTP 500). It must fail closed as a granular 422 instead.
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => ['kind' => 'literal', 'value' => ['not', 'a', 'scalar']],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.priority']);
    }

    public function test_rejects_a_priority_choice_mapping_value_outside_the_priority_options(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // `nope` is not a TaskPriority option — the mapped VALUE must be in the target field's set.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.category', 'type' => 'enum'],
                'pipeline' => [['op' => 'enum_to_choice', 'args' => ['mapping' => ['blog' => 'high', 'news' => 'nope']]]],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.priority.pipeline.0.args.mapping.news']);
    }

    public function test_rejects_an_identity_enum_ref_for_priority(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // A choice field cannot be set by a bare (pipeline-less) variable — it needs a mapping pipeline.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.category', 'type' => 'enum'],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.priority.pipeline']);
    }

    public function test_rejects_a_non_choice_terminal_for_priority(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // enum_to_text ends in TEXT, not a choice — no longer valid for a choice field like priority.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.category', 'type' => 'enum'],
                'pipeline' => [['op' => 'enum_to_text', 'args' => ['mapping' => ['blog' => 'x', 'news' => 'y']]]],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.priority.pipeline']);
    }

    public function test_accepts_a_match_to_choice_priority_pipeline(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // A TEXT source mapped to a priority by match rules + a required fallback (all ∈ TaskPriority).
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.headline', 'type' => 'text'],
                'pipeline' => [['op' => 'match_to_choice', 'args' => [
                    'rules' => [
                        ['when' => 'BREAKING', 'then' => 'urgent'],
                        ['when' => 'note', 'then' => 'low'],
                    ],
                    'fallback' => 'medium',
                ]]],
            ],
        ]))->assertCreated();
    }

    public function test_rejects_a_match_to_choice_with_out_of_set_then_and_fallback(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // Both a rule `then` and the `fallback` are outside the TaskPriority option set.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.headline', 'type' => 'text'],
                'pipeline' => [['op' => 'match_to_choice', 'args' => [
                    'rules' => [['when' => 'BREAKING', 'then' => 'ghostpriority']],
                    'fallback' => 'nope',
                ]]],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'steps.0.config.priority.pipeline.0.args.rules.0.then',
            'steps.0.config.priority.pipeline.0.args.fallback',
        ]);
    }

    public function test_rejects_a_deadline_pipeline_that_does_not_end_in_a_date(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // date_day yields a NUMBER, but deadline requires a DATE terminal.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'deadline' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.due', 'type' => 'date'],
                'pipeline' => [['op' => 'date_day', 'args' => []]],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.deadline.pipeline']);
    }

    public function test_rejects_an_op_whose_input_type_mismatches_the_ref_type(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // num_add expects a number but the ref (and first op) run on a date.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'deadline' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.due', 'type' => 'date'],
                'pipeline' => [['op' => 'num_add', 'args' => ['value' => 1]]],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.deadline.pipeline.0.op']);
    }

    public function test_rejects_a_source_map_with_a_foreign_option_key(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // The source-side KEY check is intact under the choice op: `ghost` is not a category option
        // (the mapped value `high` is a valid priority, so only the foreign KEY is reported).
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.category', 'type' => 'enum'],
                'pipeline' => [['op' => 'enum_to_choice', 'args' => ['mapping' => ['ghost' => 'high']]]],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.priority.pipeline.0.args.mapping.ghost']);
    }

    public function test_rejects_a_ref_type_that_disagrees_with_the_catalog(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // fields.category is enum in the catalog; the ref declares text.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.category', 'type' => 'text'],
                'pipeline' => [['op' => 'text_uppercase', 'args' => []]],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.priority.ref.type']);
    }

    public function test_rejects_a_reference_to_an_unknown_step_output(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // No prior step named `ghost` exists → the reference is not resolvable for this step.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'deadline' => [
                'kind' => 'variable',
                'ref' => ['source' => 'steps', 'path' => 'ghost.when', 'type' => 'date'],
                'pipeline' => [['op' => 'date_add_days', 'args' => ['value' => 1]]],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.deadline.ref.path']);
    }

    public function test_a_pipeline_less_variable_ref_still_validates(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // The pre-SB1 shape (no pipeline) is unchanged — a well-formed ref creates the workflow.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'deadline' => ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.due', 'type' => 'date']],
        ]))->assertCreated();
    }

    // ---- attachments (FILE union) --------------------------------------------

    public function test_accepts_a_literal_file_attachment(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // A literal attachment is a file uuid (a Disk pick). Only the SHAPE is checked at write
        // time — the concrete file is re-resolved (and scoped) at run time.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'attachments' => ['kind' => 'literal', 'value' => (string) \Illuminate\Support\Str::uuid()],
        ]))->assertCreated();
    }

    public function test_accepts_a_file_variable_attachment(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // A file field resolves to a FILE terminal — valid for the attachments slot as-is.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'attachments' => ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.upload', 'type' => 'file']],
        ]))->assertCreated();
    }

    public function test_rejects_a_non_uuid_literal_attachment(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'attachments' => ['kind' => 'literal', 'value' => 'not-a-file-id'],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.attachments']);
    }

    public function test_rejects_a_file_attachment_variable_that_terminates_in_text(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // file_name turns the file into TEXT — the attachments slot requires a FILE terminal.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'attachments' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.upload', 'type' => 'file'],
                'pipeline' => [['op' => 'file_name', 'args' => []]],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.attachments.pipeline']);
    }

    // ---- composite file SUBFIELD references (phase-2b.1) ----------------------
    //
    // A `file` variable exposes 5 referenceable scalar subfields (<file>.{id,name,type,size,url};
    // id/name/type/url=text, size=number). The write-validation reference index now enumerates those
    // paths, so a PIPELINE-bearing subfield ref in a structured slot type-flows from the SUBFIELD's
    // type instead of being rejected as an unknown variable. REPEATER element subfields stay
    // non-referenceable (per-element access is the deferred R2 loop).

    public function test_accepts_a_text_pipeline_on_a_file_name_subfield(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // <file>.name is TEXT: a text op flows into the priority choice mapping. Pins that the subfield
        // path is a KNOWN variable and its type gate accepts a text op (was a 422 unknown-path before).
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.upload.name', 'type' => 'text'],
                'pipeline' => [
                    ['op' => 'text_uppercase', 'args' => []],
                    ['op' => 'match_to_choice', 'args' => [
                        'rules' => [['when' => 'RAPORT.PDF', 'then' => 'high']],
                        'fallback' => 'low',
                    ]],
                ],
            ],
        ]))->assertCreated();
    }

    public function test_accepts_a_number_pipeline_on_a_file_size_subfield(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // <file>.size is NUMBER: a number op runs first (proving the subfield is typed number in the
        // index), then num_to_text bridges into the priority choice mapping.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.upload.size', 'type' => 'number'],
                'pipeline' => [
                    ['op' => 'num_to_text', 'args' => []],
                    ['op' => 'match_to_choice', 'args' => [
                        'rules' => [['when' => '2048', 'then' => 'high']],
                        'fallback' => 'low',
                    ]],
                ],
            ],
        ]))->assertCreated();
    }

    public function test_a_file_subfield_pipeline_ref_persists_verbatim(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        $pipeline = [
            ['op' => 'text_uppercase', 'args' => []],
            ['op' => 'match_to_choice', 'args' => ['rules' => [['when' => 'RAPORT.PDF', 'then' => 'high']], 'fallback' => 'low']],
        ];

        $response = $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.upload.name', 'type' => 'text'],
                'pipeline' => $pipeline,
            ],
        ]))->assertCreated();

        // The subfield ref + pipeline round-trips into the stored step config unchanged.
        $workflow = \App\Modules\Workflows\Models\Workflow::findOrFail($response->json('data.id'));
        $priority = $workflow->steps[0]['config']['priority'];

        $this->assertSame('fields.upload.name', $priority['ref']['path']);
        $this->assertSame('text', $priority['ref']['type']);
        $this->assertSame($pipeline, $priority['pipeline']);
    }

    public function test_rejects_a_number_op_on_a_text_file_subfield_with_the_type_gate_error(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // num_add expects a number but <file>.name is TEXT: the failure is the INPUT-TYPE gate under
        // `.pipeline.0.op` (the same error a type-mismatched scalar ref gives), NOT an unknown-path
        // error — proving the subfield path is now recognised by the reference index.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'deadline' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.upload.name', 'type' => 'text'],
                'pipeline' => [['op' => 'num_add', 'args' => ['value' => 1]]],
            ],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.0.config.deadline.pipeline.0.op'])
            ->assertJsonMissingValidationErrors(['steps.0.config.deadline.ref.path']);
    }

    public function test_rejects_a_pipeline_bearing_repeater_element_ref(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // fields.items.item_name is a REPEATER element — deliberately NOT enumerated (per-element
        // access is deferred to R2), so a pipeline-bearing ref to it stays an unknown variable. The
        // pipeline shape is otherwise valid, so the ONLY error is the unresolvable path.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.items.item_name', 'type' => 'text'],
                'pipeline' => [['op' => 'match_to_choice', 'args' => [
                    'rules' => [['when' => 'x', 'then' => 'high']],
                    'fallback' => 'low',
                ]]],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.priority.ref.path']);
    }

    public function test_accepts_a_pipeline_on_a_section_leaf_subfield(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // A SECTION leaf (fields.details.note, text) is a flat top-level path already enumerated by the
        // leaf pass — a pipeline-bearing ref to it keeps validating (unchanged by the file-subfield work).
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.details.note', 'type' => 'text'],
                'pipeline' => [['op' => 'match_to_choice', 'args' => [
                    'rules' => [['when' => 'urgent', 'then' => 'urgent']],
                    'fallback' => 'low',
                ]]],
            ],
        ]))->assertCreated();
    }

    // ---- operation ARGUMENTS supplied by a variable (phase-4a) ----------------
    //
    // An op arg may be a value-or-variable union instead of a constant literal, validated against the
    // SAME reference index the top-level ref uses: its resolved type must match the arg's DECLARED type,
    // its sub-pipeline is validated recursively, and nesting is capped (MAX_ARG_VARIABLE_DEPTH).

    /**
     * A number arg-variable (ref `fields.upload.size`) whose num_add pipeline nests another such
     * arg-variable, $levels deep. $levels = 1 is a bare ref (no pipeline) — the chain's bottom.
     */
    private function numberArgChain(int $levels): array
    {
        $ref = ['source' => 'trigger', 'path' => 'fields.upload.size', 'type' => 'number'];

        if ($levels <= 1) {
            return ['kind' => 'variable', 'ref' => $ref];
        }

        return [
            'kind' => 'variable',
            'ref' => $ref,
            'pipeline' => [['op' => 'num_add', 'args' => ['value' => $this->numberArgChain($levels - 1)]]],
        ];
    }

    public function test_accepts_an_op_argument_supplied_by_a_variable(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // date_add_days' `value` arg (declared NUMBER) is supplied by a variable — the file's `size`
        // subfield (a NUMBER). The arg's resolved type matches, the pipeline still ends in a DATE.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'deadline' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.due', 'type' => 'date'],
                'pipeline' => [['op' => 'date_add_days', 'args' => ['value' => [
                    'kind' => 'variable',
                    'ref' => ['source' => 'trigger', 'path' => 'fields.upload.size', 'type' => 'number'],
                ]]]],
            ],
        ]))->assertCreated();
    }

    public function test_accepts_an_op_argument_variable_with_its_own_pipeline(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // The arg-variable itself carries a sub-pipeline (num_add) that still terminates in NUMBER —
        // the arg's declared type — so the nested transform validates recursively.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'deadline' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.due', 'type' => 'date'],
                'pipeline' => [['op' => 'date_add_days', 'args' => ['value' => [
                    'kind' => 'variable',
                    'ref' => ['source' => 'trigger', 'path' => 'fields.upload.size', 'type' => 'number'],
                    'pipeline' => [['op' => 'num_add', 'args' => ['value' => 2]]],
                ]]]],
            ],
        ]))->assertCreated();
    }

    public function test_rejects_an_op_argument_variable_whose_type_mismatches_the_arg(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // date_add_days' `value` is declared NUMBER but the arg-variable references `fields.headline`
        // (TEXT) — the SAME type gate a literal arg gets rejects the mismatch under the arg's key.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'deadline' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.due', 'type' => 'date'],
                'pipeline' => [['op' => 'date_add_days', 'args' => ['value' => [
                    'kind' => 'variable',
                    'ref' => ['source' => 'trigger', 'path' => 'fields.headline', 'type' => 'text'],
                ]]]],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.deadline.pipeline.0.args.value']);
    }

    public function test_rejects_an_op_argument_variable_referencing_an_unknown_variable(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // The arg-variable must be a KNOWN variable in the reference index — `fields.ghost` is not a
        // field of the form, so it is rejected exactly like an unknown top-level ref.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'deadline' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.due', 'type' => 'date'],
                'pipeline' => [['op' => 'date_add_days', 'args' => ['value' => [
                    'kind' => 'variable',
                    'ref' => ['source' => 'trigger', 'path' => 'fields.ghost', 'type' => 'number'],
                ]]]],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.deadline.pipeline.0.args.value.ref.path']);
    }

    public function test_rejects_an_op_argument_variable_referencing_a_non_whitelisted_root(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // Exfil safety at the arg level: only trigger/steps/globals roots are references — `env` is not.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'deadline' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.due', 'type' => 'date'],
                'pipeline' => [['op' => 'date_add_days', 'args' => ['value' => [
                    'kind' => 'variable',
                    'ref' => ['source' => 'env', 'path' => 'SECRET', 'type' => 'number'],
                ]]]],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.deadline.pipeline.0.args.value.ref.source']);
    }

    public function test_rejects_an_op_argument_variable_nested_beyond_the_cap(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // A FOUR-level arg-variable chain exceeds MAX_ARG_VARIABLE_DEPTH (3) and is rejected at the
        // deepest arg's key — the write-time belt matching the runtime resolver's fail-soft boundary.
        $deepKey = 'steps.0.config.deadline' . str_repeat('.pipeline.0.args.value', 4);

        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'deadline' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.due', 'type' => 'date'],
                'pipeline' => [['op' => 'date_add_days', 'args' => ['value' => $this->numberArgChain(4)]]],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors([$deepKey]);
    }

    public function test_an_op_argument_variable_persists_verbatim(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        $pipeline = [['op' => 'date_add_days', 'args' => ['value' => [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.upload.size', 'type' => 'number'],
            'pipeline' => [['op' => 'num_add', 'args' => ['value' => 2]]],
        ]]]];

        $response = $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'deadline' => ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.due', 'type' => 'date'], 'pipeline' => $pipeline],
        ]))->assertCreated();

        // The arg-variable (ref + its own sub-pipeline) round-trips into the stored step config unchanged.
        $workflow = \App\Modules\Workflows\Models\Workflow::findOrFail($response->json('data.id'));

        $this->assertSame($pipeline, $workflow->steps[0]['config']['deadline']['pipeline']);
    }

    // ---- op ARGUMENTS: option / multi-option / structural variables (phase-4b) ----
    //
    // Beyond the value args, EVERY op-arg control now accepts a variable: single/multi OPTION args
    // (enum|text / multi ref, option-set membership deferred to runtime) and STRUCTURAL args
    // (sourceMap/choiceRules, gated LOOSELY — any whitelisted + indexed ref, exact shape deferred to
    // runtime fail-soft). All are validated against the SAME reference index the top-level ref uses.

    public function test_accepts_a_source_map_arg_supplied_by_a_variable(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // enum_to_choice's `mapping` (a sourceMap = STRUCTURAL arg) is a VARIABLE, not a literal map. The
        // loose gate accepts any whitelisted + indexed ref (fields.details is an object container in the
        // index); the exact {option: target} shape is deferred to runtime fail-soft.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.category', 'type' => 'enum'],
                'pipeline' => [['op' => 'enum_to_choice', 'args' => ['mapping' => [
                    'kind' => 'variable',
                    'ref' => ['source' => 'trigger', 'path' => 'fields.details', 'type' => 'object'],
                ]]]],
            ],
        ]))->assertCreated();
    }

    public function test_accepts_choice_rules_and_fallback_args_supplied_by_variables(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // match_to_choice's `rules` (choiceRules = STRUCTURAL) AND `fallback` (choiceFallback = single
        // OPTION) are BOTH variables: rules → a loose indexed ref, fallback → an enum|text ref. Target
        // option-set membership is unverifiable for a variable, so it is deferred to runtime fail-soft.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.headline', 'type' => 'text'],
                'pipeline' => [['op' => 'match_to_choice', 'args' => [
                    'rules' => ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.details', 'type' => 'object']],
                    'fallback' => ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.headline', 'type' => 'text']],
                ]]],
            ],
        ]))->assertCreated();
    }

    public function test_accepts_a_source_option_arg_supplied_by_a_variable(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // enum_is' `value` (sourceOption = single OPTION arg) is an enum variable; its boolean is bridged
        // through bool_to_text into a choice terminal so it fits the priority field.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.category', 'type' => 'enum'],
                'pipeline' => [
                    ['op' => 'enum_is', 'args' => ['value' => [
                        'kind' => 'variable',
                        'ref' => ['source' => 'trigger', 'path' => 'fields.category', 'type' => 'enum'],
                    ]]],
                    ['op' => 'bool_to_text', 'args' => ['when_true' => 'hot', 'when_false' => 'cold']],
                    ['op' => 'match_to_choice', 'args' => [
                        'rules' => [['when' => 'hot', 'then' => 'high'], ['when' => 'cold', 'then' => 'low']],
                        'fallback' => 'medium',
                    ]],
                ],
            ],
        ]))->assertCreated();
    }

    public function test_accepts_a_source_options_arg_supplied_by_a_multi_variable(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // enum_in's `values` (sourceOptions = multi OPTION arg) is a MULTI variable (fields.tags); same
        // boolean → choice bridge. A multi ref is the only type this control accepts.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.category', 'type' => 'enum'],
                'pipeline' => [
                    ['op' => 'enum_in', 'args' => ['values' => [
                        'kind' => 'variable',
                        'ref' => ['source' => 'trigger', 'path' => 'fields.tags', 'type' => 'multi'],
                    ]]],
                    ['op' => 'bool_to_text', 'args' => ['when_true' => 'hot', 'when_false' => 'cold']],
                    ['op' => 'match_to_choice', 'args' => [
                        'rules' => [['when' => 'hot', 'then' => 'high'], ['when' => 'cold', 'then' => 'low']],
                        'fallback' => 'medium',
                    ]],
                ],
            ],
        ]))->assertCreated();
    }

    public function test_rejects_a_structural_arg_variable_referencing_an_unknown_path(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // The loose STRUCTURAL gate still requires a RESOLVABLE ref: fields.ghost is not in the index, so
        // the sourceMap variable is rejected exactly like an unknown value ref — under the arg's ref.path.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.category', 'type' => 'enum'],
                'pipeline' => [['op' => 'enum_to_choice', 'args' => ['mapping' => [
                    'kind' => 'variable',
                    'ref' => ['source' => 'trigger', 'path' => 'fields.ghost', 'type' => 'object'],
                ]]]],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.priority.pipeline.0.args.mapping.ref.path']);
    }

    public function test_rejects_a_structural_arg_variable_referencing_a_non_whitelisted_root(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // Exfil safety at a structural arg: only trigger/steps/globals are references — `env` is not.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.category', 'type' => 'enum'],
                'pipeline' => [['op' => 'enum_to_choice', 'args' => ['mapping' => [
                    'kind' => 'variable',
                    'ref' => ['source' => 'env', 'path' => 'SECRET', 'type' => 'object'],
                ]]]],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.priority.pipeline.0.args.mapping.ref.source']);
    }

    public function test_rejects_an_option_arg_variable_whose_type_is_not_enum_or_text(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        // A single-OPTION arg (choiceFallback) accepts only an enum|text variable. A DATE ref (fields.due)
        // is rejected under the arg's own key — the same per-arg gate a value arg gets.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.headline', 'type' => 'text'],
                'pipeline' => [['op' => 'match_to_choice', 'args' => [
                    'rules' => [['when' => 'x', 'then' => 'high']],
                    'fallback' => ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.due', 'type' => 'date']],
                ]]],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.priority.pipeline.0.args.fallback']);
    }

    public function test_a_structural_arg_variable_persists_verbatim(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);

        $mapping = ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.details', 'type' => 'object']];
        $pipeline = [['op' => 'enum_to_choice', 'args' => ['mapping' => $mapping]]];

        $response = $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.category', 'type' => 'enum'], 'pipeline' => $pipeline],
        ]))->assertCreated();

        // The structural arg-variable round-trips into the stored step config unchanged (no coercion).
        $workflow = \App\Modules\Workflows\Models\Workflow::findOrFail($response->json('data.id'));

        $this->assertSame($mapping, $workflow->steps[0]['config']['priority']['pipeline'][0]['args']['mapping']);
    }

    // ---- OBJECT GLOBAL subfield references (phase-2c) -------------------------
    //
    // A workspace object GLOBAL is self-contained: its interior lives only in its descriptor, which the
    // editor's picker tree expands into pickable `globals.<key>.<sub>` refs. Those paths are now
    // enumerated in the reference index, so such a pick write-validates and type-flows from the
    // SUBFIELD's own type instead of being rejected as an unknown variable. An `array<object>` field
    // (a repeater) keeps its ELEMENTS non-referenceable (per-element access is the deferred R2 loop).

    /** The workspace's `firma` object global: scalars, a nested object, and an array<object> list. */
    private function objectGlobal(User $owner, Workspace $workspace): WorkflowGlobal
    {
        return WorkflowGlobal::factory()->object('firma', [
            WorkflowGlobalFactory::field('miasto', WorkflowVariableType::TEXT->descriptor()),
            WorkflowGlobalFactory::field('pracownicy', WorkflowVariableType::NUMBER->descriptor()),
            WorkflowGlobalFactory::field('zalozona', WorkflowVariableType::DATE->descriptor()),
            WorkflowGlobalFactory::field('geo', WorkflowVariableType::OBJECT->descriptor(fields: [
                WorkflowGlobalFactory::field('lat', WorkflowVariableType::NUMBER->descriptor()),
            ], array: false)),
            WorkflowGlobalFactory::field('kontakty', WorkflowVariableType::OBJECT->descriptor(fields: [
                WorkflowGlobalFactory::field('email', WorkflowVariableType::TEXT->descriptor()),
            ], array: true)),
        ], [
            'miasto' => 'Warszawa',
            'pracownicy' => 12,
            'zalozona' => '2019-04-01',
            'geo' => ['lat' => 52.23],
            'kontakty' => [['email' => 'kontakt@taskio.test']],
        ])->create(['creator_id' => $owner->id, 'workspace_id' => $workspace->id, 'name' => 'Firma']);
    }

    public function test_accepts_a_pipeline_on_an_object_global_subfield(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);
        $this->objectGlobal($owner, $workspace);

        // `globals.firma.miasto` is TEXT: the text op proves the subfield path is a KNOWN variable AND
        // that it type-flows from the SUBFIELD's type (this was a 422 unknown-path before).
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'globals', 'path' => 'globals.firma.miasto', 'type' => 'text'],
                'pipeline' => [
                    ['op' => 'text_uppercase', 'args' => []],
                    ['op' => 'match_to_choice', 'args' => [
                        'rules' => [['when' => 'WARSZAWA', 'then' => 'high']],
                        'fallback' => 'low',
                    ]],
                ],
            ],
        ]))->assertCreated();
    }

    public function test_accepts_a_plain_ref_and_a_nested_object_global_subfield_pipeline(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);
        $this->objectGlobal($owner, $workspace);

        // deadline: a PLAIN (pipeline-less) subfield ref, written in the short `source` + relative
        // `path` shape. priority: a NESTED subfield (`globals.firma.geo.lat`, a NUMBER two levels deep)
        // whose pipeline bridges into the choice mapping — both in one step, so the reference index is
        // actually built and consulted.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'deadline' => [
                'kind' => 'variable',
                'ref' => ['source' => 'globals', 'path' => 'firma.zalozona', 'type' => 'date'],
            ],
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'globals', 'path' => 'globals.firma.geo.lat', 'type' => 'number'],
                'pipeline' => [
                    ['op' => 'num_to_text', 'args' => []],
                    ['op' => 'match_to_choice', 'args' => [
                        'rules' => [['when' => '52.23', 'then' => 'high']],
                        'fallback' => 'low',
                    ]],
                ],
            ],
        ]))->assertCreated();
    }

    public function test_rejects_a_wrongly_typed_object_global_subfield_ref(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);
        $this->objectGlobal($owner, $workspace);

        // The subfield is indexed with its REAL type (number), so a ref DECLARING text is the catalog
        // type-mismatch error — not an unknown-path error: the path itself is now known.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'globals', 'path' => 'globals.firma.pracownicy', 'type' => 'text'],
                'pipeline' => [['op' => 'match_to_choice', 'args' => [
                    'rules' => [['when' => 'x', 'then' => 'high']],
                    'fallback' => 'low',
                ]]],
            ],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.0.config.priority.ref.type'])
            ->assertJsonMissingValidationErrors(['steps.0.config.priority.ref.path']);
    }

    public function test_rejects_an_element_subfield_of_an_array_object_global(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);
        $this->objectGlobal($owner, $workspace);

        // `kontakty` is an array<object> (a repeater): its own path is referenceable but its ELEMENT
        // subfield is not, so the ref stays an unknown variable — the same rule a form repeater gets.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'priority' => [
                'kind' => 'variable',
                'ref' => ['source' => 'globals', 'path' => 'globals.firma.kontakty.email', 'type' => 'text'],
                'pipeline' => [['op' => 'match_to_choice', 'args' => [
                    'rules' => [['when' => 'x', 'then' => 'high']],
                    'fallback' => 'low',
                ]]],
            ],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['steps.0.config.priority.ref.path']);
    }

    public function test_accepts_an_op_argument_variable_from_an_object_global_subfield(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->form($owner, $workspace);
        $this->objectGlobal($owner, $workspace);

        // The arg-variable path reads the SAME reference index: date_add_days' `value` (NUMBER) is
        // supplied by the object global's `pracownicy` subfield.
        $this->postWorkflow($owner, $workspace, $this->payload($form, [
            'deadline' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.due', 'type' => 'date'],
                'pipeline' => [['op' => 'date_add_days', 'args' => ['value' => [
                    'kind' => 'variable',
                    'ref' => ['source' => 'globals', 'path' => 'globals.firma.pracownicy', 'type' => 'number'],
                ]]]],
            ],
        ]))->assertCreated();
    }
}
