<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Changelog\Managers\ChangelogManager;
use App\Modules\Forms\Models\Form;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Enums\WorkflowVariableType;
use App\Modules\Workflows\Services\WorkflowVariableCatalogService;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The typed variable catalog: the service that builds trigger system vars + per-form field vars
 * (from a real form schema) + step-output vars and the condition field descriptors, and the
 * GET /api/forms/{form}/workflow-catalog endpoint that exposes them. This is the contract the
 * workflow editor (B6/B7) and AI-assist (B5) consume.
 */
class WorkflowVariableCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    /** A form covering every element→type mapping incl. sections, grids, and a repeater. */
    private function richForm(User $owner): Form
    {
        return Form::factory()->enabled()->create([
            'creator_id' => $owner->id,
            'content' => [
                ['id' => 'full_name', 'type' => 'short_text', 'config' => ['label' => 'Full name']],
                ['id' => 'age', 'type' => 'number', 'config' => ['label' => 'Age', 'step' => 1]],
                ['id' => 'agree', 'type' => 'checkbox', 'config' => ['label' => 'Agree']],
                ['id' => 'due', 'type' => 'date', 'config' => ['label' => 'Due']],
                ['id' => 'link', 'type' => 'url', 'config' => ['label' => 'Link']],
                ['id' => 'start_time', 'type' => 'time', 'config' => ['label' => 'Start time']],
                ['id' => 'category', 'type' => 'select', 'config' => [
                    'label' => 'Category', 'multiple' => false,
                    'options' => [['value' => 'blog'], ['value' => 'news']],
                ]],
                ['id' => 'channels', 'type' => 'select', 'config' => [
                    'label' => 'Channels', 'multiple' => true,
                    'options' => [['value' => 'fb'], ['value' => 'ig']],
                ]],
                ['id' => 'todos', 'type' => 'checklist', 'config' => [
                    'label' => 'Todos', 'options' => [['value' => 'a'], ['value' => 'b']],
                ]],
                ['id' => 'details', 'type' => 'section', 'config' => ['name' => 'details', 'children' => [
                    ['id' => 'note', 'type' => 'long_text', 'config' => ['label' => 'Note']],
                ]]],
                ['id' => 'items', 'type' => 'repeater', 'config' => ['name' => 'items', 'children' => [
                    ['id' => 'item_name', 'type' => 'short_text', 'config' => ['label' => 'Item name']],
                ]]],
            ],
        ]);
    }

    /**
     * Create a form and commit any buffered changelog now (as $owner) so a LATER unauthenticated
     * request doesn't flush a buffered `created` entry with a null causer — a test-infra quirk of
     * the changelog buffer, unrelated to the endpoint under test.
     */
    private function formWithFlushedChangelog(User $owner): Form
    {
        $form = Form::factory()->create(['creator_id' => $owner->id]);

        Auth::setUser($owner);
        app(ChangelogManager::class)->flush();
        Auth::forgetUser();

        return $form;
    }

    /** Index the catalog's field variables by path for assertions. */
    private function fieldsByPath(array $variables): array
    {
        $out = [];
        foreach ($variables as $v) {
            $out[$v['path']] = $v;
        }

        return $out;
    }

    // ---- Service: system variables -------------------------------------------

    public function test_schedule_system_variables(): void
    {
        $vars = app(WorkflowVariableCatalogService::class)
            ->triggerSystemVariables(WorkflowTriggerType::SCHEDULE);

        $this->assertCount(1, $vars);
        $this->assertSame('trigger.scheduled_at', $vars[0]['path']);
        $this->assertSame(WorkflowVariableType::DATE->value, $vars[0]['type']);
    }

    public function test_form_submitted_system_variables_include_nullable_task_id(): void
    {
        $vars = app(WorkflowVariableCatalogService::class)
            ->triggerSystemVariables(WorkflowTriggerType::FORM_SUBMITTED);

        $byPath = $this->fieldsByPath($vars);

        $this->assertArrayHasKey('trigger.submission.id', $byPath);
        $this->assertArrayHasKey('trigger.form.id', $byPath);
        $this->assertArrayHasKey('trigger.form.name', $byPath);
        $this->assertArrayHasKey('trigger.submitted_at', $byPath);
        $this->assertSame(WorkflowVariableType::DATE->value, $byPath['trigger.submitted_at']['type']);

        // source is an enum with the two known values.
        $this->assertSame(WorkflowVariableType::ENUM->value, $byPath['trigger.source']['type']);
        $this->assertSame(['manual', 'task'], $byPath['trigger.source']['enumOptions']);

        // task.id is only present for a task-attached submission → nullable flag.
        $this->assertTrue($byPath['trigger.task.id']['nullable']);
    }

    // ---- Service: per-form field variables -----------------------------------

    public function test_field_variables_are_typed_from_the_form_schema(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $catalog = app(WorkflowVariableCatalogService::class)->formFieldVariables($this->richForm($owner));
        $byPath = $this->fieldsByPath($catalog);

        $this->assertSame(WorkflowVariableType::TEXT->value, $byPath['trigger.fields.full_name']['type']);
        $this->assertSame(WorkflowVariableType::NUMBER->value, $byPath['trigger.fields.age']['type']);
        $this->assertSame(WorkflowVariableType::BOOLEAN->value, $byPath['trigger.fields.agree']['type']);
        $this->assertSame(WorkflowVariableType::DATE->value, $byPath['trigger.fields.due']['type']);
        // url + time degrade to text defensively.
        $this->assertSame(WorkflowVariableType::TEXT->value, $byPath['trigger.fields.link']['type']);
        $this->assertSame(WorkflowVariableType::TEXT->value, $byPath['trigger.fields.start_time']['type']);

        // single-select → enum with options; multi-select + checklist → multi with options.
        $this->assertSame(WorkflowVariableType::ENUM->value, $byPath['trigger.fields.category']['type']);
        $this->assertSame(['blog', 'news'], $byPath['trigger.fields.category']['enumOptions']);
        $this->assertSame(WorkflowVariableType::MULTI->value, $byPath['trigger.fields.channels']['type']);
        $this->assertSame(['fb', 'ig'], $byPath['trigger.fields.channels']['enumOptions']);
        $this->assertSame(WorkflowVariableType::MULTI->value, $byPath['trigger.fields.todos']['type']);

        // section-nested field recurses to a dotted path.
        $this->assertSame(WorkflowVariableType::TEXT->value, $byPath['trigger.fields.details.note']['type']);
    }

    public function test_repeater_fields_are_excluded_from_the_catalog(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $catalog = app(WorkflowVariableCatalogService::class)->formFieldVariables($this->richForm($owner));
        $byPath = $this->fieldsByPath($catalog);

        // The repeater itself and any child path it would produce are absent (arrays-of-objects
        // can't resolve to a comparable variable).
        $this->assertArrayNotHasKey('trigger.fields.items', $byPath);
        $this->assertArrayNotHasKey('trigger.fields.items.item_name', $byPath);
    }

    // ---- Service: step outputs -----------------------------------------------

    public function test_step_output_variables(): void
    {
        $vars = app(WorkflowVariableCatalogService::class)->stepOutputVariables();
        $byPath = $this->fieldsByPath($vars);

        $this->assertArrayHasKey('steps.create_task.task_id', $byPath);
        $this->assertArrayHasKey('steps.create_task.title', $byPath);
        $this->assertSame('steps', $byPath['steps.create_task.task_id']['source']);
        $this->assertSame(WorkflowVariableType::TEXT->value, $byPath['steps.create_task.task_id']['type']);

        // The catalog picks up a new step type's outputs automatically from its static
        // descriptors — create_form_report's report_id/report_name are referenceable with no
        // catalog change (the B3 seam works for B4's step).
        $this->assertArrayHasKey('steps.create_form_report.report_id', $byPath);
        $this->assertArrayHasKey('steps.create_form_report.report_name', $byPath);
        $this->assertSame(WorkflowVariableType::TEXT->value, $byPath['steps.create_form_report.report_id']['type']);
    }

    // ---- Service: ai personas (SB2) ------------------------------------------

    public function test_for_form_includes_the_label_less_ai_persona_catalog(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $catalog = app(WorkflowVariableCatalogService::class)->forForm($this->richForm($owner));

        $this->assertArrayHasKey('ai_personas', $catalog);
        $ids = array_column($catalog['ai_personas'], 'id');
        $this->assertSame(['neutral', 'friendly', 'formal', 'concise'], $ids);

        // Descriptors are label-less (the FE localizes) — only an `id` per persona.
        foreach ($catalog['ai_personas'] as $persona) {
            $this->assertSame(['id'], array_keys($persona));
        }
    }

    // ---- Endpoint ------------------------------------------------------------

    public function test_guest_is_unauthenticated(): void
    {
        $owner = User::factory()->create();
        $form = $this->formWithFlushedChangelog($owner);

        $this->getJson("/api/forms/{$form->id}/workflow-catalog")->assertUnauthorized();
    }

    public function test_member_gets_the_catalog_with_the_full_shape(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->richForm($owner);

        $response = $this->actingAs($owner)->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson("/api/forms/{$form->id}/workflow-catalog")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'variables' => [
                        '*' => ['source', 'path', 'name', 'type'],
                    ],
                    'fields' => [
                        '*' => ['path', 'field_id', 'label', 'type', 'operators'],
                    ],
                ],
            ]);

        // A field descriptor carries fields.<id> paths + the per-type operator set.
        $fields = collect($response->json('data.fields'))->keyBy('field_id');
        $this->assertSame('fields.category', $fields['category']['path']);
        $this->assertSame(['is', 'is_not', 'in'], $fields['category']['operators']);
        $this->assertSame(['equals', 'not_equals', 'contains'], $fields['full_name']['operators']);

        // The public variable shape must NOT leak the internal field_id key.
        foreach ($response->json('data.variables') as $variable) {
            $this->assertArrayNotHasKey('field_id', $variable);
        }
    }

    public function test_endpoint_exposes_the_ai_persona_catalog(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->richForm($owner);

        $response = $this->actingAs($owner)->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson("/api/forms/{$form->id}/workflow-catalog")
            ->assertOk()
            ->assertJsonStructure(['data' => ['ai_personas' => ['*' => ['id']]]]);

        $this->assertContains('neutral', collect($response->json('data.ai_personas'))->pluck('id')->all());
    }

    public function test_catalog_exposes_the_full_operation_catalog(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->richForm($owner);

        $response = $this->actingAs($owner)->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson("/api/forms/{$form->id}/workflow-catalog")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'operations' => [
                        '*' => ['id', 'input', 'output', 'args'],
                    ],
                ],
            ]);

        // All 68 label-less operation descriptors are present (the FE resolves labels via i18n).
        $operations = collect($response->json('data.operations'));
        $this->assertCount(68, $operations);

        // Spot-check a sourceMap op: enum_to_date maps each enum option to a date.
        $enumToDate = $operations->firstWhere('id', 'enum_to_date');
        $this->assertSame('enum', $enumToDate['input']);
        $this->assertSame('date', $enumToDate['output']);
        $this->assertSame([['id' => 'mapping', 'type' => 'sourceMap', 'mapType' => 'date']], $enumToDate['args']);

        // Spot-check the choice terminals. enum_to_choice is a sourceMap with an ENUM mapType (the target
        // option set is injected per-field, so it is NOT in the static descriptor).
        $enumToChoice = $operations->firstWhere('id', 'enum_to_choice');
        $this->assertSame('enum', $enumToChoice['input']);
        $this->assertSame('enum', $enumToChoice['output']);
        $this->assertSame([['id' => 'mapping', 'type' => 'sourceMap', 'mapType' => 'enum']], $enumToChoice['args']);

        // match_to_choice carries a choiceRules list + a choiceFallback (no mapType — target-driven).
        $matchToChoice = $operations->firstWhere('id', 'match_to_choice');
        $this->assertSame('text', $matchToChoice['input']);
        $this->assertSame('enum', $matchToChoice['output']);
        $this->assertSame([
            ['id' => 'rules', 'type' => 'choiceRules'],
            ['id' => 'fallback', 'type' => 'choiceFallback'],
        ], $matchToChoice['args']);

        // Spot-check a nullary predicate carries no args, and a literal-arg op carries its descriptor.
        $this->assertSame([], $operations->firstWhere('id', 'text_is_empty')['args']);
        $this->assertSame(
            [['id' => 'value', 'type' => 'number']],
            $operations->firstWhere('id', 'num_gt')['args'],
        );
    }

    public function test_non_member_of_the_active_workspace_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $foreignWorkspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $form = $this->formWithFlushedChangelog($owner);

        $outsider = User::factory()->create();

        // The ResolveWorkspace middleware refuses a non-member of the active workspace.
        $this->actingAs($outsider)->withHeader('X-Workspace-Id', $foreignWorkspace->id)
            ->getJson("/api/forms/{$form->id}/workflow-catalog")
            ->assertForbidden();
    }

    public function test_authorization_is_gated_by_form_policy_view(): void
    {
        // The endpoint authorizes via FormPolicy::view (mirroring the Forms module endpoints).
        // FormPolicy::view currently gates only "is authenticated", and the {form} binding is
        // resolved BEFORE ResolveWorkspace sets the tenant (SubstituteBindings runs first), so
        // the binding is NOT workspace-scoped — a documented, pre-existing app behavior this
        // batch does not change. An authenticated member of the active workspace therefore
        // reaches the catalog; the real tenant gate for the REQUEST is ResolveWorkspace, which
        // 403s a non-member (covered above).
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->richForm($owner);

        $this->actingAs($owner)->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson("/api/forms/{$form->id}/workflow-catalog")
            ->assertOk();
    }
}
