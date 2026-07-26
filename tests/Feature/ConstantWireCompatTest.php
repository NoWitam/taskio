<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Variables\Models\Constant;
use App\Modules\Workflows\Services\WorkflowConditionEngine;
use App\Modules\Workflows\Services\WorkflowVariableCatalogService;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization of the `globals.*` WIRE after the globals table→`consts` and model→Constant
 * rename. The persistence renamed, but every runtime surface that a STORED workflow config touches must
 * stay byte-identical `globals.<key>`, or configs saved before the rename would silently break. Pins
 * all three surfaces against the new Constant model:
 *   - RUNTIME: a stored `globals.<key>` condition source resolves to the constant's value (via the
 *     resolver, driven through the condition-gate);
 *   - CATALOG: the picker still emits `source:'globals'` / `path:'globals.<key>'`;
 *   - RESOURCE: the CRUD resource still exposes `reference:'globals.<key>'`.
 */
class ConstantWireCompatTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    public function test_a_stored_globals_reference_still_resolves_to_the_constant_value_after_the_rename(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);
        app(TenantContext::class)->set($workspace);

        Constant::factory()->text('nazwa_marki', 'Taskio')->create([
            'creator_id' => $user->id,
            'workspace_id' => $workspace->id,
        ]);

        $tree = fn (string $expected): array => [
            'logic' => 'and',
            'children' => [[
                'kind' => 'condition',
                'source' => 'globals.nazwa_marki',
                'source_type' => 'text',
                'pipeline' => [['op' => 'text_equals', 'args' => ['value' => $expected]]],
            ]],
        ];

        $engine = app(WorkflowConditionEngine::class);

        // The gate reads `globals.nazwa_marki` off the run context the step runner injects — the exact
        // stored-config wire. It resolves to the constant's stored value ('Taskio') post-rename.
        $this->assertTrue($engine->passes($tree('Taskio'), ['fields' => []]));
        $this->assertFalse($engine->passes($tree('Inna marka'), ['fields' => []]));
    }

    public function test_the_catalog_still_emits_the_globals_source_and_path_for_a_constant(): void
    {
        $user = User::factory()->create();
        Constant::factory()->text('nazwa_marki', 'Taskio')->create(['creator_id' => $user->id, 'name' => 'Nazwa marki']);

        $variables = app(WorkflowVariableCatalogService::class)->forContext(null)['variables'];

        $variable = null;
        foreach ($variables as $candidate) {
            if (($candidate['path'] ?? null) === 'globals.nazwa_marki') {
                $variable = $candidate;
                break;
            }
        }

        $this->assertNotNull($variable, 'the constant must surface as a globals.<key> catalog variable');
        $this->assertSame('globals', $variable['source']);
        $this->assertSame('globals.nazwa_marki', $variable['path']);
    }

    public function test_the_constant_resource_still_exposes_the_globals_reference(): void
    {
        $user = User::factory()->create();
        Constant::factory()->text('nazwa_marki', 'Taskio')->create(['creator_id' => $user->id, 'name' => 'Nazwa marki']);

        $this->actingAs($user)
            ->getJson('/api/consts')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'globals.nazwa_marki');
    }
}
