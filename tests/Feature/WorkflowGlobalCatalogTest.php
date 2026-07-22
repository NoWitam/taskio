<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowGlobal;
use App\Modules\Workflows\Services\WorkflowVariableCatalogService;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The variable CATALOG's globals source: the workspace's user-created LITERAL constants surface as
 * `globals.<key>` catalog variables (form-independent), in the reference index (write validation),
 * and in the runtime type map (pipeline typing). Pins the shape (source/path/descriptor/flat type/
 * enumOptions) and the workspace scoping.
 */
class WorkflowGlobalCatalogTest extends TestCase
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
        WorkflowGlobal::factory()->text('nazwa_marki', 'Taskio')->create(['creator_id' => $user->id, 'name' => 'Nazwa marki']);
        WorkflowGlobal::factory()->number('budzet', 5000)->create(['creator_id' => $user->id]);
        WorkflowGlobal::factory()->enum('kategoria', [['key' => 'blog', 'label' => 'Blog'], ['key' => 'news', 'label' => 'News']], 'blog')->create(['creator_id' => $user->id]);
        WorkflowGlobal::factory()->textList('hashtagi', ['#ai', '#automatyzacja'])->create(['creator_id' => $user->id]);

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

        // An array<text> global rides MULTI on the flat wire, but its descriptor keeps the true
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
        WorkflowGlobal::factory()->text('nazwa_marki', 'Taskio')->create(['creator_id' => $user->id]);

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
        WorkflowGlobal::factory()->number('budzet', 5000)->create(['creator_id' => $user->id]);

        $index = $this->catalog()->referenceIndex(WorkflowTriggerType::SCHEDULE, null, []);
        $this->assertArrayHasKey('globals.budzet', $index);
        $this->assertSame('number', $index['globals.budzet']['type']->value);

        $workflow = Workflow::factory()->scheduled()->create(['creator_id' => $user->id]);
        $typeMap = $this->catalog()->runtimeTypeMap($workflow);
        $this->assertArrayHasKey('globals.budzet', $typeMap);
        $this->assertSame('number', $typeMap['globals.budzet']->value);
    }

    public function test_global_values_map_returns_the_stored_literals(): void
    {
        $user = User::factory()->create();
        WorkflowGlobal::factory()->text('nazwa_marki', 'Taskio')->create(['creator_id' => $user->id]);
        WorkflowGlobal::factory()->number('budzet', 5000)->create(['creator_id' => $user->id]);
        WorkflowGlobal::factory()->textList('hashtagi', ['#ai', '#automatyzacja'])->create(['creator_id' => $user->id]);

        $this->assertSame([
            'nazwa_marki' => 'Taskio',
            'budzet' => 5000,
            'hashtagi' => ['#ai', '#automatyzacja'],
        ], $this->catalog()->globalValues());
    }

    public function test_catalog_endpoint_scopes_globals_to_the_active_workspace(): void
    {
        $user = User::factory()->create();
        $workspaceA = $this->workspaceFor($user);
        $workspaceB = $this->workspaceFor($user);

        WorkflowGlobal::factory()->text('own_brand', 'A')->create(['creator_id' => $user->id, 'workspace_id' => $workspaceA->id]);
        WorkflowGlobal::factory()->text('foreign_brand', 'B')->create(['creator_id' => $user->id, 'workspace_id' => $workspaceB->id]);

        $paths = collect(
            $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
                ->getJson('/api/workflows/catalog?trigger_type=schedule')
                ->assertOk()
                ->json('data.variables')
        )->pluck('path');

        $this->assertTrue($paths->contains('globals.own_brand'), 'the active workspace global is present');
        $this->assertFalse($paths->contains('globals.foreign_brand'), 'a foreign workspace global must not leak');
    }
}
