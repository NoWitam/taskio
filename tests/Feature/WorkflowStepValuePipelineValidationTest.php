<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Workspaces\Models\Workspace;
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
}
