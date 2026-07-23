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

    /**
     * A form covering every element→type mapping incl. sections, grids, and a repeater.
     *
     * When a $workspace is given the form is stamped with its id so the workspace-scoped
     * route-model binding (ResolveWorkspace runs before SubstituteBindings) resolves it under
     * that active workspace — the HTTP endpoint tests send `X-Workspace-Id: $workspace->id` and
     * would otherwise 404 at bind. The service-level tests query the service directly (no active
     * workspace) and pass no workspace, mirroring the `form($owner, $workspace)` helper in
     * WorkflowStepValuePipelineValidationTest.
     */
    private function richForm(User $owner, ?Workspace $workspace = null): Form
    {
        return Form::factory()->enabled()->create([
            'creator_id' => $owner->id,
            'workspace_id' => $workspace?->id,
            'content' => [
                ['id' => 'full_name', 'type' => 'short_text', 'config' => ['label' => 'Full name']],
                ['id' => 'age', 'type' => 'number', 'config' => ['label' => 'Age', 'step' => 1]],
                ['id' => 'agree', 'type' => 'checkbox', 'config' => ['label' => 'Agree']],
                ['id' => 'due', 'type' => 'date', 'config' => ['label' => 'Due']],
                ['id' => 'link', 'type' => 'url', 'config' => ['label' => 'Link']],
                ['id' => 'start_time', 'type' => 'time', 'config' => ['label' => 'Start time']],
                ['id' => 'category', 'type' => 'select', 'config' => [
                    'label' => 'Category', 'multiple' => false,
                    // Human labels differ from the wire VALUES (dropped by the JSON schema, kept in config).
                    'options' => [['value' => 'blog', 'label' => 'Blog'], ['value' => 'news', 'label' => 'News']],
                ]],
                ['id' => 'channels', 'type' => 'select', 'config' => [
                    'label' => 'Channels', 'multiple' => true,
                    'options' => [['value' => 'fb', 'label' => 'Facebook'], ['value' => 'ig', 'label' => 'Instagram']],
                ]],
                ['id' => 'todos', 'type' => 'checklist', 'config' => [
                    // No labels here — the descriptor must fall back to labelling each value with itself.
                    'label' => 'Todos', 'options' => [['value' => 'a'], ['value' => 'b']],
                ]],
                // The file input (wire type 'image') carries format:'file' → FILE variable.
                ['id' => 'attachment', 'type' => 'image', 'config' => ['label' => 'Attachment']],
                ['id' => 'details', 'type' => 'section', 'config' => ['name' => 'details', 'children' => [
                    ['id' => 'note', 'type' => 'long_text', 'config' => ['label' => 'Note']],
                    // A multi-select INSIDE a section — its child descriptor must be an array<enum>.
                    ['id' => 'section_tags', 'type' => 'select', 'config' => [
                        'label' => 'Section tags', 'multiple' => true,
                        'options' => [['value' => 'x', 'label' => 'X'], ['value' => 'y', 'label' => 'Y']],
                    ]],
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

    public function test_trigger_system_variables_carry_structured_descriptors(): void
    {
        $vars = app(WorkflowVariableCatalogService::class)
            ->triggerSystemVariables(WorkflowTriggerType::FORM_SUBMITTED);

        $byPath = $this->fieldsByPath($vars);

        // Plain scalar system vars.
        $this->assertSame(['base' => 'text', 'nullable' => false, 'array' => false], $byPath['trigger.submission.id']['descriptor']);
        $this->assertSame(['base' => 'date', 'nullable' => false, 'array' => false], $byPath['trigger.submitted_at']['descriptor']);

        // A nullable system var carries nullable:true into its descriptor too.
        $this->assertSame(['base' => 'text', 'nullable' => true, 'array' => false], $byPath['trigger.task.id']['descriptor']);

        // trigger.source is an enum → enum base + options. System vars have no human labels, so the
        // label falls back to the value.
        $source = $byPath['trigger.source']['descriptor'];
        $this->assertSame('enum', $source['base']);
        $this->assertFalse($source['array']);
        $this->assertSame([
            ['key' => 'manual', 'label' => 'manual'],
            ['key' => 'task', 'label' => 'task'],
        ], $source['options']);
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

        // a file input maps to the FILE variable (format:'file' wins over its string shape).
        $this->assertSame(WorkflowVariableType::FILE->value, $byPath['trigger.fields.attachment']['type']);

        // section-nested field recurses to a dotted path.
        $this->assertSame(WorkflowVariableType::TEXT->value, $byPath['trigger.fields.details.note']['type']);
    }

    public function test_repeater_has_no_flat_leaf_and_no_addressable_child_paths(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $catalog = app(WorkflowVariableCatalogService::class)->formFieldVariables($this->richForm($owner));
        $byPath = $this->fieldsByPath($catalog);

        // The repeater has NO scalar leaf variable of its own, and its child fields are NOT addressable
        // as top-level paths (they live inside the container descriptor's `fields` — a repeater's
        // elements can't resolve without a loop). The repeater is instead emitted as an array<object>
        // CONTAINER entry (asserted below in test_repeater_emits_an_array_of_object_container).
        $items = $byPath['trigger.fields.items'] ?? null;
        $this->assertNotNull($items); // present now, but as a container (base 'object'), not a flat leaf
        $this->assertSame('object', $items['descriptor']['base']);
        $this->assertArrayNotHasKey('trigger.fields.items.item_name', $byPath);
    }

    // ---- Service: structural container variables (phase-2a, additive) ---------

    public function test_section_emits_an_object_container_descriptor(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $catalog = app(WorkflowVariableCatalogService::class)->formFieldVariables($this->richForm($owner));
        $byPath = $this->fieldsByPath($catalog);

        // The flat leaves of the section STILL exist (additive — existing references keep resolving).
        $this->assertArrayHasKey('trigger.fields.details.note', $byPath);
        $this->assertArrayHasKey('trigger.fields.details.section_tags', $byPath);

        // The section ALSO surfaces as an `object` container grouping its children (array:false).
        $section = $byPath['trigger.fields.details'];
        $this->assertSame(WorkflowVariableType::TEXT->value, $section['type']); // flat wire degrades to text
        $this->assertSame('object', $section['descriptor']['base']);
        $this->assertFalse($section['descriptor']['array']);
        $this->assertFalse($section['descriptor']['nullable']);
        $this->assertArrayNotHasKey('field_id', $section); // a container is not a condition source

        // Ordered child descriptors, each {key,label,descriptor}, labels from config.
        $fields = $section['descriptor']['fields'];
        $this->assertSame(['note', 'section_tags'], array_column($fields, 'key'));
        $this->assertSame('Note', $fields[0]['label']);
        $this->assertSame(['base' => 'text', 'nullable' => false, 'array' => false], $fields[0]['descriptor']);
    }

    public function test_section_child_multi_select_is_an_array_of_enum(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $catalog = app(WorkflowVariableCatalogService::class)->formFieldVariables($this->richForm($owner));
        $byPath = $this->fieldsByPath($catalog);

        $fields = collect($byPath['trigger.fields.details']['descriptor']['fields'])->keyBy('key');
        $tags = $fields['section_tags'];

        $this->assertSame('Section tags', $tags['label']);
        // A multi-select child recurses to a full array<enum> descriptor with REAL config labels.
        $this->assertSame('enum', $tags['descriptor']['base']);
        $this->assertTrue($tags['descriptor']['array']);
        $this->assertSame([
            ['key' => 'x', 'label' => 'X'],
            ['key' => 'y', 'label' => 'Y'],
        ], $tags['descriptor']['options']);
    }

    public function test_repeater_emits_an_array_of_object_container_descriptor(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $catalog = app(WorkflowVariableCatalogService::class)->formFieldVariables($this->richForm($owner));
        $byPath = $this->fieldsByPath($catalog);

        // The repeater exclusion is LIFTED: it is emitted as an `array<object>` container.
        $repeater = $byPath['trigger.fields.items'];
        $this->assertSame(WorkflowVariableType::TEXT->value, $repeater['type']); // flat wire degrades to text
        $this->assertSame('object', $repeater['descriptor']['base']);
        $this->assertTrue($repeater['descriptor']['array']); // array<object>
        $this->assertArrayNotHasKey('field_id', $repeater);

        // The element fields live in `fields` (NOT as top-level paths), labelled from config.
        $fields = $repeater['descriptor']['fields'];
        $this->assertSame(['item_name'], array_column($fields, 'key'));
        $this->assertSame('Item name', $fields[0]['label']);
        $this->assertSame(['base' => 'text', 'nullable' => false, 'array' => false], $fields[0]['descriptor']);
    }

    public function test_container_variables_are_not_condition_fields(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // Containers appear in the referenceable variables + carry a text flat type, but are NEVER
        // offered as condition sources (a container is not a comparable scalar).
        $catalog = app(WorkflowVariableCatalogService::class)
            ->forContext(WorkflowTriggerType::FORM_SUBMITTED, $this->richForm($owner));

        $conditionPaths = collect($catalog['fields'])->pluck('path');
        $this->assertFalse($conditionPaths->contains('fields.details'));
        $this->assertFalse($conditionPaths->contains('fields.items'));

        // But a section's scalar CHILD is still conditionable (the leaf is untouched).
        $this->assertTrue($conditionPaths->contains('fields.details.note'));
    }

    public function test_container_paths_are_referenceable_and_degrade_to_text_in_the_reference_index(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // The write-validator reference index (and the runtime type map, built the same way) carries
        // the container paths so a whole-container reference is a KNOWN variable — degraded to text
        // (never `object`), so nothing downstream that dispatches on the type can ever see `object`.
        $index = app(WorkflowVariableCatalogService::class)
            ->referenceIndex(WorkflowTriggerType::FORM_SUBMITTED, $this->richForm($owner), []);

        $this->assertArrayHasKey('trigger.fields.details', $index);
        $this->assertArrayHasKey('trigger.fields.items', $index);
        $this->assertSame(WorkflowVariableType::TEXT, $index['trigger.fields.details']['type']);
        $this->assertSame(WorkflowVariableType::TEXT, $index['trigger.fields.items']['type']);
        $this->assertNull($index['trigger.fields.items']['enumOptions']);
    }

    public function test_reference_index_enumerates_file_subfield_paths_and_excludes_repeater_elements(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // The reference index a pipeline-bearing ref is write-validated against now carries the 5 file
        // SUBFIELD paths (phase-2b.1) so a `<file>.name` / `.size` pipeline ref is a KNOWN variable and
        // type-flows from the subfield's own type — id/name/type/url=text, size=number.
        $index = app(WorkflowVariableCatalogService::class)
            ->referenceIndex(WorkflowTriggerType::FORM_SUBMITTED, $this->richForm($owner), []);

        // The whole-file container entry is unchanged (still `file`).
        $this->assertSame(WorkflowVariableType::FILE, $index['trigger.fields.attachment']['type']);

        // The 5 composite subfields, each a plain scalar with no options.
        $this->assertSame(WorkflowVariableType::TEXT, $index['trigger.fields.attachment.id']['type']);
        $this->assertSame(WorkflowVariableType::TEXT, $index['trigger.fields.attachment.name']['type']);
        $this->assertSame(WorkflowVariableType::TEXT, $index['trigger.fields.attachment.type']['type']);
        $this->assertSame(WorkflowVariableType::NUMBER, $index['trigger.fields.attachment.size']['type']);
        $this->assertSame(WorkflowVariableType::TEXT, $index['trigger.fields.attachment.url']['type']);
        $this->assertNull($index['trigger.fields.attachment.size']['enumOptions']);

        // The subfield SET is exactly the file descriptor's — no stray keys leak in.
        $subfields = array_keys(array_filter(
            $index,
            fn (string $path): bool => str_starts_with($path, 'trigger.fields.attachment.'),
            ARRAY_FILTER_USE_KEY,
        ));
        sort($subfields);
        $this->assertSame([
            'trigger.fields.attachment.id',
            'trigger.fields.attachment.name',
            'trigger.fields.attachment.size',
            'trigger.fields.attachment.type',
            'trigger.fields.attachment.url',
        ], $subfields);

        // A SECTION leaf stays a flat top-level path (unchanged). A REPEATER element is NOT enumerated
        // (per-element access is the deferred R2 loop) — so a pipeline ref to it stays unknown → rejected.
        $this->assertArrayHasKey('trigger.fields.details.note', $index);
        $this->assertArrayNotHasKey('trigger.fields.items.item_name', $index);
    }

    public function test_a_sections_flat_leaves_are_not_re_indexed_from_its_object_descriptor(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // A SECTION is an object CONTAINER whose descriptor declares its children — but those children
        // are ALREADY emitted as flat `section.leaf` variables by the leaf pass, with their option
        // lists. The object-subfield indexing (phase-2c) must therefore never overwrite them: the
        // richer flat entry wins, so nothing about a section reference changes.
        $index = app(WorkflowVariableCatalogService::class)
            ->referenceIndex(WorkflowTriggerType::FORM_SUBMITTED, $this->richForm($owner), []);

        $this->assertSame(WorkflowVariableType::TEXT, $index['trigger.fields.details.note']['type']);

        $tags = $index['trigger.fields.details.section_tags'];
        $this->assertSame(WorkflowVariableType::MULTI, $tags['type']);
        // The OPTION list survives — the proof the descriptor walk did not re-emit this leaf (a
        // descriptor-derived subfield entry carries no options).
        $this->assertSame(['x', 'y'], $tags['enumOptions']);

        // The container itself is still the degraded text entry, and the repeater's ELEMENT is still
        // absent (an `array:true` object descriptor is never recursed).
        $this->assertSame(WorkflowVariableType::TEXT, $index['trigger.fields.details']['type']);
        $this->assertArrayNotHasKey('trigger.fields.items.item_name', $index);
    }

    // ---- Service: structured type descriptors (phase-1a, additive) -----------

    public function test_field_variables_carry_a_structured_type_descriptor(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $catalog = app(WorkflowVariableCatalogService::class)->formFieldVariables($this->richForm($owner));
        $byPath = $this->fieldsByPath($catalog);

        // Scalars → { base:<self>, nullable:false, array:false }.
        $this->assertSame(['base' => 'text', 'nullable' => false, 'array' => false], $byPath['trigger.fields.full_name']['descriptor']);
        $this->assertSame(['base' => 'number', 'nullable' => false, 'array' => false], $byPath['trigger.fields.age']['descriptor']);
        $this->assertSame(['base' => 'boolean', 'nullable' => false, 'array' => false], $byPath['trigger.fields.agree']['descriptor']);
        $this->assertSame(['base' => 'date', 'nullable' => false, 'array' => false], $byPath['trigger.fields.due']['descriptor']);
        $this->assertSame(['base' => 'text', 'nullable' => false, 'array' => false], $byPath['trigger.fields.link']['descriptor']);
        // A file is a COMPOSITE (phase-2b): base 'file', single-file (array:false), carrying its
        // subfields — the subfield set is asserted in test_file_variable_descriptor_exposes_composite_subfields.
        $attachment = $byPath['trigger.fields.attachment']['descriptor'];
        $this->assertSame('file', $attachment['base']);
        $this->assertFalse($attachment['nullable']);
        $this->assertFalse($attachment['array']);

        // A TIME field gets its OWN base in the descriptor (the flat `type` still degrades to text —
        // asserted in the back-compat test below).
        $this->assertSame(['base' => 'time', 'nullable' => false, 'array' => false], $byPath['trigger.fields.start_time']['descriptor']);
    }

    public function test_file_variable_descriptor_exposes_composite_subfields(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $catalog = app(WorkflowVariableCatalogService::class)->formFieldVariables($this->richForm($owner));
        $byPath = $this->fieldsByPath($catalog);

        $attachment = $byPath['trigger.fields.attachment'];

        // The wire `type` stays `file` — every existing file semantic (text→name, structural→id,
        // copy-on-attach, the snapshot) is preserved; only the descriptor is enriched (phase-2b).
        $this->assertSame(WorkflowVariableType::FILE->value, $attachment['type']);
        $this->assertSame('file', $attachment['descriptor']['base']);

        // The composite subfields, in order, each a plain scalar (id/name/type/url = text, size = number)
        // so a `<file>.<subfield>` reference needs no new type.
        $fields = collect($attachment['descriptor']['fields'])->keyBy('key');
        $this->assertSame(['id', 'name', 'type', 'size', 'url'], array_keys($fields->all()));
        $this->assertSame(['base' => 'text', 'nullable' => false, 'array' => false], $fields['id']['descriptor']);
        $this->assertSame(['base' => 'text', 'nullable' => false, 'array' => false], $fields['name']['descriptor']);
        $this->assertSame(['base' => 'text', 'nullable' => false, 'array' => false], $fields['type']['descriptor']);
        $this->assertSame(['base' => 'number', 'nullable' => false, 'array' => false], $fields['size']['descriptor']);
        $this->assertSame(['base' => 'text', 'nullable' => false, 'array' => false], $fields['url']['descriptor']);
    }

    public function test_file_variable_stays_a_condition_source_with_filled_empty_operators(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // Modelling a file as a composite must NOT reclassify it as a container: unlike an `object`
        // (dropped from condition fields), a file keeps its file/empty condition operators unchanged.
        $catalog = app(WorkflowVariableCatalogService::class)
            ->forContext(WorkflowTriggerType::FORM_SUBMITTED, $this->richForm($owner));

        $attachment = collect($catalog['fields'])->firstWhere('field_id', 'attachment');

        $this->assertNotNull($attachment);
        $this->assertSame(WorkflowVariableType::FILE->value, $attachment['type']);
        $this->assertSame(['filled', 'empty'], $attachment['operators']);
    }

    public function test_single_select_descriptor_carries_enum_options_with_real_labels(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $catalog = app(WorkflowVariableCatalogService::class)->formFieldVariables($this->richForm($owner));
        $byPath = $this->fieldsByPath($catalog);

        $descriptor = $byPath['trigger.fields.category']['descriptor'];

        $this->assertSame('enum', $descriptor['base']);
        $this->assertFalse($descriptor['array']);
        // Options carry {key,label} with the REAL labels from the element config (NOT the schema enum).
        $this->assertSame([
            ['key' => 'blog', 'label' => 'Blog'],
            ['key' => 'news', 'label' => 'News'],
        ], $descriptor['options']);
        // The label is genuinely NOT the value — proving the label came from the config, not the schema.
        $this->assertNotSame($descriptor['options'][0]['key'], $descriptor['options'][0]['label']);
    }

    public function test_multi_select_and_checklist_descriptors_are_arrays_of_enum(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $catalog = app(WorkflowVariableCatalogService::class)->formFieldVariables($this->richForm($owner));
        $byPath = $this->fieldsByPath($catalog);

        // SELECT-multiple → { base:'enum', array:true, options:[{key,label}] } with real labels.
        $channels = $byPath['trigger.fields.channels']['descriptor'];
        $this->assertSame('enum', $channels['base']);
        $this->assertTrue($channels['array']);
        $this->assertSame([
            ['key' => 'fb', 'label' => 'Facebook'],
            ['key' => 'ig', 'label' => 'Instagram'],
        ], $channels['options']);

        // CHECKLIST → same shape; with no config labels the label falls back to the value.
        $todos = $byPath['trigger.fields.todos']['descriptor'];
        $this->assertSame('enum', $todos['base']);
        $this->assertTrue($todos['array']);
        $this->assertSame([
            ['key' => 'a', 'label' => 'a'],
            ['key' => 'b', 'label' => 'b'],
        ], $todos['options']);
    }

    public function test_descriptor_is_additive_and_leaves_the_legacy_type_and_enum_options_untouched(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $catalog = app(WorkflowVariableCatalogService::class)->formFieldVariables($this->richForm($owner));
        $byPath = $this->fieldsByPath($catalog);

        // BACK-COMPAT: a TIME field's flat `type` still degrades to text (runtime + FE untouched);
        // only its descriptor knows it is `time`.
        $this->assertSame(WorkflowVariableType::TEXT->value, $byPath['trigger.fields.start_time']['type']);
        $this->assertSame('time', $byPath['trigger.fields.start_time']['descriptor']['base']);

        // BACK-COMPAT: the enum/multi flat `type` + `enumOptions` (value-only) keys are unchanged,
        // even though the config now also carries human labels (which flow only into the descriptor).
        $this->assertSame(WorkflowVariableType::ENUM->value, $byPath['trigger.fields.category']['type']);
        $this->assertSame(['blog', 'news'], $byPath['trigger.fields.category']['enumOptions']);
        $this->assertSame(WorkflowVariableType::MULTI->value, $byPath['trigger.fields.channels']['type']);
        $this->assertSame(['fb', 'ig'], $byPath['trigger.fields.channels']['enumOptions']);
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
        // Step-output vars carry a scalar descriptor alongside the flat type (additive).
        $this->assertSame(['base' => 'text', 'nullable' => false, 'array' => false], $byPath['steps.create_task.task_id']['descriptor']);

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
        $form = $this->richForm($owner, $workspace);

        $response = $this->actingAs($owner)->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson("/api/forms/{$form->id}/workflow-catalog")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'variables' => [
                        // The additive `descriptor` survives serialization (only field_id is stripped).
                        '*' => ['source', 'path', 'name', 'type', 'descriptor' => ['base', 'nullable', 'array']],
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
        $form = $this->richForm($owner, $workspace);

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
        $form = $this->richForm($owner, $workspace);

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

        // All 77 label-less operation descriptors are present (the FE resolves labels via i18n).
        $operations = collect($response->json('data.operations'));
        $this->assertCount(77, $operations);

        // The file ops: two boolean terminals (so a file field is usable in a condition at all)
        // plus two converters that hand the value to the text/number vocabulary. All nullary.
        $fileName = $operations->firstWhere('id', 'file_name');
        $this->assertSame('file', $fileName['input']);
        $this->assertSame('text', $fileName['output']);
        $this->assertSame([], $fileName['args']);

        $fileIsEmpty = $operations->firstWhere('id', 'file_is_empty');
        $this->assertSame('file', $fileIsEmpty['input']);
        $this->assertSame('boolean', $fileIsEmpty['output']);

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

        // Phase-1b append-only ops: coalesce (fallback literal), the nullary presence predicates, and
        // date_format (safe-token pattern literal). NOMINAL input/output the FE/validator advertise.
        $coalesce = $operations->firstWhere('id', 'coalesce');
        $this->assertSame([['id' => 'fallback', 'type' => 'text']], $coalesce['args']);

        $isPresent = $operations->firstWhere('id', 'is_present');
        $this->assertSame('boolean', $isPresent['output']);
        $this->assertSame([], $isPresent['args']);

        $this->assertSame([], $operations->firstWhere('id', 'assert_present')['args']);

        $dateFormat = $operations->firstWhere('id', 'date_format');
        $this->assertSame('date', $dateFormat['input']);
        $this->assertSame('text', $dateFormat['output']);
        $this->assertSame([['id' => 'pattern', 'type' => 'text']], $dateFormat['args']);
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
        // FormPolicy::view gates only "is authenticated"; the workspace gate is upstream. Since
        // the security reorder, ResolveWorkspace runs BEFORE SubstituteBindings, so the {form}
        // binding is now workspace-scoped: a member of the active workspace reaches a SAME-
        // workspace form's catalog (200), while a foreign form 404s at bind (see
        // CrossWorkspaceBindingTest) and a non-member 403s at ResolveWorkspace (covered above).
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->richForm($owner, $workspace);

        $this->actingAs($owner)->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson("/api/forms/{$form->id}/workflow-catalog")
            ->assertOk();
    }

    // ---- Form-independent catalog: service (forContext / variableTypes) -------

    public function test_for_context_without_a_form_is_form_independent(): void
    {
        // A form-LESS schedule catalog carries the structural sources (trigger-system vars +
        // step-output templates + operations + personas + the type list) but NO field variables.
        $catalog = app(WorkflowVariableCatalogService::class)
            ->forContext(WorkflowTriggerType::SCHEDULE);

        $byPath = $this->fieldsByPath($catalog['variables']);

        // Trigger-system var for schedule + the step-output template vars are present.
        $this->assertArrayHasKey('trigger.scheduled_at', $byPath);
        $this->assertArrayHasKey('steps.create_task.task_id', $byPath);

        // No form → no field variables and no condition field descriptors.
        foreach (array_keys($byPath) as $path) {
            $this->assertStringStartsNotWith('trigger.fields.', $path);
        }
        $this->assertSame([], $catalog['fields']);

        // The structural catalogs are still present (form-independent).
        $this->assertNotEmpty($catalog['operations']);
        $this->assertNotEmpty($catalog['ai_personas']);
        $this->assertNotEmpty($catalog['types']);
    }

    public function test_for_context_layers_field_variables_when_a_form_is_present(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // With a form, forContext produces the SAME thing forForm does (its FORM_SUBMITTED
        // specialization) — field vars + their condition field descriptors are layered in.
        $catalog = app(WorkflowVariableCatalogService::class)
            ->forContext(WorkflowTriggerType::FORM_SUBMITTED, $this->richForm($owner));

        $byPath = $this->fieldsByPath($catalog['variables']);

        $this->assertArrayHasKey('trigger.fields.full_name', $byPath);
        $this->assertNotEmpty($catalog['fields']);
    }

    public function test_variable_types_list_carries_every_type_with_primitive_and_operators(): void
    {
        $types = app(WorkflowVariableCatalogService::class)->variableTypes();

        // Every WorkflowVariableType is described, each as {id, primitive, operators}.
        $this->assertSame(WorkflowVariableType::ids(), array_column($types, 'id'));

        $byId = [];
        foreach ($types as $type) {
            $this->assertSame(['id', 'primitive', 'operators'], array_keys($type));
            $byId[$type['id']] = $type;
        }

        // The editor primitive degrade rule (number|boolean keep, everything else → text).
        $this->assertSame('number', $byId['number']['primitive']);
        $this->assertSame('text', $byId['date']['primitive']);
        // The operator set mirrors WorkflowVariableType::operators().
        $this->assertSame(WorkflowVariableType::ENUM->operators(), $byId['enum']['operators']);

        // TIME (appended in phase-1a) joins the vocabulary with a text editor primitive and — this
        // slice — NO operators: it is representable (in the descriptor) but not yet conditionable at
        // run time (a stored time condition would hit the evaluator's exhaustive match).
        $this->assertArrayHasKey('time', $byId);
        $this->assertSame('text', $byId['time']['primitive']);
        $this->assertSame([], $byId['time']['operators']);

        // OBJECT (appended in phase-2a) is the same descriptor-only tripwire: a text editor primitive
        // and NO operators (a structural container is never a condition source).
        $this->assertArrayHasKey('object', $byId);
        $this->assertSame('text', $byId['object']['primitive']);
        $this->assertSame([], $byId['object']['operators']);
    }

    // ---- Form-independent catalog: endpoint (GET /workflows/catalog) ----------

    public function test_formless_catalog_endpoint_returns_structural_metadata(): void
    {
        $owner = User::factory()->create();

        // No form_id → structural metadata only. A workspace header is not required (no tenant
        // rows), mirroring how the global runs feed authorizes (viewAny = authenticated member).
        $response = $this->actingAs($owner)
            ->getJson('/api/workflows/catalog?trigger_type=schedule')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'variables' => ['*' => ['source', 'path', 'name', 'type']],
                    'fields',
                    'operations' => ['*' => ['id', 'input', 'output', 'args']],
                    'ai_personas' => ['*' => ['id']],
                    'types' => ['*' => ['id', 'primitive', 'operators']],
                ],
            ]);

        $paths = collect($response->json('data.variables'))->pluck('path');

        // The schedule chip + the step-output templates are present; NO field variables.
        $this->assertTrue($paths->contains('trigger.scheduled_at'));
        $this->assertTrue($paths->contains('steps.create_task.task_id'));
        $this->assertFalse($paths->contains(fn (string $p) => str_starts_with($p, 'trigger.fields.')));
        $this->assertSame([], $response->json('data.fields'));

        // The public variable shape must NOT leak the internal field_id key.
        foreach ($response->json('data.variables') as $variable) {
            $this->assertArrayNotHasKey('field_id', $variable);
        }
    }

    public function test_formless_catalog_requires_a_trigger_type_without_a_form(): void
    {
        $owner = User::factory()->create();

        // A form-less call must name its trigger (required_without:form_id).
        $this->actingAs($owner)
            ->getJson('/api/workflows/catalog')
            ->assertStatus(422)
            ->assertJsonValidationErrors('trigger_type');
    }

    public function test_formless_catalog_endpoint_is_unauthenticated_for_a_guest(): void
    {
        $this->getJson('/api/workflows/catalog?trigger_type=schedule')->assertUnauthorized();
    }

    public function test_catalog_endpoint_with_form_id_layers_in_field_variables(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $form = $this->richForm($owner, $workspace);

        // form_id present (trigger_type omitted — a form implies FORM_SUBMITTED): the field vars
        // + condition field descriptors are layered on, authorized by FormPolicy::view under the
        // active workspace (the form resolves via WorkspaceScope).
        $response = $this->actingAs($owner)->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson("/api/workflows/catalog?form_id={$form->id}")
            ->assertOk();

        $paths = collect($response->json('data.variables'))->pluck('path');
        $this->assertTrue($paths->contains('trigger.fields.full_name'));
        $this->assertNotEmpty($response->json('data.fields'));
    }

    public function test_catalog_endpoint_with_a_foreign_form_id_is_not_found(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $foreignWorkspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        // A form in ANOTHER workspace than the active one does not resolve under WorkspaceScope,
        // so it 404s — mirroring the {form} route-model binding on the form-bound catalog route.
        $foreignForm = $this->richForm($owner, $foreignWorkspace);

        $this->actingAs($owner)->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson("/api/workflows/catalog?form_id={$foreignForm->id}")
            ->assertNotFound();
    }
}
