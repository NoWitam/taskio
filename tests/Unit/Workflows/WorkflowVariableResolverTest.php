<?php

namespace Tests\Unit\Workflows;

use App\Modules\Workflows\Enums\WorkflowVariableType;
use App\Modules\Workflows\Services\WorkflowVariableResolver;
use PHPUnit\Framework\TestCase;

/**
 * The workflow variable resolver: understands the next editor's `@[variable]("…")` directive,
 * the transitional flat `{{…}}` tokens, and the structured literal|variable union. These tests
 * pin its boundaries (roots whitelist, standalone vs embedded, malformed tolerance, coercion)
 * and PORT the old ReferenceResolver contract (incl. its exfil probes).
 */
class WorkflowVariableResolverTest extends TestCase
{
    private WorkflowVariableResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new WorkflowVariableResolver;
    }

    private function context(): array
    {
        return [
            'trigger' => [
                'task_id' => 'trigger-task-uuid',
                'title' => 'Trigger title',
                'meta' => ['count' => 7],
                'fields' => ['priority' => 'high', 'tags' => ['a', 'b']],
                'submitted_at' => '2026-01-02T03:04:05+00:00',
            ],
            'steps' => [
                'make_task' => ['task_id' => 'step-task-uuid', 'title' => 'Made task'],
            ],
        ];
    }

    /**
     * Encode a variable directive with the EXACT 6-key payload the next editor's
     * encodeVariableDirective emits: {id, name, type, locked, pipeline, resultType} —
     * nothing more (the directive is identity-only; there is NO extra type field).
     */
    private function directive(string $path, string $type = 'text'): string
    {
        $payload = json_encode([
            'v' => 1,
            'data' => [
                'id' => $path,
                'name' => $path,
                'type' => $type,
                'locked' => false,
                'pipeline' => [],
                'resultType' => $type,
            ],
        ]);

        return '@[variable]("' . str_replace('"', '\\"', $payload) . '")';
    }

    // ---- Editor-shape parity ---------------------------------------------------

    public function test_resolver_reads_data_id_from_the_exact_editor_payload_shape(): void
    {
        // Parity probe: the editor's encodeVariableDirective emits exactly these SIX data
        // keys (see resources/js/next/ui/editor/extensions/variable.ts + directives.spec.ts).
        // If the FE ever renames `id` or reshapes the payload, this pins what the backend
        // resolves so the drift surfaces here instead of silently breaking runs.
        $payload = '{\\"v\\":1,\\"data\\":{\\"id\\":\\"trigger.task_id\\",\\"name\\":\\"Task id\\",'
            . '\\"type\\":\\"text\\",\\"locked\\":false,\\"pipeline\\":[],\\"resultType\\":\\"text\\"}}';

        $value = $this->resolver->resolve('@[variable]("' . $payload . '")', $this->context());

        $this->assertSame('trigger-task-uuid', $value);
    }

    // ---- Flat-token back-compat (ported from ReferenceResolverTest) -----------

    public function test_standalone_flat_token_returns_the_typed_value(): void
    {
        $this->assertSame('trigger-task-uuid', $this->resolver->resolve('{{trigger.task_id}}', $this->context()));
    }

    public function test_standalone_flat_token_preserves_non_string_scalar_type(): void
    {
        $this->assertSame(7, $this->resolver->resolve('{{trigger.meta.count}}', $this->context()));
    }

    public function test_standalone_unknown_flat_path_becomes_null(): void
    {
        $this->assertNull($this->resolver->resolve('{{trigger.nope}}', $this->context()));
        $this->assertNull($this->resolver->resolve('{{steps.make_task.missing}}', $this->context()));
    }

    public function test_steps_flat_path_resolves_prior_step_output(): void
    {
        $this->assertSame('step-task-uuid', $this->resolver->resolve('{{steps.make_task.task_id}}', $this->context()));
    }

    public function test_embedded_flat_token_stringifies_into_surrounding_text(): void
    {
        $this->assertSame(
            'Task step-task-uuid is ready',
            $this->resolver->resolve('Task {{steps.make_task.task_id}} is ready', $this->context()),
        );
    }

    public function test_embedded_unknown_flat_path_becomes_empty_string(): void
    {
        // Unknown path → '' so the surrounding text stays valid.
        $this->assertSame('prefix-', $this->resolver->resolve('prefix-{{trigger.nope}}', $this->context()));
    }

    public function test_embedded_flat_array_joins_scalar_members(): void
    {
        // NEW behavior (vs the old resolver's ''): an embedded array joins its scalar members
        // with ', ' — the task's embedded-array contract.
        $this->assertSame('tags: a, b', $this->resolver->resolve('tags: {{trigger.fields.tags}}', $this->context()));
    }

    public function test_non_whitelisted_flat_root_is_left_literal(): void
    {
        // Exfil safety: env/config/db/arbitrary roots are NOT references — stay verbatim.
        $this->assertSame('{{env.SECRET}}', $this->resolver->resolve('{{env.SECRET}}', $this->context()));
        $this->assertSame('{{config.app.key}}', $this->resolver->resolve('{{config.app.key}}', $this->context()));
        $this->assertSame('a {{db.password}} b', $this->resolver->resolve('a {{db.password}} b', $this->context()));
    }

    public function test_no_expression_evaluation(): void
    {
        $this->assertSame('{{ 1 + 1 }}', $this->resolver->resolve('{{ 1 + 1 }}', $this->context()));
        $this->assertSame('{{trigger.title | upper}}', $this->resolver->resolve('{{trigger.title | upper}}', $this->context()));
    }

    public function test_whitespace_inside_flat_token_is_tolerated(): void
    {
        $this->assertSame('Trigger title', $this->resolver->resolve('{{  trigger.title  }}', $this->context()));
    }

    public function test_recurses_through_arrays(): void
    {
        $config = [
            'title' => '{{trigger.title}}',
            'nested' => [
                'task_id' => '{{steps.make_task.task_id}}',
                'label' => 'static',
                'deep' => ['count' => '{{trigger.meta.count}}'],
            ],
        ];

        $this->assertSame([
            'title' => 'Trigger title',
            'nested' => [
                'task_id' => 'step-task-uuid',
                'label' => 'static',
                'deep' => ['count' => 7],
            ],
        ], $this->resolver->resolve($config, $this->context()));
    }

    public function test_non_string_scalars_pass_through(): void
    {
        $this->assertSame(42, $this->resolver->resolve(42, $this->context()));
        $this->assertTrue($this->resolver->resolve(true, $this->context()));
        $this->assertNull($this->resolver->resolve(null, $this->context()));
    }

    // ---- Editor directive parsing --------------------------------------------

    public function test_standalone_directive_resolves_to_the_typed_value(): void
    {
        $this->assertSame(
            'high',
            $this->resolver->resolve($this->directive('trigger.fields.priority'), $this->context()),
        );
    }

    public function test_standalone_directive_returns_array_value_untouched(): void
    {
        // A multi field degrades to text in the directive but the resolved value stays an array.
        $this->assertSame(
            ['a', 'b'],
            $this->resolver->resolve($this->directive('trigger.fields.tags'), $this->context()),
        );
    }

    public function test_embedded_directive_stringifies_scalar_and_joins_array(): void
    {
        $md = 'Priority ' . $this->directive('trigger.fields.priority') . ' tags ' . $this->directive('trigger.fields.tags');

        $this->assertSame('Priority high tags a, b', $this->resolver->resolve($md, $this->context()));
    }

    public function test_directive_with_non_whitelisted_root_resolves_null(): void
    {
        // Exfil safety across the directive serialization too.
        $this->assertNull($this->resolver->resolve($this->directive('env.SECRET'), $this->context()));
        $this->assertNull($this->resolver->resolve($this->directive('config.app.key'), $this->context()));
    }

    public function test_malformed_directive_json_is_left_literal_when_embedded(): void
    {
        // A directive whose payload is not decodable JSON must not throw; embedded, it stays put.
        $broken = '@[variable]("not-json")';
        $this->assertSame('x ' . $broken . ' y', $this->resolver->resolve('x ' . $broken . ' y', $this->context()));
    }

    public function test_malformed_standalone_directive_resolves_null(): void
    {
        $this->assertNull($this->resolver->resolve('@[variable]("not-json")', $this->context()));
    }

    public function test_directive_ignores_pipeline_and_resolves_identity(): void
    {
        // A non-empty pipeline in the directive is IGNORED — identity refs only in the MVP.
        $payload = json_encode([
            'v' => 1,
            'data' => [
                'id' => 'trigger.fields.priority',
                'name' => 'Priority',
                'type' => 'text',
                'pipeline' => [['stepId' => 's1', 'operationId' => 'upper', 'args' => [], 'outputType' => 'text']],
            ],
        ]);
        $directive = '@[variable]("' . str_replace('"', '\\"', $payload) . '")';

        $this->assertSame('high', $this->resolver->resolve($directive, $this->context()));
    }

    // ---- Structured union + coercion -----------------------------------------

    public function test_union_literal_is_coerced_to_expected_type(): void
    {
        $this->assertSame(
            5,
            $this->resolver->resolveValueOrVariable(['kind' => 'literal', 'value' => '5'], $this->context(), WorkflowVariableType::NUMBER),
        );
        $this->assertTrue(
            $this->resolver->resolveValueOrVariable(['kind' => 'literal', 'value' => 'true'], $this->context(), WorkflowVariableType::BOOLEAN),
        );
    }

    public function test_union_variable_resolves_ref_path_and_coerces(): void
    {
        $field = ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.priority', 'type' => 'text']];

        $this->assertSame('high', $this->resolver->resolveValueOrVariable($field, $this->context(), WorkflowVariableType::TEXT));
    }

    public function test_union_variable_date_parse_failure_coerces_to_null(): void
    {
        $field = ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.priority', 'type' => 'date']];

        // 'high' is not a date.
        $this->assertNull($this->resolver->resolveValueOrVariable($field, $this->context(), WorkflowVariableType::DATE));
    }

    public function test_union_variable_date_coerces_to_iso_string(): void
    {
        $field = ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'submitted_at', 'type' => 'date']];

        $this->assertSame(
            '2026-01-02T03:04:05+00:00',
            $this->resolver->resolveValueOrVariable($field, $this->context(), WorkflowVariableType::DATE),
        );
    }

    public function test_union_multi_wraps_scalar_and_keeps_array(): void
    {
        $scalar = ['kind' => 'literal', 'value' => 'a'];
        $this->assertSame(['a'], $this->resolver->resolveValueOrVariable($scalar, $this->context(), WorkflowVariableType::MULTI));

        $arr = ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.tags', 'type' => 'multi']];
        $this->assertSame(['a', 'b'], $this->resolver->resolveValueOrVariable($arr, $this->context(), WorkflowVariableType::MULTI));
    }

    public function test_union_variable_non_whitelisted_root_resolves_null(): void
    {
        $field = ['kind' => 'variable', 'ref' => ['source' => 'env', 'path' => 'SECRET', 'type' => 'text']];

        $this->assertNull($this->resolver->resolveValueOrVariable($field, $this->context(), WorkflowVariableType::TEXT));
    }

    public function test_union_bare_scalar_is_treated_as_literal(): void
    {
        $this->assertSame('7', $this->resolver->resolveValueOrVariable(7, $this->context(), WorkflowVariableType::TEXT));
    }
}
