<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Variables\Models\CustomFunction;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Write-time DEFINITION + BODY + CYCLE validation for CUSTOM FUNCTIONS (Phase 3a): a body type-flows
 * over {input + args} and must terminate in the return type; args are unique, non-reserved, typed; a
 * body may reference OTHER functions (nesting) but the reference graph must stay ACYCLIC (self-ref /
 * A→B→A / deeper cycles rejected); and a function referenced by another cannot be deleted.
 */
class CustomFunctionValidationTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    /** Assert a 422 carrying at least one validation error under $prefix (exact or a nested key). */
    private function assertErrorUnder(TestResponse $response, string $prefix): void
    {
        $response->assertStatus(422);

        $keys = array_keys($response->json('errors') ?? []);

        $this->assertTrue(
            collect($keys)->contains(fn (string $key): bool => $key === $prefix || str_starts_with($key, $prefix . '.')),
            "Expected a validation error under '{$prefix}'. Got: " . (implode(', ', $keys) ?: '(none)'),
        );
    }

    /** A valid text→text function payload. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Fn',
            'input_type' => 'text',
            'args' => [],
            'return_type' => 'text',
            'body' => [['op' => 'text_uppercase']],
        ], $overrides);
    }

    // ---- body type-flow over {input + args} ----------------------------------

    public function test_a_body_referencing_an_arg_validates_and_terminates_in_the_return_type(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/functions', $this->payload([
                'args' => [['name' => 'suffix', 'type' => 'text']],
                'body' => [[
                    'op' => 'text_append',
                    'args' => ['value' => ['kind' => 'variable', 'ref' => ['source' => 'scope', 'path' => 'suffix', 'type' => 'text']]],
                ]],
            ]))
            ->assertCreated();
    }

    public function test_a_body_can_stringify_a_number_input_into_the_text_return_type(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/functions', $this->payload([
                'input_type' => 'number',
                'return_type' => 'text',
                'body' => [['op' => 'num_to_text']],
            ]))
            ->assertCreated();
    }

    public function test_a_return_type_mismatch_is_rejected(): void
    {
        $user = User::factory()->create();

        // text → text_uppercase → text, but the return type is number.
        $this->assertErrorUnder(
            $this->actingAs($user)->postJson('/api/functions', $this->payload(['return_type' => 'number'])),
            'body',
        );
    }

    public function test_an_invalid_input_or_return_type_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->assertErrorUnder(
            $this->actingAs($user)->postJson('/api/functions', $this->payload(['input_type' => 'nonsense'])),
            'input_type',
        );

        $this->assertErrorUnder(
            $this->actingAs($user)->postJson('/api/functions', $this->payload(['return_type' => 'nonsense'])),
            'return_type',
        );
    }

    // ---- arg rules ------------------------------------------------------------

    public function test_a_reserved_arg_name_is_rejected(): void
    {
        $user = User::factory()->create();

        foreach (['input', 'element', 'index'] as $reserved) {
            $this->assertErrorUnder(
                $this->actingAs($user)->postJson('/api/functions', $this->payload([
                    'args' => [['name' => $reserved, 'type' => 'text']],
                ])),
                'args.0.name',
            );
        }
    }

    public function test_a_duplicate_arg_name_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->assertErrorUnder(
            $this->actingAs($user)->postJson('/api/functions', $this->payload([
                'args' => [
                    ['name' => 'x', 'type' => 'text'],
                    ['name' => 'x', 'type' => 'text'],
                ],
            ])),
            'args.1.name',
        );
    }

    public function test_an_invalid_arg_type_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->assertErrorUnder(
            $this->actingAs($user)->postJson('/api/functions', $this->payload([
                'args' => [['name' => 'x', 'type' => 'nonsense']],
            ])),
            'args.0.type',
        );
    }

    public function test_a_non_scope_reference_inside_a_body_is_rejected(): void
    {
        $user = User::factory()->create();

        // A body may only read its input + args (via scope refs). A globals/trigger/steps arg-variable
        // is rejected at write (scope-only reference index).
        $this->assertErrorUnder(
            $this->actingAs($user)->postJson('/api/functions', $this->payload([
                'body' => [[
                    'op' => 'text_append',
                    'args' => ['value' => ['kind' => 'variable', 'ref' => ['source' => 'globals', 'path' => 'globals.brand', 'type' => 'text']]],
                ]],
            ])),
            'body',
        );
    }

    // ---- frame stack: array transforms inside a body (A1) ---------------------

    public function test_a_body_filters_over_an_array_combining_the_element_with_an_arg(): void
    {
        $user = User::factory()->create();

        // THE FRAME STACK (A1 headline): a body filters over its multi INPUT and the element-pipeline
        // predicate references the enclosing `needle` ARG — the map's `element` AND the function frame are
        // in scope AT ONCE. Previously 422 (the element scope discarded the enclosing frame); now accepted,
        // exactly matching the runtime (OperationExecutorFunctionTest frame-stack twin).
        $this->actingAs($user)
            ->postJson('/api/functions', $this->payload([
                'input_type' => 'multi',
                'args' => [['name' => 'needle', 'type' => 'text']],
                'return_type' => 'multi',
                'body' => [[
                    'op' => 'array_filter',
                    'args' => ['pipeline' => [[
                        'op' => 'enum_is',
                        'args' => ['value' => ['kind' => 'variable', 'ref' => ['source' => 'scope', 'path' => 'needle', 'type' => 'text']]],
                    ]]],
                ]],
            ]))
            ->assertCreated();
    }

    public function test_a_body_reduces_over_an_array_combining_the_accumulator_with_an_arg(): void
    {
        $user = User::factory()->create();

        // reduce over the input: the reducer (rooted at the accumulator) references the enclosing `factor`
        // ARG — the reduce frame stack (element/index + the function frame) resolves at write, as at runtime.
        $this->actingAs($user)
            ->postJson('/api/functions', $this->payload([
                'input_type' => 'multi',
                'args' => [['name' => 'factor', 'type' => 'number']],
                'return_type' => 'number',
                'body' => [[
                    'op' => 'array_reduce',
                    'args' => [
                        'seed' => ['type' => 'number', 'value' => 0],
                        'reducer' => [[
                            'op' => 'num_add',
                            'args' => ['value' => ['kind' => 'variable', 'ref' => ['source' => 'scope', 'path' => 'factor', 'type' => 'number']]],
                        ]],
                    ],
                ]],
            ]))
            ->assertCreated();
    }

    public function test_a_body_maps_over_an_array_referencing_the_whole_input(): void
    {
        $user = User::factory()->create();

        // map over the input, the element pipeline referencing the enclosing `input` frame root (the whole
        // array, reduced to text via a sub-pipeline) — proves `input` (not just an arg) rides the frame
        // stack into an element pipeline.
        $this->actingAs($user)
            ->postJson('/api/functions', $this->payload([
                'input_type' => 'multi',
                'args' => [],
                'return_type' => 'multi',
                'body' => [[
                    'op' => 'array_map',
                    'args' => ['pipeline' => [[
                        'op' => 'enum_is',
                        'args' => ['value' => [
                            'kind' => 'variable',
                            'ref' => ['source' => 'scope', 'path' => 'input', 'type' => 'multi'],
                            'pipeline' => [['op' => 'multi_to_text']],
                        ]],
                    ]]],
                ]],
            ]))
            ->assertCreated();
    }

    public function test_a_non_scope_reference_inside_a_body_element_pipeline_is_rejected(): void
    {
        $user = User::factory()->create();

        // NO FAIL-OPEN: inside a body's element pipeline the element scope is unioned with the function
        // frame ONLY. A globals/trigger/steps arg-variable resolves to nothing at runtime, so it is STILL
        // write-rejected — the frame-stack fix widens the scope to EXACTLY the runtime scope, never further.
        $this->assertErrorUnder(
            $this->actingAs($user)->postJson('/api/functions', $this->payload([
                'input_type' => 'multi',
                'return_type' => 'multi',
                'body' => [[
                    'op' => 'array_filter',
                    'args' => ['pipeline' => [[
                        'op' => 'enum_is',
                        'args' => ['value' => ['kind' => 'variable', 'ref' => ['source' => 'globals', 'path' => 'globals.brand', 'type' => 'text']]],
                    ]]],
                ]],
            ])),
            'body',
        );
    }

    public function test_an_unknown_scope_reference_inside_a_body_element_pipeline_is_rejected(): void
    {
        $user = User::factory()->create();

        // NO FAIL-OPEN: a scope ref to a name that is neither element/index nor a declared arg/input has no
        // binding at runtime, so it is STILL rejected at write. The merged scope is EXACTLY
        // {element, index, +subfields} ∪ the function frame — `ghost` is in none of them.
        $this->assertErrorUnder(
            $this->actingAs($user)->postJson('/api/functions', $this->payload([
                'input_type' => 'multi',
                'args' => [['name' => 'needle', 'type' => 'text']],
                'return_type' => 'multi',
                'body' => [[
                    'op' => 'array_filter',
                    'args' => ['pipeline' => [[
                        'op' => 'enum_is',
                        'args' => ['value' => ['kind' => 'variable', 'ref' => ['source' => 'scope', 'path' => 'ghost', 'type' => 'text']]],
                    ]]],
                ]],
            ])),
            'body',
        );
    }

    // ---- nesting + cycles -----------------------------------------------------

    public function test_a_body_referencing_another_function_validates(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $inner = CustomFunction::factory()->create([
            'creator_id' => $user->id,
            'workspace_id' => $workspace->id,
            'input_type' => 'text', 'return_type' => 'text', 'args' => [], 'body' => [['op' => 'text_uppercase']],
        ]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/functions', $this->payload([
                'name' => 'Outer',
                'body' => [['op' => 'fn:' . $inner->id]],
            ]))
            ->assertCreated();
    }

    public function test_a_body_referencing_an_unknown_function_is_rejected(): void
    {
        $user = User::factory()->create();

        // A `fn:<uuid>` not among the workspace functions resolves to null → the walk rejects it.
        $this->assertErrorUnder(
            $this->actingAs($user)->postJson('/api/functions', $this->payload([
                'body' => [['op' => 'fn:00000000-0000-0000-0000-000000000000']],
            ])),
            'body',
        );
    }

    public function test_a_self_reference_is_rejected(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);
        $fn = CustomFunction::factory()->create([
            'creator_id' => $user->id,
            'workspace_id' => $workspace->id,
            'input_type' => 'text', 'return_type' => 'text', 'args' => [], 'body' => [['op' => 'text_uppercase']],
        ]);

        $this->assertErrorUnder(
            $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
                ->putJson("/api/functions/{$fn->id}", $this->payload([
                    'name' => 'Self',
                    'body' => [['op' => 'fn:' . $fn->id]],
                ])),
            'body',
        );
    }

    public function test_a_two_function_cycle_is_rejected(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $b = CustomFunction::factory()->create([
            'creator_id' => $user->id, 'workspace_id' => $workspace->id,
            'input_type' => 'text', 'return_type' => 'text', 'args' => [], 'body' => [['op' => 'text_uppercase']],
        ]);
        // A → B
        $a = CustomFunction::factory()->create([
            'creator_id' => $user->id, 'workspace_id' => $workspace->id,
            'input_type' => 'text', 'return_type' => 'text', 'args' => [], 'body' => [['op' => 'fn:' . $b->id]],
        ]);

        // Point B → A, closing the A → B → A cycle.
        $this->assertErrorUnder(
            $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
                ->putJson("/api/functions/{$b->id}", $this->payload([
                    'name' => 'B', 'body' => [['op' => 'fn:' . $a->id]],
                ])),
            'body',
        );
    }

    public function test_a_three_deep_cycle_is_rejected(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $c = CustomFunction::factory()->create([
            'creator_id' => $user->id, 'workspace_id' => $workspace->id,
            'input_type' => 'text', 'return_type' => 'text', 'args' => [], 'body' => [['op' => 'text_uppercase']],
        ]);
        $b = CustomFunction::factory()->create([
            'creator_id' => $user->id, 'workspace_id' => $workspace->id,
            'input_type' => 'text', 'return_type' => 'text', 'args' => [], 'body' => [['op' => 'fn:' . $c->id]],
        ]); // B → C
        $a = CustomFunction::factory()->create([
            'creator_id' => $user->id, 'workspace_id' => $workspace->id,
            'input_type' => 'text', 'return_type' => 'text', 'args' => [], 'body' => [['op' => 'fn:' . $b->id]],
        ]); // A → B

        // Point C → A, closing the A → B → C → A cycle.
        $this->assertErrorUnder(
            $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
                ->putJson("/api/functions/{$c->id}", $this->payload([
                    'name' => 'C', 'body' => [['op' => 'fn:' . $a->id]],
                ])),
            'body',
        );
    }

    // ---- delete guard ---------------------------------------------------------

    public function test_delete_is_blocked_while_referenced_by_another_function(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $inner = CustomFunction::factory()->create([
            'creator_id' => $user->id, 'workspace_id' => $workspace->id,
            'input_type' => 'text', 'return_type' => 'text', 'args' => [], 'body' => [['op' => 'text_uppercase']],
        ]);
        $outer = CustomFunction::factory()->create([
            'creator_id' => $user->id, 'workspace_id' => $workspace->id,
            'input_type' => 'text', 'return_type' => 'text', 'args' => [], 'body' => [['op' => 'fn:' . $inner->id]],
        ]);

        // The referenced inner function is blocked (422); the referencing outer function is deletable.
        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->deleteJson("/api/functions/{$inner->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('custom_functions', ['id' => $inner->id]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->deleteJson("/api/functions/{$outer->id}")
            ->assertOk();
    }
}
