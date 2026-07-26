<?php

namespace Tests\Unit\Workflows;

use App\Modules\Variables\Enums\VariableType as WorkflowVariableType;
use App\Modules\Variables\Services\OperationExecutor as WorkflowOperationExecutor;
use App\Modules\Workflows\Services\WorkflowVariableResolver;
use Illuminate\Support\Carbon;
use RuntimeException;
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
            // Phase 3: the workspace's user-created LITERAL constants, injected under the `globals`
            // root as a {<key>: <value>} map. `evil` holds a value that literally contains a flat
            // token — the second-order injection probe (a global value must render VERBATIM).
            'globals' => [
                'brand' => 'Taskio',
                'budget' => 5000,
                'flag' => true,
                'tags' => ['#ai', '#automatyzacja'],
                'evil' => '{{trigger.fields.priority}}',
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

    // ---- globals root (Phase 3) ----------------------------------------------

    public function test_globals_flat_token_resolves_the_stored_literal(): void
    {
        // The `globals` root is whitelisted (a plain dotted lookup — no graph, no cycles).
        $this->assertSame('Taskio', $this->resolver->resolve('{{globals.brand}}', $this->context()));
    }

    public function test_globals_flat_token_preserves_non_string_types(): void
    {
        // A number stays a number, a boolean a boolean, an array an array (standalone flat token
        // returns the typed value unchanged).
        $this->assertSame(5000, $this->resolver->resolve('{{globals.budget}}', $this->context()));
        $this->assertTrue($this->resolver->resolve('{{globals.flag}}', $this->context()));
        $this->assertSame(['#ai', '#automatyzacja'], $this->resolver->resolve('{{globals.tags}}', $this->context()));
    }

    public function test_globals_directive_resolves_the_stored_literal(): void
    {
        $this->assertSame('Taskio', $this->resolver->resolve($this->directive('globals.brand'), $this->context()));
    }

    public function test_globals_directive_embeds_and_joins_arrays(): void
    {
        $md = 'Brand ' . $this->directive('globals.brand') . ' tags ' . $this->directive('globals.tags');

        $this->assertSame('Brand Taskio tags #ai, #automatyzacja', $this->resolver->resolve($md, $this->context()));
    }

    public function test_nonexistent_global_fails_soft_to_null(): void
    {
        $this->assertNull($this->resolver->resolve('{{globals.nope}}', $this->context()));
        $this->assertSame('prefix-', $this->resolver->resolve('prefix-{{globals.nope}}', $this->context()));
    }

    public function test_a_global_value_is_resolved_in_a_structured_slot(): void
    {
        // A structured non-text field referencing a global resolves + coerces its literal.
        $field = ['kind' => 'variable', 'ref' => ['source' => 'globals', 'path' => 'globals.budget', 'type' => 'number']];

        $this->assertSame(5000, $this->resolver->resolveValueOrVariable($field, $this->context(), WorkflowVariableType::NUMBER));
    }

    // ---- Finding B: source-aware scope detection (a global NAMED index/element) ---

    public function test_a_global_whose_path_leaf_is_index_is_pre_resolved_not_treated_as_loop_scope(): void
    {
        // A workspace global literally NAMED "index" (source 'globals', path leaf 'index') is an ORDINARY
        // ref, NOT the synthetic per-element loop scope. Before the source-aware fix the resolver mis-flagged
        // it as scope by its path LEAF alone and refused to pre-resolve it — so as an OP ARGUMENT it reached
        // the pure executor as an unresolved union and failed closed. It must now pre-resolve to the GLOBAL's
        // value (100), so `meta.count (7) + globals.index (100)` = 107.
        $context = $this->context();
        $context['globals']['index'] = 100;

        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'meta.count', 'type' => 'number'],
            'pipeline' => [
                ['op' => 'num_add', 'args' => [
                    'value' => ['kind' => 'variable', 'ref' => ['source' => 'globals', 'path' => 'globals.index', 'type' => 'number']],
                ]],
            ],
        ];

        $this->assertSame(107.0, $this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::NUMBER));
    }

    public function test_a_global_named_index_wins_over_the_loop_scope_inside_an_element_pipeline(): void
    {
        // The INSIDE case: the SAME global "index" used as an op argument WITHIN an element pipeline (a
        // reduce reducer) must still resolve to the GLOBAL's value (100 every iteration), NOT the per-element
        // loop index (1, 2, 3). Its source is 'globals', not 'scope', so the resolver pre-resolves it to a
        // literal for ALL elements and the executor never overlays the loop index onto it. Sum = 0 + 100 +
        // 100 + 100 = 300 (the OLD leaf-only detectors leaked the loop index → 0 + 1 + 2 + 3 = 6).
        $context = $this->context();
        $context['globals']['index'] = 100;
        $context['trigger']['fields']['nums'] = ['1', '2', '3'];

        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.nums', 'type' => 'multi'],
            'pipeline' => [
                ['op' => 'array_reduce', 'args' => [
                    'seed' => ['type' => 'number', 'value' => 0],
                    'reducer' => [
                        ['op' => 'num_add', 'args' => [
                            'value' => ['kind' => 'variable', 'ref' => ['source' => 'globals', 'path' => 'globals.index', 'type' => 'number']],
                        ]],
                    ],
                ]],
            ],
        ];

        $this->assertSame(300.0, $this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::NUMBER));
    }

    public function test_a_genuine_scope_index_ref_still_folds_the_loop_index(): void
    {
        // The counterpart that MUST keep working: a TRUE scope ref (source 'scope') still resolves per
        // element to the 1-based loop index — proving the source-aware fix narrowed scope detection to
        // `source:'scope'` WITHOUT breaking genuine scope references. Sum of indices 1 + 2 + 3 = 6.
        $context = $this->context();
        $context['trigger']['fields']['nums'] = ['a', 'b', 'c'];

        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.nums', 'type' => 'multi'],
            'pipeline' => [
                ['op' => 'array_reduce', 'args' => [
                    'seed' => ['type' => 'number', 'value' => 0],
                    'reducer' => [
                        ['op' => 'num_add', 'args' => [
                            'value' => ['kind' => 'variable', 'ref' => ['source' => 'scope', 'path' => 'index', 'type' => 'number']],
                        ]],
                    ],
                ]],
            ],
        ];

        $this->assertSame(6.0, $this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::NUMBER));
    }

    /**
     * INJECTION SAFETY (critical): a global's VALUE is user-authored. A value that literally contains
     * reference-/directive-like bytes (`{{…}}`, `@[…]`) MUST render VERBATIM — it rides the SAME
     * NUL-mask path a resolved value / per-ref default uses and is NEVER re-interpreted as a second
     * reference. `globals.evil` holds the literal string "{{trigger.fields.priority}}".
     */
    public function test_a_global_value_with_reference_like_bytes_is_not_re_interpreted(): void
    {
        // Standalone flat token: the injected `{{…}}` renders literally, NOT resolved to 'high'.
        $this->assertSame(
            '{{trigger.fields.priority}}',
            $this->resolver->resolve('{{globals.evil}}', $this->context()),
        );

        // Embedded flat token: same — the surrounding text keeps the literal injected token.
        $this->assertSame(
            'Value: {{trigger.fields.priority}}',
            $this->resolver->resolve('Value: {{globals.evil}}', $this->context()),
        );

        // Standalone directive: the looked-up value stringifies verbatim (never re-scanned).
        $this->assertSame(
            '{{trigger.fields.priority}}',
            $this->resolver->resolve($this->directive('globals.evil'), $this->context()),
        );

        // Embedded directive: the substituted value is NUL-masked before the flat pass, so the
        // injected token cannot be resolved a second time (mirrors the per-ref default guarantee).
        $this->assertSame(
            'Value: {{trigger.fields.priority}}',
            $this->resolver->resolve('Value: ' . $this->directive('globals.evil'), $this->context()),
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

    // ---- per-reference defaults (phase-1b) ------------------------------------

    /** A directive carrying an optional literal `default` (+ an optional pipeline). */
    private function directiveWithDefault(string $path, mixed $default, array $pipeline = [], string $type = 'text'): string
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
                'default' => $default,
            ],
        ]);

        return '@[variable]("' . str_replace('"', '\\"', $payload) . '")';
    }

    public function test_default_is_substituted_when_the_reference_is_missing(): void
    {
        // trigger.fields.missing is absent → the literal default is used.
        $directive = $this->directiveWithDefault('trigger.fields.missing', 'fallback-value');

        $this->assertSame('fallback-value', $this->resolver->resolve($directive, $this->context()));
    }

    public function test_default_is_substituted_when_the_reference_is_empty_string(): void
    {
        $context = ['trigger' => ['fields' => ['note' => '']], 'steps' => []];
        $directive = $this->directiveWithDefault('trigger.fields.note', 'fallback-value');

        $this->assertSame('fallback-value', $this->resolver->resolve($directive, $context));
    }

    public function test_default_is_ignored_when_the_reference_has_a_value(): void
    {
        $directive = $this->directiveWithDefault('trigger.fields.priority', 'fallback-value');

        $this->assertSame('high', $this->resolver->resolve($directive, $this->context()));
    }

    public function test_default_is_applied_before_the_pipeline_runs(): void
    {
        // Missing ref → default 'draft' → THEN uppercased by the pipeline (default feeds the pipeline).
        $directive = $this->directiveWithDefault('trigger.fields.missing', 'draft', [$this->step('text_uppercase')]);

        $this->assertSame('DRAFT', $this->resolver->resolve($directive, $this->context()));
    }

    public function test_default_can_feed_a_date_format_pipeline(): void
    {
        // Missing date ref → default ISO date → formatted by the safe date_format op.
        $typeMap = ['trigger.fields.due' => WorkflowVariableType::DATE];
        $directive = $this->directiveWithDefault('trigger.fields.due', '2026-01-09', [
            $this->step('date_format', ['pattern' => 'DD/MM/YYYY']),
        ]);

        $this->assertSame('09/01/2026', $this->resolver->resolve($directive, $this->context(), $typeMap));
    }

    public function test_standalone_default_flat_token_is_not_re_interpreted(): void
    {
        // A standalone default holding `{{…}}` bytes is returned verbatim, never resolved to 'high'.
        $directive = $this->directiveWithDefault('trigger.fields.missing', '{{trigger.fields.priority}}');

        $this->assertSame('{{trigger.fields.priority}}', $this->resolver->resolve($directive, $this->context()));
    }

    public function test_embedded_default_with_reference_like_bytes_is_not_re_interpreted(): void
    {
        // Injection guard: an EMBEDDED default carrying a `{{…}}` token AND `@[…]` directive-open bytes
        // round-trips verbatim — once substituted it enters through the same NUL-mask as a resolved
        // value, so neither the flat pass (which would otherwise resolve `{{trigger.fields.priority}}`
        // to 'high') nor the directive pass re-scans it.
        $default = 'raw {{trigger.fields.priority}} and @[variable] bytes';
        $md = 'Value: ' . $this->directiveWithDefault('trigger.fields.missing', $default);

        $this->assertSame('Value: ' . $default, $this->resolver->resolve($md, $this->context()));
    }

    public function test_union_variable_uses_default_when_ref_is_missing(): void
    {
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.missing', 'type' => 'text'],
            'default' => 'fallback',
        ];

        $this->assertSame('fallback', $this->resolver->resolveValueOrVariable($field, $this->context(), WorkflowVariableType::TEXT));
    }

    public function test_union_variable_ignores_default_when_ref_present(): void
    {
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.priority', 'type' => 'text'],
            'default' => 'fallback',
        ];

        $this->assertSame('high', $this->resolver->resolveValueOrVariable($field, $this->context(), WorkflowVariableType::TEXT));
    }

    // ---- assert_present hard-fail escalation (phase-1b) -----------------------

    public function test_assert_present_passes_a_present_value_through_the_resolver(): void
    {
        $directive = $this->directiveWithPipeline('trigger.fields.priority', 'text', [$this->step('assert_present')]);

        $this->assertSame('high', $this->resolver->resolve($directive, $this->context()));
    }

    public function test_assert_present_over_an_empty_value_raises_a_run_step_failure(): void
    {
        // A directive pipeline ending in assert_present over a MISSING ref re-raises as a
        // RuntimeException — the runner records the step failed and stops the run ("force a value").
        $directive = $this->directiveWithPipeline('trigger.fields.missing', 'text', [$this->step('assert_present')]);

        $this->expectException(RuntimeException::class);
        $this->resolver->resolve($directive, $this->context());
    }

    public function test_assert_present_in_a_structured_union_raises_a_run_step_failure(): void
    {
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.missing', 'type' => 'text'],
            'pipeline' => [['op' => 'assert_present']],
        ];

        $this->expectException(RuntimeException::class);
        $this->resolver->resolveValueOrVariable($field, $this->context(), WorkflowVariableType::TEXT);
    }

    // ---- structural container references (phase-2a) ---------------------------

    public function test_whole_object_container_reference_resolves_to_the_nested_map(): void
    {
        // A SECTION answer is a nested map. Referencing the whole `object` resolves fail-soft to that
        // map through the SAME whitelist — it NEVER throws / hits an UnhandledMatchError, because the
        // flat wire type degrades to text (like TIME) so the resolver's defaultless coerce/normalize
        // matches are never reached for it. Representation only — no per-element iteration.
        $context = ['trigger' => ['fields' => ['details' => ['note' => 'hello', 'tags' => ['x', 'y']]]], 'steps' => []];

        $this->assertSame(
            ['note' => 'hello', 'tags' => ['x', 'y']],
            $this->resolver->resolve('{{trigger.fields.details}}', $context),
        );
    }

    public function test_whole_repeater_container_reference_resolves_to_the_list(): void
    {
        // A REPEATER answer is a list of item objects; the whole reference resolves to that list.
        $context = ['trigger' => ['fields' => ['items' => [['item_name' => 'A'], ['item_name' => 'B']]]], 'steps' => []];

        $this->assertSame(
            [['item_name' => 'A'], ['item_name' => 'B']],
            $this->resolver->resolve('{{trigger.fields.items}}', $context),
        );
    }

    public function test_nonexistent_nested_container_path_resolves_null(): void
    {
        $context = ['trigger' => ['fields' => ['details' => ['note' => 'hello']]], 'steps' => []];

        $this->assertNull($this->resolver->resolve('{{trigger.fields.details.nope}}', $context));
    }

    public function test_nested_leaf_under_a_container_still_resolves(): void
    {
        // A section's scalar CHILD stays individually addressable (the container entry is additive).
        $context = ['trigger' => ['fields' => ['details' => ['note' => 'hello']]], 'steps' => []];

        $this->assertSame('hello', $this->resolver->resolve('{{trigger.fields.details.note}}', $context));
    }

    public function test_container_reference_in_a_structured_slot_is_fail_soft_never_throws(): void
    {
        // A container value reaching a structured value-or-variable slot must NOT throw an
        // UnhandledMatchError: it coerces fail-soft to the field's EXPECTED type (a nested map is not a
        // scalar → null for TEXT; a list is wrapped for MULTI). No `object` type reaches coerce (the
        // ref carries a degraded scalar type), so the defaultless match is unreachable — mirroring TIME.
        $objectField = ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.details', 'type' => 'text']];
        $objectContext = ['trigger' => ['fields' => ['details' => ['note' => 'hello']]], 'steps' => []];
        $this->assertNull($this->resolver->resolveValueOrVariable($objectField, $objectContext, WorkflowVariableType::TEXT));

        $listField = ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.items', 'type' => 'multi']];
        $listContext = ['trigger' => ['fields' => ['items' => [['item_name' => 'A']]]], 'steps' => []];
        $this->assertSame([['item_name' => 'A']], $this->resolver->resolveValueOrVariable($listField, $listContext, WorkflowVariableType::MULTI));
    }

    // ---- array<object> (repeater) element pipelines at runtime (wave 3) --------

    public function test_repeater_filter_then_count_resolves_at_runtime_despite_a_degraded_wire_type(): void
    {
        // A repeater ref carries the DEGRADED wire type `text`, but its runtime VALUE is a real list and the
        // pipeline LEADS with an array op — so the base type is recovered as an array (arrayPipelineBaseType)
        // and filter(element.price > 100) |> count runs, yielding 2.
        $field = ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.rows', 'type' => 'text'], 'pipeline' => [
            ['op' => 'array_filter', 'args' => ['pipeline' => [
                'kind' => 'variable',
                'ref' => ['source' => 'scope', 'path' => 'element.price', 'type' => 'number'],
                'pipeline' => [['op' => 'num_gt', 'args' => ['value' => 100]]],
            ]]],
            ['op' => 'array_count', 'args' => []],
        ]];
        $context = ['trigger' => ['fields' => ['rows' => [['price' => 150], ['price' => 50], ['price' => 200]]]], 'steps' => []];

        $this->assertSame(2.0, $this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::NUMBER));
    }

    public function test_repeater_map_to_a_subfield_resolves_an_array_of_that_field_at_runtime(): void
    {
        $field = ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.rows', 'type' => 'text'], 'pipeline' => [
            ['op' => 'array_map', 'args' => ['pipeline' => [
                'kind' => 'variable',
                'ref' => ['source' => 'scope', 'path' => 'element.name', 'type' => 'text'],
                'pipeline' => [],
            ]]],
        ]];
        $context = ['trigger' => ['fields' => ['rows' => [['name' => 'Ann'], ['name' => 'Bob']]]], 'steps' => []];

        $this->assertSame(['Ann', 'Bob'], $this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::MULTI));
    }

    // ---- composite file subfield references (phase-2b) ------------------------

    /**
     * A run context carrying a single-file answer as the snapshot LIST the payload factory builds:
     * a one-element list of {id, name, mime_type, size, url}. (A single-file input is a 1-element list.)
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fileContext(array $overrides = []): array
    {
        $snapshot = $overrides + [
            'id' => 'file-uuid-1',
            'name' => 'raport.pdf',
            'mime_type' => 'application/pdf',
            'size' => 2048,
            'url' => 'https://taskio.test/api/disk/file-uuid-1',
        ];

        return ['trigger' => ['fields' => ['attachment' => [$snapshot]]], 'steps' => []];
    }

    public function test_whole_file_resolution_is_identical_with_or_without_the_new_url_key(): void
    {
        // BACK-COMPAT proof: the `url` key added to the snapshot (phase-2b) does NOT change how a WHOLE
        // file reference resolves — text still renders the name(s), structural still yields the id(s).
        // The FILE resolver branch (stringify→name, coerce→ids) is untouched by this slice.
        $withUrl = ['trigger' => ['fields' => ['attachment' => [
            ['id' => 'f1', 'name' => 'a.pdf', 'mime_type' => 'application/pdf', 'size' => 9, 'url' => 'https://x/f1'],
        ]]], 'steps' => []];
        $withoutUrl = ['trigger' => ['fields' => ['attachment' => [
            ['id' => 'f1', 'name' => 'a.pdf', 'mime_type' => 'application/pdf', 'size' => 9],
        ]]], 'steps' => []];

        foreach ([$withUrl, $withoutUrl] as $context) {
            // TEXT: a standalone file directive stringifies to the NAME.
            $this->assertSame('a.pdf', $this->resolver->resolve($this->directive('trigger.fields.attachment', 'file'), $context));
            // TEXT: an embedded flat token stringifies to the NAME too.
            $this->assertSame('File: a.pdf', $this->resolver->resolve('File: {{trigger.fields.attachment}}', $context));
            // STRUCTURAL: the union coerced to FILE yields the IDS (what copy-on-attach reads).
            $field = ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.attachment', 'type' => 'file']];
            $this->assertSame(['f1'], $this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::FILE));
        }
    }

    public function test_file_subfield_id_name_url_type_size_each_resolve(): void
    {
        $context = $this->fileContext();

        // A standalone flat token returns the raw typed value: text subfields as strings, size as int.
        $this->assertSame('file-uuid-1', $this->resolver->resolve('{{trigger.fields.attachment.id}}', $context));
        $this->assertSame('raport.pdf', $this->resolver->resolve('{{trigger.fields.attachment.name}}', $context));
        $this->assertSame('https://taskio.test/api/disk/file-uuid-1', $this->resolver->resolve('{{trigger.fields.attachment.url}}', $context));
        // `type` is the human alias the resolver maps to the snapshot's `mime_type`.
        $this->assertSame('application/pdf', $this->resolver->resolve('{{trigger.fields.attachment.type}}', $context));
        // `size` preserves its numeric type through a standalone token.
        $this->assertSame(2048, $this->resolver->resolve('{{trigger.fields.attachment.size}}', $context));
    }

    public function test_file_subfield_resolves_through_the_directive_and_union_serializations(): void
    {
        $context = $this->fileContext();

        // Directive (embedded) → stringified into the surrounding text.
        $md = 'File is ' . $this->directive('trigger.fields.attachment.name', 'text');
        $this->assertSame('File is raport.pdf', $this->resolver->resolve($md, $context));

        // Structured union → coerced to the field's expected scalar type.
        $field = ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.attachment.name', 'type' => 'text']];
        $this->assertSame('raport.pdf', $this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::TEXT));
    }

    public function test_file_subfield_in_an_if_block_condition_resolves(): void
    {
        // A file subfield is a plain scalar, so it flows through the condition executor like any text.
        $md = $this->ifBlock(
            $this->branch('IF', $this->condition('trigger.fields.attachment.type', [$this->step('text_equals', ['value' => 'application/pdf'])]), 'pdf'),
            $this->branch('ELSE', null, 'other'),
        );

        $this->assertSame('pdf', $this->resolver->resolve($md, $this->fileContext()));
    }

    public function test_missing_or_non_file_subfield_is_fail_soft_null(): void
    {
        // An unknown tail on a real file → null (not a throw): `nope` is not a file subfield.
        $this->assertNull($this->resolver->resolve('{{trigger.fields.attachment.nope}}', $this->fileContext()));

        // A file-subfield WORD on a NON-file parent → null (the parent isn't a snapshot).
        $nonFile = ['trigger' => ['fields' => ['note' => 'hello']], 'steps' => []];
        $this->assertNull($this->resolver->resolve('{{trigger.fields.note.name}}', $nonFile));

        // An empty file answer ([]) → collapse finds no element → null.
        $empty = ['trigger' => ['fields' => ['attachment' => []]], 'steps' => []];
        $this->assertNull($this->resolver->resolve('{{trigger.fields.attachment.name}}', $empty));
    }

    public function test_multi_file_subfield_takes_the_first_element_fail_soft(): void
    {
        // A multi-file answer collapses to its FIRST element (single-file semantics; true per-element
        // iteration is deferred to the R2 loop) — it must degrade, NEVER crash.
        $context = ['trigger' => ['fields' => ['attachment' => [
            ['id' => 'f1', 'name' => 'first.pdf', 'mime_type' => 'application/pdf', 'size' => 1, 'url' => 'https://x/f1'],
            ['id' => 'f2', 'name' => 'second.png', 'mime_type' => 'image/png', 'size' => 2, 'url' => 'https://x/f2'],
        ]]], 'steps' => []];

        $this->assertSame('first.pdf', $this->resolver->resolve('{{trigger.fields.attachment.name}}', $context));
        $this->assertSame('f1', $this->resolver->resolve('{{trigger.fields.attachment.id}}', $context));
    }

    public function test_file_subfield_in_a_structured_slot_never_throws_unhandled_match(): void
    {
        // Same tripwire reasoning as TIME/object: a file subfield ref carries a PLAIN scalar type, so
        // it coerces fail-soft and NEVER reaches the resolver's defaultless coerce match with an
        // `object`/unhandled base. The WHOLE-file ref still coerces via the existing FILE arm.
        $context = $this->fileContext();

        $subfield = ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.attachment.size', 'type' => 'number']];
        $this->assertSame(2048, $this->resolver->resolveValueOrVariable($subfield, $context, WorkflowVariableType::NUMBER));

        $whole = ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.attachment', 'type' => 'file']];
        $this->assertSame(['file-uuid-1'], $this->resolver->resolveValueOrVariable($whole, $context, WorkflowVariableType::FILE));
    }

    public function test_file_subfield_pipeline_resolves_at_runtime(): void
    {
        // The write-side now accepts a pipeline-bearing file-subfield ref; the runtime must actually
        // execute it. A text pipeline on <file>.name and a number pipeline on <file>.size each flow
        // from the SUBFIELD's type (carried on the ref) through the executor to the coerced result.
        $context = $this->fileContext();

        $nameField = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.attachment.name', 'type' => 'text'],
            'pipeline' => [['op' => 'text_uppercase', 'args' => []]],
        ];
        $this->assertSame('RAPORT.PDF', $this->resolver->resolveValueOrVariable($nameField, $context, WorkflowVariableType::TEXT));

        $sizeField = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.attachment.size', 'type' => 'number'],
            'pipeline' => [['op' => 'num_add', 'args' => ['value' => 1]]],
        ];
        // The number executor works in float, so 2048 + 1 → 2049.0 (the coerced numeric terminal).
        $this->assertSame(2049.0, $this->resolver->resolveValueOrVariable($sizeField, $context, WorkflowVariableType::NUMBER));
    }

    // ---- object GLOBAL subfield references (phase-2c) -------------------------

    /**
     * A run context whose `globals` root carries a STRUCTURED constant (an object global's stored
     * literal), including a nested object and an array<object> element list.
     *
     * @return array<string, mixed>
     */
    private function objectGlobalContext(): array
    {
        return [
            'trigger' => ['fields' => ['priority' => 'high']],
            'steps' => [],
            'globals' => [
                'firma' => [
                    'miasto' => 'Warszawa',
                    'pracownicy' => 12,
                    'geo' => ['lat' => 52.23, 'lng' => 21.01],
                    'kontakty' => [['email' => 'kontakt@taskio.test']],
                    // The second-order injection probe, one level INSIDE the object.
                    'evil' => '{{trigger.fields.priority}}',
                ],
            ],
        ];
    }

    public function test_object_global_subfield_resolves_through_every_serialization(): void
    {
        $context = $this->objectGlobalContext();

        // The write-side now accepts `globals.<key>.<sub>` refs; the runtime resolves them as a plain
        // whitelisted dotted lookup (no new mechanism) — in each serialization.
        $this->assertSame('Warszawa', $this->resolver->resolve('{{globals.firma.miasto}}', $context));
        $this->assertSame(12, $this->resolver->resolve('{{globals.firma.pracownicy}}', $context));
        $this->assertSame('Warszawa', $this->resolver->resolve($this->directive('globals.firma.miasto'), $context));
        $this->assertSame('Miasto: Warszawa', $this->resolver->resolve('Miasto: ' . $this->directive('globals.firma.miasto'), $context));

        $field = ['kind' => 'variable', 'ref' => ['source' => 'globals', 'path' => 'globals.firma.miasto', 'type' => 'text']];
        $this->assertSame('Warszawa', $this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::TEXT));
    }

    public function test_nested_object_global_subfield_resolves_and_an_unknown_one_is_fail_soft(): void
    {
        $context = $this->objectGlobalContext();

        // Two levels deep — the index enumerates it recursively, and so does Arr::get.
        $this->assertSame(52.23, $this->resolver->resolve('{{globals.firma.geo.lat}}', $context));

        $nested = ['kind' => 'variable', 'ref' => ['source' => 'globals', 'path' => 'globals.firma.geo.lat', 'type' => 'number']];
        $this->assertSame(52.23, $this->resolver->resolveValueOrVariable($nested, $context, WorkflowVariableType::NUMBER));

        // An undeclared subfield (and a repeater ELEMENT, which the index deliberately never offers)
        // resolves fail-soft to null — never a throw.
        $this->assertNull($this->resolver->resolve('{{globals.firma.nieznane}}', $context));
        $this->assertNull($this->resolver->resolve('{{globals.firma.kontakty.email}}', $context));
    }

    public function test_object_global_subfield_pipeline_runs_against_the_subfield_type(): void
    {
        $context = $this->objectGlobalContext();

        // The runtime type map carries the SUBFIELD's real type (the same entries the write-validator
        // type-flowed), so a directive pipeline executes as a number — not as the container's text.
        $typeMap = ['globals.firma.pracownicy' => WorkflowVariableType::NUMBER];
        $directive = $this->directiveWithPipeline('globals.firma.pracownicy', 'text', [$this->step('num_add', ['value' => 3])]);

        $this->assertSame('15', $this->resolver->resolve($directive, $context, $typeMap));

        // The structured union carries the subfield type on the ref itself.
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'globals', 'path' => 'globals.firma.miasto', 'type' => 'text'],
            'pipeline' => [['op' => 'text_uppercase', 'args' => []]],
        ];
        $this->assertSame('WARSZAWA', $this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::TEXT));
    }

    public function test_an_object_global_subfield_value_with_reference_like_bytes_is_not_re_interpreted(): void
    {
        // INJECTION SAFETY carries into the object interior: a subfield value holding `{{…}}` bytes
        // rides the SAME NUL-mask path and renders VERBATIM (never resolved to 'high').
        $context = $this->objectGlobalContext();

        $this->assertSame('{{trigger.fields.priority}}', $this->resolver->resolve('{{globals.firma.evil}}', $context));
        $this->assertSame(
            'Value: {{trigger.fields.priority}}',
            $this->resolver->resolve('Value: ' . $this->directive('globals.firma.evil'), $context),
        );
    }

    public function test_a_genuine_nested_field_named_like_a_subfield_still_resolves_directly(): void
    {
        // Guard: the subfield collapse is a FALLBACK only when Arr::get misses. A real nested map with
        // its own `name`/`type` key (e.g. a section child) resolves directly and is never intercepted.
        $context = ['trigger' => ['fields' => ['details' => ['name' => 'Ada', 'type' => 'admin']]], 'steps' => []];

        $this->assertSame('Ada', $this->resolver->resolve('{{trigger.fields.details.name}}', $context));
        $this->assertSame('admin', $this->resolver->resolve('{{trigger.fields.details.type}}', $context));
    }

    // ---- operation ARGUMENTS supplied by a variable (phase-4a) ----------------

    public function test_op_argument_supplied_by_a_variable_resolves_from_context(): void
    {
        // num_add's `value` arg is a VARIABLE (not a constant): it is pulled from context and coerced
        // to the arg's DECLARED type (number) before the pure executor runs the op — 10 + 5 = 15.
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.base', 'type' => 'number'],
            'pipeline' => [['op' => 'num_add', 'args' => ['value' => [
                'kind' => 'variable',
                // A TEXT context value '5' is coerced to the arg's declared NUMBER type.
                'ref' => ['source' => 'trigger', 'path' => 'fields.addend', 'type' => 'text'],
            ]]]],
        ];
        $context = ['trigger' => ['fields' => ['base' => 10, 'addend' => '5']], 'steps' => []];

        $this->assertSame(15.0, $this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::NUMBER));
    }

    public function test_op_argument_variable_applies_its_own_pipeline_one_level_deep(): void
    {
        // The arg-variable itself carries a pipeline (text_uppercase) — resolved one level deep before
        // it feeds text_append: 'ada' → 'ADA', appended to 'Hi ' → 'Hi ADA'.
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.greeting', 'type' => 'text'],
            'pipeline' => [['op' => 'text_append', 'args' => ['value' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.name', 'type' => 'text'],
                'pipeline' => [['op' => 'text_uppercase']],
            ]]]],
        ];
        $context = ['trigger' => ['fields' => ['greeting' => 'Hi ', 'name' => 'ada']], 'steps' => []];

        $this->assertSame('Hi ADA', $this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::TEXT));
    }

    public function test_op_argument_literal_is_unchanged_backcompat(): void
    {
        // REGRESSION: a pipeline whose op arg is a plain LITERAL resolves byte-identically to before the
        // arg-variable work — the resolver only rewrites args shaped as a `{kind:'variable'}` union.
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.base', 'type' => 'number'],
            'pipeline' => [['op' => 'num_add', 'args' => ['value' => 3]]],
        ];
        $context = ['trigger' => ['fields' => ['base' => 10]], 'steps' => []];

        $this->assertSame(13.0, $this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::NUMBER));
    }

    public function test_op_argument_variable_resolves_in_a_directive_pipeline(): void
    {
        // The DIRECTIVE pipeline path pre-resolves arg-variables too: 'high' → 'HIGH', then text_append
        // whose `value` is a variable (globals.brand = 'Taskio') → 'HIGHTaskio'.
        $directive = $this->directiveWithPipeline('trigger.fields.priority', 'text', [
            $this->step('text_uppercase'),
            $this->step('text_append', ['value' => [
                'kind' => 'variable',
                'ref' => ['source' => 'globals', 'path' => 'globals.brand', 'type' => 'text'],
            ]]),
        ]);

        $this->assertSame('HIGHTaskio', $this->resolver->resolve($directive, $this->context()));
    }

    public function test_op_argument_variable_resolves_in_an_if_block_condition(): void
    {
        // The IF-BLOCK condition pipeline path pre-resolves arg-variables too: text_equals compares the
        // source (fields.a) against a VARIABLE arg (fields.b) — equal → the IF branch wins.
        $context = ['trigger' => ['fields' => ['a' => 'match', 'b' => 'match']], 'steps' => []];
        $md = $this->ifBlock(
            $this->branch('IF', $this->condition('trigger.fields.a', [
                $this->step('text_equals', ['value' => [
                    'kind' => 'variable',
                    'ref' => ['source' => 'trigger', 'path' => 'fields.b', 'type' => 'text'],
                ]]),
            ]), 'EQUAL'),
            $this->branch('ELSE', null, 'DIFFERENT'),
        );

        $this->assertSame('EQUAL', $this->resolver->resolve($md, $context));
    }

    public function test_arg_variable_value_with_reference_like_bytes_is_not_re_interpreted(): void
    {
        // INJECTION SAFETY: an arg-variable can resolve to a value that literally contains reference
        // bytes. `trigger.fields.injection` holds "{{trigger.fields.priority}}". Appended by the pure
        // executor and stashed on the SAME NUL-mask path a resolved value uses, it renders VERBATIM —
        // never re-scanned into 'high'.
        $directive = $this->directiveWithPipeline('trigger.title', 'text', [
            $this->step('text_append', ['value' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.injection', 'type' => 'text'],
            ]]),
        ]);

        $this->assertSame(
            'Out: Trigger title{{trigger.fields.priority}}',
            $this->resolver->resolve('Out: ' . $directive, $this->context()),
        );
    }

    /**
     * A number arg-variable (ref `trigger.fields.tag` = 't') whose text_append pipeline nests another
     * such arg-variable, $levels deep. $levels = 1 is a bare ref (no pipeline) — the chain's bottom.
     *
     * @return array<string, mixed>
     */
    private function nestedArgVariable(int $levels): array
    {
        $ref = ['source' => 'trigger', 'path' => 'fields.tag', 'type' => 'text'];

        if ($levels <= 1) {
            return ['kind' => 'variable', 'ref' => $ref];
        }

        return [
            'kind' => 'variable',
            'ref' => $ref,
            'pipeline' => [['op' => 'text_append', 'args' => ['value' => $this->nestedArgVariable($levels - 1)]]],
        ];
    }

    /** A top-level value field (`fields.base` = 'B') whose text_append appends $argVariable. */
    private function topFieldAppending(array $argVariable): array
    {
        return [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.base', 'type' => 'text'],
            'pipeline' => [['op' => 'text_append', 'args' => ['value' => $argVariable]]],
        ];
    }

    public function test_arg_variable_nesting_within_the_depth_cap_resolves(): void
    {
        // Three arg-variable levels (the cap) all resolve: each appends 't' to the deeper result —
        // 't' → 'tt' → 'ttt' — then the top field prepends its own 'B'.
        $context = ['trigger' => ['fields' => ['base' => 'B', 'tag' => 't']], 'steps' => []];

        $this->assertSame(
            'Bttt',
            $this->resolver->resolveValueOrVariable($this->topFieldAppending($this->nestedArgVariable(3)), $context, WorkflowVariableType::TEXT),
        );
    }

    public function test_arg_variable_nesting_beyond_the_depth_cap_fails_soft(): void
    {
        // A FOURTH arg-variable level exceeds MAX_ARG_VARIABLE_DEPTH: the deepest arg resolves fail-soft
        // (null), its op fail-closes, and the whole nested pipeline collapses to the field's soft
        // default (null) — no crash, no unbounded work (there are no cycles, only bounded nesting).
        $context = ['trigger' => ['fields' => ['base' => 'B', 'tag' => 't']], 'steps' => []];

        $this->assertNull(
            $this->resolver->resolveValueOrVariable($this->topFieldAppending($this->nestedArgVariable(4)), $context, WorkflowVariableType::TEXT),
        );
    }

    // ---- op ARGUMENTS: option / multi-option / structural variables (phase-4b) ----

    public function test_op_single_option_argument_supplied_by_a_variable_resolves(): void
    {
        // enum_is' `value` (a single-OPTION arg) is a VARIABLE: pulled from context and coerced to a
        // string option key before the compare. 'high' === 'high' → true.
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.priority', 'type' => 'enum'],
            'pipeline' => [['op' => 'enum_is', 'args' => ['value' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.match', 'type' => 'enum'],
            ]]]],
        ];
        $context = ['trigger' => ['fields' => ['priority' => 'high', 'match' => 'high']], 'steps' => []];

        $this->assertTrue($this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::BOOLEAN));
    }

    public function test_op_multi_option_argument_supplied_by_a_variable_resolves(): void
    {
        // enum_in's `values` (a multi-OPTION arg) is a VARIABLE resolving to a set; membership is checked
        // against it at runtime. 'b' ∈ ['a','b'] → true.
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.priority', 'type' => 'enum'],
            'pipeline' => [['op' => 'enum_in', 'args' => ['values' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.set', 'type' => 'multi'],
            ]]]],
        ];
        $context = ['trigger' => ['fields' => ['priority' => 'b', 'set' => ['a', 'b']]], 'steps' => []];

        $this->assertTrue($this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::BOOLEAN));
    }

    public function test_op_source_map_entry_supplied_by_a_variable_resolves(): void
    {
        // PER-ENTRY (Defect-3): enum_to_text's `mapping` is a {option: Entry} map whose 'high' TARGET is a
        // VARIABLE (referencing globals.target). The executor still reads a plain {option: scalar} map.
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.priority', 'type' => 'enum'],
            'pipeline' => [['op' => 'enum_to_text', 'args' => ['mapping' => [
                'high' => ['kind' => 'variable', 'ref' => ['source' => 'globals', 'path' => 'globals.target', 'type' => 'text']],
            ]]]],
        ];
        $context = [
            'trigger' => ['fields' => ['priority' => 'high']],
            'steps' => [],
            'globals' => ['target' => 'urgent'],
        ];

        $this->assertSame('urgent', $this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::TEXT));
    }

    public function test_op_source_map_mixes_literal_and_variable_entries_byte_identically(): void
    {
        // A literal entry is byte-identical to today (a bare scalar); a variable entry pre-resolves. The
        // picked option decides which one the executor reads.
        $mapping = [
            'low' => 'normal', // literal, untouched
            'high' => ['kind' => 'variable', 'ref' => ['source' => 'globals', 'path' => 'globals.target', 'type' => 'text']],
        ];
        $context = ['trigger' => ['fields' => ['priority' => 'low']], 'steps' => [], 'globals' => ['target' => 'urgent']];

        $field = fn () => [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.priority', 'type' => 'enum'],
            'pipeline' => [['op' => 'enum_to_text', 'args' => ['mapping' => $mapping]]],
        ];

        // priority 'low' picks the LITERAL entry, unchanged.
        $this->assertSame('normal', $this->resolver->resolveValueOrVariable($field(), $context, WorkflowVariableType::TEXT));

        // priority 'high' picks the VARIABLE entry.
        $context['trigger']['fields']['priority'] = 'high';
        $this->assertSame('urgent', $this->resolver->resolveValueOrVariable($field(), $context, WorkflowVariableType::TEXT));
    }

    public function test_op_choice_rules_then_entry_supplied_by_a_variable_resolves(): void
    {
        // PER-ENTRY (Defect-3): match_to_choice's `rules` is a [{when, then}] list whose matched rule's
        // `then` is a VARIABLE. 'BREAKING' matches → the resolved `then` ('high').
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.headline', 'type' => 'text'],
            'pipeline' => [['op' => 'match_to_choice', 'args' => [
                'rules' => [['when' => [['op' => 'text_equals', 'args' => ['value' => 'BREAKING']]], 'then' => ['kind' => 'variable', 'ref' => ['source' => 'globals', 'path' => 'globals.chosen', 'type' => 'enum']]]],
                'fallback' => 'low',
            ]]],
        ];
        $context = [
            'trigger' => ['fields' => ['headline' => 'BREAKING']],
            'steps' => [],
            'globals' => ['chosen' => 'high'],
        ];

        $this->assertSame('high', $this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::ENUM));

        // A literal `then` beside a variable `when`-match is unchanged.
        $literal = $field;
        $literal['pipeline'][0]['args']['rules'] = [['when' => [['op' => 'text_equals', 'args' => ['value' => 'BREAKING']]], 'then' => 'high']];
        $this->assertSame('high', $this->resolver->resolveValueOrVariable($literal, $context, WorkflowVariableType::ENUM));
    }

    public function test_op_source_map_date_entry_variable_narrows_to_the_wire_date(): void
    {
        // enum_to_date's target is DATE: a variable entry coerces to the ISO instant a FIELD stores, then
        // narrows to the strict Y-m-d the executor's enumMap date reader accepts (argWireDate).
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.priority', 'type' => 'enum'],
            'pipeline' => [
                ['op' => 'enum_to_date', 'args' => ['mapping' => [
                    'high' => ['kind' => 'variable', 'ref' => ['source' => 'globals', 'path' => 'globals.when', 'type' => 'date']],
                ]]],
                ['op' => 'date_to_text'],
            ],
        ];
        $context = ['trigger' => ['fields' => ['priority' => 'high']], 'steps' => [], 'globals' => ['when' => '2026-01-01']];

        $this->assertSame('2026-01-01', $this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::TEXT));
    }

    public function test_op_source_map_entry_with_its_own_sub_pipeline_resolves_depth_capped(): void
    {
        // A per-entry variable may carry its OWN sub-pipeline — uppercased here — resolved one arg-variable
        // level deeper (depth-capped like a top-level arg-variable).
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.priority', 'type' => 'enum'],
            'pipeline' => [['op' => 'enum_to_text', 'args' => ['mapping' => [
                'high' => [
                    'kind' => 'variable',
                    'ref' => ['source' => 'globals', 'path' => 'globals.target', 'type' => 'text'],
                    'pipeline' => [['op' => 'text_uppercase']],
                ],
            ]]]],
        ];
        $context = ['trigger' => ['fields' => ['priority' => 'high']], 'steps' => [], 'globals' => ['target' => 'urgent']];

        $this->assertSame('URGENT', $this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::TEXT));
    }

    public function test_op_source_map_entry_variable_that_fails_to_resolve_fails_the_entry_closed(): void
    {
        // A per-entry variable pointing at an UNANSWERED field resolves to null → the option maps to null →
        // enumMap treats it as unmapped and the pipeline fails closed (never opens a gate). Fail-soft null.
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.priority', 'type' => 'enum'],
            'pipeline' => [['op' => 'enum_to_text', 'args' => ['mapping' => [
                'high' => ['kind' => 'variable', 'ref' => ['source' => 'globals', 'path' => 'globals.missing', 'type' => 'text']],
            ]]]],
        ];
        $context = ['trigger' => ['fields' => ['priority' => 'high']], 'steps' => [], 'globals' => []];

        $this->assertNull($this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::TEXT));
    }

    public function test_op_choice_fallback_variable_out_of_set_returns_the_value_fail_soft(): void
    {
        // match_to_choice's `fallback` (a single-OPTION arg) is a VARIABLE resolving to a value OUTSIDE the
        // destination option set. Membership is a RUNTIME concern: the op returns the resolved fallback
        // as-is (no crash) — the downstream consumer soft-defaults an unknown choice (e.g. priority → MED).
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.headline', 'type' => 'text'],
            'pipeline' => [['op' => 'match_to_choice', 'args' => [
                'rules' => [['when' => [['op' => 'text_equals', 'args' => ['value' => 'x']]], 'then' => 'high']],
                'fallback' => ['kind' => 'variable', 'ref' => ['source' => 'globals', 'path' => 'globals.brand', 'type' => 'text']],
            ]]],
        ];
        // headline is not 'x' → no rule matches → the (variable, out-of-set) fallback 'Taskio' is returned.
        $context = ['trigger' => ['fields' => ['headline' => 'whatever']], 'steps' => [], 'globals' => ['brand' => 'Taskio']];

        $this->assertSame('Taskio', $this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::ENUM));
    }

    public function test_op_source_map_whole_arg_union_is_rejected_fail_soft(): void
    {
        // DEFENSIVE (Defect-3): the whole-structure design was removed — a sourceMap is a per-entry
        // container, never itself a variable. A legacy/hand-written whole-arg union left as-is is rejected
        // by the executor's array reader, so the pipeline soft-resolves to null. Never a crash.
        $field = [
            'kind' => 'variable',
            'ref' => ['source' => 'trigger', 'path' => 'fields.priority', 'type' => 'enum'],
            'pipeline' => [['op' => 'enum_to_text', 'args' => ['mapping' => [
                'kind' => 'variable',
                'ref' => ['source' => 'globals', 'path' => 'globals.map', 'type' => 'object'],
            ]]]],
        ];
        $context = [
            'trigger' => ['fields' => ['priority' => 'high']],
            'steps' => [],
            'globals' => ['map' => ['high' => 'urgent']],
        ];

        $this->assertNull($this->resolver->resolveValueOrVariable($field, $context, WorkflowVariableType::TEXT));
    }

    public function test_structural_entry_variable_value_with_reference_like_bytes_is_not_re_interpreted(): void
    {
        // INJECTION SAFETY (per-entry): a mapping ENTRY variable resolves to a VALUE that literally contains
        // `{{…}}` bytes. The pure executor maps to it verbatim and the OUTPUT rides the SAME NUL-mask as any
        // resolved value — so an embedded directive pipeline renders it literally, never re-scanning it.
        $directive = $this->directiveWithPipeline('trigger.fields.priority', 'text', [
            $this->step('enum_to_text', ['mapping' => [
                'high' => ['kind' => 'variable', 'ref' => ['source' => 'globals', 'path' => 'globals.evil', 'type' => 'text']],
            ]]),
        ]);
        $context = [
            'trigger' => ['fields' => ['priority' => 'high']],
            'steps' => [],
            'globals' => ['evil' => '{{trigger.fields.priority}}'],
        ];

        $this->assertSame(
            'Out: {{trigger.fields.priority}}',
            $this->resolver->resolve('Out: ' . $directive, $context),
        );
    }
}
