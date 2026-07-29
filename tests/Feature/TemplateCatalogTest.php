<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Variables\Models\Constant;
use App\Modules\Variables\Models\CustomFunction;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The server-authoritative TEMPLATE catalog endpoint (POST /generator/catalog): it emits the declared
 * `slots.<name>` typed variables MERGED with the shared authoring surface (workspace globals, the
 * operation catalog incl. custom functions surfaced as `fn:<uuid>` ops, and the variable-type list).
 * Draft-friendly — the slots ride the request body, so an in-progress template gets a real catalog.
 */
class TemplateCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    private function variableByPath(array $variables, string $path): ?array
    {
        foreach ($variables as $variable) {
            if (($variable['path'] ?? null) === $path) {
                return $variable;
            }
        }

        return null;
    }

    public function test_catalog_emits_slot_variables_globals_functions_and_types(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        // A workspace global (globals.<key>) and a workspace custom function (surfaced as fn:<uuid>).
        Constant::factory()->text('brand', 'Taskio')->create(['creator_id' => $user->id, 'workspace_id' => $workspace->id, 'name' => 'Brand']);
        $function = CustomFunction::factory()->create(['creator_id' => $user->id, 'workspace_id' => $workspace->id]);

        $response = $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/generator/catalog', [
                'slots' => [
                    ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]],
                    ['name' => 'tone', 'descriptor' => ['base' => 'enum', 'options' => [['key' => 'formal', 'label' => 'Formal'], ['key' => 'casual', 'label' => 'Casual']]]],
                ],
            ])
            ->assertOk()
            ->json('data');

        // The declared slots surface as `slots.<name>` variables (source EQUALS root).
        $topic = $this->variableByPath($response['variables'], 'slots.topic');
        $this->assertNotNull($topic);
        $this->assertSame('slots', $topic['source']);
        $this->assertSame('text', $topic['type']);
        $this->assertSame('text', $topic['descriptor']['base']);
        $this->assertSame('The subject', $topic['name']);

        // An enum slot carries its option keys on the flat wire (like a global enum).
        $tone = $this->variableByPath($response['variables'], 'slots.tone');
        $this->assertSame('enum', $tone['type']);
        $this->assertSame(['formal', 'casual'], $tone['enumOptions']);

        // The workspace global is merged in.
        $this->assertNotNull($this->variableByPath($response['variables'], 'globals.brand'));

        // The custom function is offered as a `fn:<uuid>` operation.
        $ids = array_column($response['operations'], 'id');
        $this->assertContains('fn:' . $function->id, $ids);

        // The full variable-type list rides `types`.
        $this->assertNotEmpty($response['types']);
        $this->assertContains('text', array_column($response['types'], 'id'));
    }

    public function test_catalog_object_and_file_slots_carry_their_descriptor_fields_for_subfield_expansion(): void
    {
        $user = User::factory()->create();

        $scalar = fn (string $base): array => ['base' => $base, 'nullable' => false, 'array' => false];

        $variables = $this->actingAs($user)
            ->postJson('/api/generator/catalog', [
                'slots' => [
                    ['name' => 'product', 'descriptor' => [
                        'base' => 'object', 'nullable' => false, 'array' => false,
                        'fields' => [['key' => 'name', 'label' => 'Name', 'descriptor' => $scalar('text')]],
                    ]],
                    ['name' => 'image', 'descriptor' => [
                        'base' => 'file', 'nullable' => false, 'array' => false,
                        'fields' => [['key' => 'url', 'label' => 'url', 'descriptor' => $scalar('text')]],
                    ]],
                ],
            ])
            ->assertOk()
            ->json('data.variables');

        // The object slot degrades its flat wire type to text but CARRIES its `fields` — the SAME shape the
        // workflow object variable emits, so the shared FE variable-tree expands `slots.product.name` from
        // the descriptor (no separate flat subfield entry needed).
        $product = $this->variableByPath($variables, 'slots.product');
        $this->assertNotNull($product);
        $this->assertSame('text', $product['type']);
        $this->assertSame('object', $product['descriptor']['base']);
        $this->assertSame('name', $product['descriptor']['fields'][0]['key']);

        // The file slot keeps its `file` wire type + composite fields (the tree expands slots.image.url).
        $image = $this->variableByPath($variables, 'slots.image');
        $this->assertNotNull($image);
        $this->assertSame('file', $image['type']);
        $this->assertSame('file', $image['descriptor']['base']);
    }

    public function test_catalog_scopes_globals_to_the_active_workspace(): void
    {
        $user = User::factory()->create();
        $workspaceA = $this->workspaceFor($user);
        $workspaceB = $this->workspaceFor($user);

        Constant::factory()->text('own_brand', 'A')->create(['creator_id' => $user->id, 'workspace_id' => $workspaceA->id]);
        Constant::factory()->text('foreign_brand', 'B')->create(['creator_id' => $user->id, 'workspace_id' => $workspaceB->id]);

        $variables = $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->postJson('/api/generator/catalog', ['slots' => []])
            ->assertOk()
            ->json('data.variables');

        $paths = array_column($variables, 'path');
        $this->assertContains('globals.own_brand', $paths);
        $this->assertNotContains('globals.foreign_brand', $paths);
    }

    public function test_catalog_is_draft_friendly_and_skips_a_malformed_slot(): void
    {
        $user = User::factory()->create();

        // A slot missing its descriptor is skipped (fail-soft) — the catalog still returns for the rest.
        $variables = $this->actingAs($user)
            ->postJson('/api/generator/catalog', [
                'slots' => [
                    ['name' => 'good', 'descriptor' => ['base' => 'text']],
                    ['name' => 'bad'],
                ],
            ])
            ->assertOk()
            ->json('data.variables');

        $paths = array_column($variables, 'path');
        $this->assertContains('slots.good', $paths);
        $this->assertNotContains('slots.bad', $paths);
    }

    public function test_catalog_is_unauthenticated_for_a_guest(): void
    {
        $this->postJson('/api/generator/catalog', ['slots' => []])
            ->assertUnauthorized();
    }
}
