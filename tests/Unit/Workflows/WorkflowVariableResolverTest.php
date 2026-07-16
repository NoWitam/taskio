<?php

namespace Tests\Unit\Workflows;

use App\Modules\Workflows\Enums\WorkflowVariableType;
use App\Modules\Workflows\Services\WorkflowOperationExecutor;
use App\Modules\Workflows\Services\WorkflowVariableResolver;
use Illuminate\Support\Carbon;
use Tests\Support\ScriptedWorkflowAiTextService;
use Tests\TestCase;

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
        // SB1 tests never emit an ai-text directive, so the (default) double is never called.
        $this->resolver = new WorkflowVariableResolver(new WorkflowOperationExecutor, new ScriptedWorkflowAiTextService);
        Carbon::setTestNow('2026-07-14 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function context(): array
    {
        return [
            'trigger' => [
                'task_id' => 'trigger-task-uuid',
                'title' => 'Trigger title',
                'meta' => ['count' => 7],
                'fields' => [
                    'priority' => 'high',
                    'tags' => ['a', 'b'],
                    // An UNTRUSTED form value that literally contains a token (second-order probe).
                    'injection' => '{{trigger.fields.priority}}',
                ],
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

    public function test_standalone_directive_stringifies_non_text_value(): void
    {
        // REGRESSION (reviewer B1): a directive ONLY lives in a text field, so a standalone chip
        // that IS the whole field must STRINGIFY its value (parity with the embedded path) — a bare
        // multi/number/boolean chip in a task title must NOT arrive as a raw array (which would
        // hard-fail requireString('title') with a misleading "requires a title").
        $this->assertSame(
            'a, b',
            $this->resolver->resolve($this->directive('trigger.fields.tags'), $this->context()),
        );
    }

    public function test_directive_substituted_value_is_not_re_scanned_as_a_flat_token(): void
    {
        // REGRESSION (reviewer S3): an (untrusted) form value that literally contains a `{{…}}`
        // token, once substituted by the directive pass, must NOT be re-resolved by the flat pass.
        // `trigger.fields.injection` holds the literal string "{{trigger.fields.priority}}".
        $md = 'Value: ' . $this->directive('trigger.fields.injection');

        $this->assertSame('Value: {{trigger.fields.priority}}', $this->resolver->resolve($md, $this->context()));
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

    /**
     * A directive whose `data.pipeline` carries editor pipeline steps
     * ({stepId, operationId, args, outputType}). SB1 EXECUTES it (it used to be ignored).
     */
    private function directiveWithPipeline(string $path, string $type, array $pipeline): string
    {
        $payload = json_encode([
            'v' => 1,
            'data' => [
                'id' => $path,
                'name' => $path,
                'type' => $type,
                'locked' => false,
                'pipeline' => $pipeline,
                'resultType' => $type,
            ],
        ]);

        return '@[variable]("' . str_replace('"', '\\"', $payload) . '")';
    }

    /** One editor-shaped pipeline step (operationId, not op). */
    private function step(string $operationId, array $args = []): array
    {
        return ['stepId' => 's_' . $operationId, 'operationId' => $operationId, 'args' => $args, 'outputType' => 'text'];
    }

    public function test_directive_pipeline_transforms_text_standalone(): void
    {
        // Standalone directive in a text field: uppercase → the STRINGIFIED transformed result.
        $directive = $this->directiveWithPipeline('trigger.fields.priority', 'text', [$this->step('text_uppercase')]);

        $this->assertSame('HIGH', $this->resolver->resolve($directive, $this->context()));
    }

    public function test_directive_pipeline_transforms_embedded(): void
    {
        $md = 'Priority is ' . $this->directiveWithPipeline('trigger.fields.priority', 'text', [
            $this->step('text_uppercase'),
            $this->step('text_append', ['value' => '!']),
        ]);

        $this->assertSame('Priority is HIGH!', $this->resolver->resolve($md, $this->context()));
    }

    public function test_directive_pipeline_number_multiply_to_text_uses_true_type_from_map(): void
    {
        // The directive degrades a number field to primitive 'number'; the type map supplies the real
        // type so a number pipeline (×2 → to_text) runs against the numeric field value.
        $context = ['trigger' => ['fields' => ['count' => 21]], 'steps' => []];
        $typeMap = ['trigger.fields.count' => WorkflowVariableType::NUMBER];

        $directive = $this->directiveWithPipeline('trigger.fields.count', 'number', [
            $this->step('num_multiply', ['value' => 2]),
            $this->step('num_to_text'),
        ]);

        $this->assertSame('42', $this->resolver->resolve($directive, $context, $typeMap));
    }

    public function test_directive_pipeline_date_formats_with_true_type_from_map(): void
    {
        // A date field: add 5 days, then a DATE terminal is stringified as Y-m-d.
        $context = ['trigger' => ['fields' => ['due' => '2026-01-10']], 'steps' => []];
        $typeMap = ['trigger.fields.due' => WorkflowVariableType::DATE];

        $directive = $this->directiveWithPipeline('trigger.fields.due', 'text', [$this->step('date_add_days', ['value' => 5])]);

        $this->assertSame('2026-01-15', $this->resolver->resolve($directive, $context, $typeMap));
    }

    public function test_directive_pipeline_failure_resolves_to_empty_string(): void
    {
        // 'high' is not numeric → text_to_number fails → the fail-closed doctrine yields ''.
        $directive = $this->directiveWithPipeline('trigger.fields.priority', 'number', [$this->step('text_to_number')]);

        $this->assertSame('', $this->resolver->resolve($directive, $this->context()));
    }

    // ---- if-blocks ------------------------------------------------------------

    /** Build a boolean if-condition on $variableId with an editor-shaped pipeline. */
    private function condition(string $variableId, array $pipeline): array
    {
        return ['variableId' => $variableId, 'pipeline' => $pipeline, 'resultType' => 'boolean'];
    }

    /** Serialize one branch marker + body. */
    private function branch(string $keyword, ?array $condition, string $body): string
    {
        $meta = $condition !== null ? ['id' => 'b', 'condition' => $condition] : ['id' => 'b'];

        return '[[' . $keyword . ' ' . json_encode($meta) . ']]' . "\n" . $body;
    }

    /** Wrap branch strings in the fenced if-block container. */
    private function ifBlock(string ...$branches): string
    {
        return '```if-block ' . json_encode(['id' => 'if_1', 'v' => 1]) . "\n" . implode("\n", $branches) . "\n```";
    }

    public function test_if_block_picks_the_true_if_branch(): void
    {
        $md = $this->ifBlock(
            $this->branch('IF', $this->condition('trigger.fields.priority', [$this->step('text_equals', ['value' => 'high'])]), 'Urgent path'),
            $this->branch('ELSE', null, 'Normal path'),
        );

        $this->assertSame('Urgent path', $this->resolver->resolve($md, $this->context()));
    }

    public function test_if_block_falls_through_to_else(): void
    {
        $md = $this->ifBlock(
            $this->branch('IF', $this->condition('trigger.fields.priority', [$this->step('text_equals', ['value' => 'low'])]), 'Urgent path'),
            $this->branch('ELSE', null, 'Normal path'),
        );

        $this->assertSame('Normal path', $this->resolver->resolve($md, $this->context()));
    }

    public function test_if_block_selects_matching_else_if(): void
    {
        $md = $this->ifBlock(
            $this->branch('IF', $this->condition('trigger.fields.priority', [$this->step('text_equals', ['value' => 'low'])]), 'A'),
            $this->branch('ELSE_IF', $this->condition('trigger.fields.priority', [$this->step('text_equals', ['value' => 'high'])]), 'B'),
            $this->branch('ELSE', null, 'C'),
        );

        $this->assertSame('B', $this->resolver->resolve($md, $this->context()));
    }

    public function test_if_block_no_match_and_no_else_is_empty(): void
    {
        $md = $this->ifBlock(
            $this->branch('IF', $this->condition('trigger.fields.priority', [$this->step('text_equals', ['value' => 'nope'])]), 'Body'),
        );

        $this->assertSame('', $this->resolver->resolve($md, $this->context()));
    }

    public function test_if_block_winning_branch_resolves_inner_directive(): void
    {
        // The winning branch body carries a directive with a pipeline — it must be resolved
        // recursively (an if-block fence must start a line, so the prefix lives in the body).
        $inner = $this->directiveWithPipeline('trigger.fields.priority', 'text', [$this->step('text_uppercase')]);
        $md = $this->ifBlock(
            $this->branch('IF', $this->condition('trigger.fields.priority', [$this->step('text_is_not_empty')]), 'value=' . $inner),
            $this->branch('ELSE', null, 'none'),
        );

        $this->assertSame('value=HIGH', $this->resolver->resolve($md, $this->context()));
    }

    public function test_nested_if_block_resolves_the_inner_winner(): void
    {
        $innerBlock = $this->ifBlock(
            $this->branch('IF', $this->condition('trigger.fields.priority', [$this->step('text_equals', ['value' => 'high'])]), 'inner-high'),
            $this->branch('ELSE', null, 'inner-other'),
        );

        $md = $this->ifBlock(
            $this->branch('IF', $this->condition('trigger.fields.priority', [$this->step('text_is_not_empty')]), $innerBlock),
            $this->branch('ELSE', null, 'outer-else'),
        );

        $this->assertSame('inner-high', $this->resolver->resolve($md, $this->context()));
    }

    public function test_if_block_condition_failure_is_treated_as_false(): void
    {
        // A missing variable path → the branch condition is fail-closed to false → ELSE wins.
        $md = $this->ifBlock(
            $this->branch('IF', $this->condition('trigger.fields.missing', [$this->step('text_is_not_empty')]), 'yes'),
            $this->branch('ELSE', null, 'no'),
        );

        $this->assertSame('no', $this->resolver->resolve($md, $this->context()));
    }

    // ---- value-or-variable pipeline (C) --------------------------------------

    public function test_value_or_variable_pipeline_transforms_date(): void
    {
        // deadline = the submitted date + 3 days, coerced back to an ISO string.
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.due', 'type' => 'date'],
            'pipeline' => [['op' => 'date_add_days', 'args' => ['value' => 3]]],
        ];
        $context = ['trigger' => ['fields' => ['due' => '2026-01-10']], 'steps' => []];

        $resolved = $this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::DATE);

        $this->assertSame('2026-01-13', Carbon::parse($resolved)->format('Y-m-d'));
    }

    public function test_value_or_variable_pipeline_maps_enum_to_text(): void
    {
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.priority', 'type' => 'enum'],
            'pipeline' => [['op' => 'enum_to_text', 'args' => ['mapping' => ['high' => 'urgent', 'low' => 'medium']]]],
        ];

        $this->assertSame('urgent', $this->resolver->resolveValueOrVariable($field, $this->context(), WorkflowVariableType::TEXT));
    }

    public function test_value_or_variable_pipeline_failure_soft_resolves_to_null(): void
    {
        // 'high' is not numeric → the number pipeline fails → the field soft-defaults (null).
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.priority', 'type' => 'number'],
            'pipeline' => [['op' => 'num_add', 'args' => ['value' => 1]]],
        ];

        $this->assertNull($this->resolver->resolveValueOrVariable($field, $this->context(), WorkflowVariableType::NUMBER));
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
