<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Variables\Enums\VariableType as WorkflowVariableType;
use App\Modules\Variables\Models\Constant;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Services\WorkflowConditionEngine;
use App\Modules\Workflows\Services\WorkflowVariableCatalogService;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Database\Factories\ConstantFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The variable CATALOG's globals source: the workspace's user-created LITERAL constants surface as
 * `globals.<key>` catalog variables (form-independent), in the reference index (write validation),
 * and in the runtime type map (pipeline typing). Pins the shape (source/path/descriptor/flat type/
 * enumOptions) and the workspace scoping. The persistence renamed to Constant/consts; the wire stays
 * `globals.*`.
 */
class ConstantCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    private function catalog(): WorkflowVariableCatalogService
    {
        return app(WorkflowVariableCatalogService::class);
    }

    /** Find a catalog variable by its full path, or null. */
    private function variableByPath(array $variables, string $path): ?array
    {
        foreach ($variables as $variable) {
            if (($variable['path'] ?? null) === $path) {
                return $variable;
            }
        }

        return null;
    }

    public function test_catalog_emits_globals_as_globals_source_variables_with_the_right_types(): void
    {
        $user = User::factory()->create();
        Constant::factory()->text('nazwa_marki', 'Taskio')->create(['creator_id' => $user->id, 'name' => 'Nazwa marki']);
        Constant::factory()->number('budzet', 5000)->create(['creator_id' => $user->id]);
        Constant::factory()->enum('kategoria', [['key' => 'blog', 'label' => 'Blog'], ['key' => 'news', 'label' => 'News']], 'blog')->create(['creator_id' => $user->id]);
        Constant::factory()->textList('hashtagi', ['#ai', '#automatyzacja'])->create(['creator_id' => $user->id]);

        $variables = $this->catalog()->forContext(WorkflowTriggerType::FORM_SUBMITTED)['variables'];

        $text = $this->variableByPath($variables, 'globals.nazwa_marki');
        $this->assertNotNull($text);
        $this->assertSame('globals', $text['source']);
        $this->assertSame('text', $text['type']);
        $this->assertSame('text', $text['descriptor']['base']);
        $this->assertSame('Nazwa marki', $text['name']);

        $this->assertSame('number', $this->variableByPath($variables, 'globals.budzet')['type']);

        $enum = $this->variableByPath($variables, 'globals.kategoria');
        $this->assertSame('enum', $enum['type']);
        $this->assertSame(['blog', 'news'], $enum['enumOptions']);

        // An array<text> constant rides MULTI on the flat wire, but its descriptor keeps the true
        // element base (text, array:true) and it carries NO enumOptions (it is not enum-based).
        $list = $this->variableByPath($variables, 'globals.hashtagi');
        $this->assertSame('multi', $list['type']);
        $this->assertSame('text', $list['descriptor']['base']);
        $this->assertTrue($list['descriptor']['array']);
        $this->assertArrayNotHasKey('enumOptions', $list);
    }

    public function test_globals_are_form_independent_across_triggers(): void
    {
        $user = User::factory()->create();
        Constant::factory()->text('nazwa_marki', 'Taskio')->create(['creator_id' => $user->id]);

        // Present for a schedule trigger with no form at all.
        $scheduleVars = $this->catalog()->forContext(WorkflowTriggerType::SCHEDULE)['variables'];
        $this->assertNotNull($this->variableByPath($scheduleVars, 'globals.nazwa_marki'));

        // And for a null trigger (form-less catalog).
        $bareVars = $this->catalog()->forContext(null)['variables'];
        $this->assertNotNull($this->variableByPath($bareVars, 'globals.nazwa_marki'));
    }

    public function test_reference_index_and_runtime_type_map_include_globals(): void
    {
        $user = User::factory()->create();
        Constant::factory()->number('budzet', 5000)->create(['creator_id' => $user->id]);

        $index = $this->catalog()->referenceIndex(WorkflowTriggerType::SCHEDULE, null, []);
        $this->assertArrayHasKey('globals.budzet', $index);
        $this->assertSame('number', $index['globals.budzet']['type']->value);

        $workflow = Workflow::factory()->scheduled()->create(['creator_id' => $user->id]);
        $typeMap = $this->catalog()->runtimeTypeMap($workflow);
        $this->assertArrayHasKey('globals.budzet', $typeMap);
        $this->assertSame('number', $typeMap['globals.budzet']->value);
    }

    // ---- OBJECT constants: subfield paths (phase-2c) --------------------------
    //
    // An object constant is SELF-CONTAINED: unlike a form section (whose leaves are also emitted as flat
    // `section.leaf` variables) its interior lives only inside the descriptor. The editor's picker tree
    // expands `descriptor.fields` into pickable `globals.<key>.<sub>` refs, so the write-validation
    // reference index + the runtime type map must know those paths — otherwise a valid pick 422s.

    /**
     * The descriptor `fields` of the `firma` object constant: a scalar of each kind, an enum, an
     * array<text>, a NESTED object (geo) and an array<object> element list (kontakty — a repeater,
     * whose interior stays non-referenceable).
     *
     * @return array<int, array{key: string, label: string, descriptor: array<string, mixed>}>
     */
    private function companyFields(): array
    {
        return [
            ConstantFactory::field('miasto', WorkflowVariableType::TEXT->descriptor()),
            ConstantFactory::field('pracownicy', WorkflowVariableType::NUMBER->descriptor()),
            ConstantFactory::field('zalozona', WorkflowVariableType::DATE->descriptor()),
            ConstantFactory::field('branza', WorkflowVariableType::ENUM->descriptor([
                ['key' => 'it', 'label' => 'IT'],
                ['key' => 'media', 'label' => 'Media'],
            ])),
            ConstantFactory::field('tagi', WorkflowVariableType::TEXT->descriptor(array: true)),
            ConstantFactory::field('geo', WorkflowVariableType::OBJECT->descriptor(fields: [
                ConstantFactory::field('lat', WorkflowVariableType::NUMBER->descriptor()),
                ConstantFactory::field('lng', WorkflowVariableType::NUMBER->descriptor()),
            ], array: false)),
            ConstantFactory::field('kontakty', WorkflowVariableType::OBJECT->descriptor(fields: [
                ConstantFactory::field('email', WorkflowVariableType::TEXT->descriptor()),
            ], array: true)),
        ];
    }

    /** The literal matching companyFields(). @return array<string, mixed> */
    private function companyValue(): array
    {
        return [
            'miasto' => 'Warszawa',
            'pracownicy' => 12,
            'zalozona' => '2019-04-01',
            'branza' => 'it',
            'tagi' => ['#ai'],
            'geo' => ['lat' => 52.23, 'lng' => 21.01],
            'kontakty' => [['email' => 'kontakt@taskio.test']],
        ];
    }

    private function createCompanyConstant(User $user): Constant
    {
        return Constant::factory()
            ->object('firma', $this->companyFields(), $this->companyValue())
            ->create(['creator_id' => $user->id, 'name' => 'Firma']);
    }

    public function test_object_constant_subfields_are_known_references_in_the_index_and_type_map(): void
    {
        $user = User::factory()->create();
        $this->createCompanyConstant($user);

        $index = $this->catalog()->referenceIndex(WorkflowTriggerType::SCHEDULE, null, []);
        $workflow = Workflow::factory()->scheduled()->create(['creator_id' => $user->id]);
        $typeMap = $this->catalog()->runtimeTypeMap($workflow);

        // The whole-object entry is UNCHANGED: degraded to text (never `object`).
        $this->assertSame('text', $index['globals.firma']['type']->value);
        $this->assertSame('text', $typeMap['globals.firma']->value);

        // Every declared subfield is a known reference carrying its own flat type — recursively.
        $expected = [
            'globals.firma.miasto' => 'text',
            'globals.firma.pracownicy' => 'number',
            'globals.firma.zalozona' => 'date',
            'globals.firma.branza' => 'enum',
            // An array<text> rides `multi` on the flat wire (the descriptor keeps the element base).
            'globals.firma.tagi' => 'multi',
            // A nested container degrades to text exactly like its parent, and RECURSES.
            'globals.firma.geo' => 'text',
            'globals.firma.geo.lat' => 'number',
            'globals.firma.geo.lng' => 'number',
            // The array<object> field itself is referenceable (as a degraded container).
            'globals.firma.kontakty' => 'text',
        ];

        foreach ($expected as $path => $type) {
            $this->assertArrayHasKey($path, $index, $path . ' must be write-validatable');
            $this->assertSame($type, $index[$path]['type']->value, $path . ' index type');
            $this->assertArrayHasKey($path, $typeMap, $path . ' must be typed at runtime');
            $this->assertSame($type, $typeMap[$path]->value, $path . ' runtime type');
        }

        // A REPEATER's ELEMENT subfield stays non-referenceable (per-element access is the deferred
        // R2 loop) — at every nesting level, exactly like a form repeater's element.
        $this->assertArrayNotHasKey('globals.firma.kontakty.email', $index);
        $this->assertArrayNotHasKey('globals.firma.kontakty.email', $typeMap);
    }

    public function test_object_constant_subfields_add_no_new_catalog_variables(): void
    {
        $user = User::factory()->create();
        $this->createCompanyConstant($user);

        // The editor builds its picker children from `descriptor.fields`, so this is an INDEX /
        // type-map widening ONLY: the flat `variables[]` contract still carries ONE entry per constant.
        $variables = $this->catalog()->forContext(null)['variables'];

        $constant = $this->variableByPath($variables, 'globals.firma');
        $this->assertNotNull($constant);
        $this->assertNull($this->variableByPath($variables, 'globals.firma.miasto'));
        $this->assertNull($this->variableByPath($variables, 'globals.firma.geo.lat'));

        // …and the descriptor the FE expands is untouched (base object + the ordered field keys).
        $this->assertSame('object', $constant['descriptor']['base']);
        $this->assertSame(
            ['miasto', 'pracownicy', 'zalozona', 'branza', 'tagi', 'geo', 'kontakty'],
            array_column($constant['descriptor']['fields'], 'key'),
        );
    }

    public function test_global_values_map_returns_the_stored_literals(): void
    {
        $user = User::factory()->create();
        // The values map is workspace-scoped, so it is read INSIDE a workspace — as every real caller
        // does (an HTTP request through ResolveWorkspace, a job through QueueTenancy).
        app(TenantContext::class)->set($this->workspaceFor($user));

        Constant::factory()->text('nazwa_marki', 'Taskio')->create(['creator_id' => $user->id]);
        Constant::factory()->number('budzet', 5000)->create(['creator_id' => $user->id]);
        Constant::factory()->textList('hashtagi', ['#ai', '#automatyzacja'])->create(['creator_id' => $user->id]);

        $this->assertSame([
            'nazwa_marki' => 'Taskio',
            'budzet' => 5000,
            'hashtagi' => ['#ai', '#automatyzacja'],
        ], $this->catalog()->globalValues());
    }

    public function test_global_values_map_is_scoped_to_the_active_workspace(): void
    {
        $user = User::factory()->create();
        $workspaceA = $this->workspaceFor($user);
        $workspaceB = $this->workspaceFor($user);

        Constant::factory()->text('own_brand', 'A')->create(['creator_id' => $user->id, 'workspace_id' => $workspaceA->id]);
        Constant::factory()->text('foreign_brand', 'B')->create(['creator_id' => $user->id, 'workspace_id' => $workspaceB->id]);

        app(TenantContext::class)->set($workspaceA);

        $this->assertSame(['own_brand' => 'A'], $this->catalog()->globalValues());
    }

    public function test_no_active_workspace_yields_no_globals_at_all(): void
    {
        // WorkspaceScope deliberately leaves a query UNCONSTRAINED with no active workspace (queue
        // jobs, console, login) — and this map COLLAPSES rows into `key => value`, so an unguarded
        // read would flatten EVERY workspace's constants into one map (last row wins) and a trigger gate
        // or a step could resolve a FOREIGN workspace's constant. With no tenant to read constants for,
        // the honest answer is none.
        $user = User::factory()->create();
        $workspaceA = $this->workspaceFor($user);
        $workspaceB = $this->workspaceFor($user);

        Constant::factory()->text('brand', 'A')->create(['creator_id' => $user->id, 'workspace_id' => $workspaceA->id]);
        Constant::factory()->text('brand', 'B')->create(['creator_id' => $user->id, 'workspace_id' => $workspaceB->id]);

        app(TenantContext::class)->clear();

        $this->assertSame([], $this->catalog()->globalValues());
    }

    public function test_a_globals_gated_condition_reads_no_foreign_global_without_a_workspace(): void
    {
        // The same guard seen from the SECURITY boundary that motivated it: the trigger gate. Its
        // `globals.*` source resolves through the very same map, so with no active workspace the
        // condition can only fail closed — never open on another workspace's constant.
        $user = User::factory()->create();
        $foreign = $this->workspaceFor($user);

        Constant::factory()->text('nazwa_marki', 'Taskio')->create([
            'creator_id' => $user->id,
            'workspace_id' => $foreign->id,
        ]);

        app(TenantContext::class)->clear();

        $gate = app(WorkflowConditionEngine::class)->passes([
            'logic' => 'and',
            'children' => [[
                'kind' => 'condition',
                'source' => 'globals.nazwa_marki',
                'source_type' => 'text',
                'pipeline' => [['op' => 'text_equals', 'args' => ['value' => 'Taskio']]],
            ]],
        ], ['fields' => []]);

        $this->assertFalse($gate, 'a foreign workspace constant must never open a gate');
    }

    public function test_catalog_endpoint_scopes_globals_to_the_active_workspace(): void
    {
        $user = User::factory()->create();
        $workspaceA = $this->workspaceFor($user);
        $workspaceB = $this->workspaceFor($user);

        Constant::factory()->text('own_brand', 'A')->create(['creator_id' => $user->id, 'workspace_id' => $workspaceA->id]);
        Constant::factory()->text('foreign_brand', 'B')->create(['creator_id' => $user->id, 'workspace_id' => $workspaceB->id]);

        $paths = collect(
            $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
                ->getJson('/api/workflows/catalog?trigger_type=schedule')
                ->assertOk()
                ->json('data.variables')
        )->pluck('path');

        $this->assertTrue($paths->contains('globals.own_brand'), 'the active workspace constant is present');
        $this->assertFalse($paths->contains('globals.foreign_brand'), 'a foreign workspace constant must not leak');
    }
}
