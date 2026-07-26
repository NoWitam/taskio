<?php

namespace Tests\Unit\Workflows;

use App\Modules\Variables\Enums\VariableType as WorkflowVariableType;
use App\Modules\Variables\Services\OperationExecutor as WorkflowOperationExecutor;
use App\Modules\Variables\Support\UnresolvedArgument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The shared operation executor extracted from the condition engine (SB1): the single 68-op runtime
 * both the condition engine and the variable resolver call. WorkflowConditionEngineTest already pins
 * the 66 condition ops (happy + fail-closed) THROUGH this executor, so these tests focus on the
 * executor's OWN contract — the OperationResult success/failure envelope, the value+type it carries,
 * the `op`/`operationId` key tolerance, the fail-closed edges, and the two choice terminals (SB-choice).
 */
class WorkflowOperationExecutorTest extends TestCase
{
    private WorkflowOperationExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->executor = new WorkflowOperationExecutor;
        Carbon::setTestNow('2026-07-14 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_empty_pipeline_returns_the_normalized_base_value_and_type(): void
    {
        $result = $this->executor->execute('42', WorkflowVariableType::NUMBER, []);

        $this->assertFalse($result->failed);
        $this->assertSame(42.0, $result->value);
        $this->assertSame(WorkflowVariableType::NUMBER, $result->type);
    }

    public function test_text_group_transform_carries_value_and_terminal_type(): void
    {
        $result = $this->executor->execute('  hi  ', WorkflowVariableType::TEXT, [
            ['op' => 'text_trim'],
            ['op' => 'text_uppercase'],
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame('HI', $result->value);
        $this->assertSame(WorkflowVariableType::TEXT, $result->type);
    }

    public function test_number_group_arithmetic_and_conversion(): void
    {
        $result = $this->executor->execute(21, WorkflowVariableType::NUMBER, [
            ['op' => 'num_multiply', 'args' => ['value' => 2]],
            ['op' => 'num_to_text'],
        ]);

        $this->assertSame('42', $result->value);
        $this->assertSame(WorkflowVariableType::TEXT, $result->type);
    }

    public function test_date_group_returns_a_carbon_immutable_terminal(): void
    {
        $result = $this->executor->execute('2026-01-10', WorkflowVariableType::DATE, [
            ['op' => 'date_add_days', 'args' => ['value' => 5]],
        ]);

        $this->assertFalse($result->failed);
        $this->assertInstanceOf(CarbonImmutable::class, $result->value);
        $this->assertSame('2026-01-15', $result->value->format('Y-m-d'));
        $this->assertSame(WorkflowVariableType::DATE, $result->type);
    }

    public function test_boolean_group_terminal(): void
    {
        $result = $this->executor->execute('', WorkflowVariableType::TEXT, [['op' => 'text_is_empty']]);

        $this->assertSame(true, $result->value);
        $this->assertSame(WorkflowVariableType::BOOLEAN, $result->type);
    }

    public function test_enum_group_mapping(): void
    {
        $result = $this->executor->execute('high', WorkflowVariableType::ENUM, [
            ['op' => 'enum_to_number', 'args' => ['mapping' => ['high' => 3, 'low' => 1]]],
        ]);

        $this->assertSame(3.0, $result->value);
        $this->assertSame(WorkflowVariableType::NUMBER, $result->type);
    }

    public function test_multi_group_join(): void
    {
        $result = $this->executor->execute(['a', 'b'], WorkflowVariableType::MULTI, [['op' => 'multi_to_text']]);

        $this->assertSame('a, b', $result->value);
        $this->assertSame(WorkflowVariableType::TEXT, $result->type);
    }

    // ── choice terminals (enum_to_choice / match_to_choice) ───────────────────

    public function test_enum_to_choice_maps_a_source_option_to_its_target_choice_string(): void
    {
        $result = $this->executor->execute('blog', WorkflowVariableType::ENUM, [
            ['op' => 'enum_to_choice', 'args' => ['mapping' => ['blog' => 'high', 'news' => 'low']]],
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame('high', $result->value);
        $this->assertSame(WorkflowVariableType::ENUM, $result->type);
    }

    public function test_enum_to_choice_fails_closed_on_an_unmapped_source_option(): void
    {
        $this->assertTrue($this->executor->execute('other', WorkflowVariableType::ENUM, [
            ['op' => 'enum_to_choice', 'args' => ['mapping' => ['blog' => 'high']]],
        ])->failed);
    }

    public function test_match_to_choice_returns_the_first_true_when_pipelines_then(): void
    {
        // A rule's `when` is now a BOOLEAN-terminal pipeline run over the op's TEXT input; the FIRST
        // rule whose `when` is true returns its `then`.
        $result = $this->executor->execute('BREAKING', WorkflowVariableType::TEXT, [
            ['op' => 'match_to_choice', 'args' => [
                'rules' => [
                    ['when' => [['op' => 'text_equals', 'args' => ['value' => 'BREAKING']]], 'then' => 'urgent'],
                    ['when' => [['op' => 'text_equals', 'args' => ['value' => 'note']]], 'then' => 'low'],
                ],
                'fallback' => 'medium',
            ]],
        ]);

        $this->assertSame('urgent', $result->value);
        $this->assertSame(WorkflowVariableType::ENUM, $result->type);
    }

    public function test_match_to_choice_supports_a_multi_step_when_pipeline(): void
    {
        // The `when` is a full pipeline, not just equality: TEXT → text_contains → boolean fires the rule.
        $result = $this->executor->execute('urgent bulletin', WorkflowVariableType::TEXT, [
            ['op' => 'match_to_choice', 'args' => [
                'rules' => [['when' => [['op' => 'text_contains', 'args' => ['value' => 'urgent']]], 'then' => 'high']],
                'fallback' => 'low',
            ]],
        ]);

        $this->assertSame('high', $result->value);
    }

    public function test_match_to_choice_returns_the_fallback_when_no_when_pipeline_is_true(): void
    {
        $result = $this->executor->execute('anything', WorkflowVariableType::TEXT, [
            ['op' => 'match_to_choice', 'args' => [
                'rules' => [['when' => [['op' => 'text_equals', 'args' => ['value' => 'BREAKING']]], 'then' => 'urgent']],
                'fallback' => 'medium',
            ]],
        ]);

        $this->assertSame('medium', $result->value);
        $this->assertSame(WorkflowVariableType::ENUM, $result->type);
    }

    public function test_match_to_choice_fails_closed_when_the_fallback_is_missing(): void
    {
        $this->assertTrue($this->executor->execute('x', WorkflowVariableType::TEXT, [
            ['op' => 'match_to_choice', 'args' => ['rules' => [['when' => [['op' => 'text_equals', 'args' => ['value' => 'x']]], 'then' => 'high']]]],
        ])->failed);
    }

    public function test_match_to_choice_fails_closed_when_a_when_pipeline_is_not_a_pipeline(): void
    {
        // The scalar-equality `when` is gone: a non-array `when` (e.g. a legacy scalar) is malformed and
        // fails the op CLOSED rather than being read as an equality string.
        $this->assertTrue($this->executor->execute('x', WorkflowVariableType::TEXT, [
            ['op' => 'match_to_choice', 'args' => ['rules' => [['when' => 'x', 'then' => 'high']], 'fallback' => 'low']],
        ])->failed);
    }

    public function test_match_to_choice_fails_closed_when_a_when_pipeline_does_not_end_in_a_boolean(): void
    {
        // A `when` that terminates in a non-boolean (here TEXT) is not a condition → fail CLOSED, never
        // falling through to the fallback (which could OPEN a gate).
        $this->assertTrue($this->executor->execute('x', WorkflowVariableType::TEXT, [
            ['op' => 'match_to_choice', 'args' => [
                'rules' => [['when' => [['op' => 'text_uppercase']], 'then' => 'high']],
                'fallback' => 'low',
            ]],
        ])->failed);
    }

    public function test_match_to_choice_fails_closed_when_a_when_pipeline_fails(): void
    {
        // A `when` sub-run that itself fails closed (a type-mismatched op) fails the whole op closed.
        $this->assertTrue($this->executor->execute('x', WorkflowVariableType::TEXT, [
            ['op' => 'match_to_choice', 'args' => [
                'rules' => [['when' => [['op' => 'num_gt', 'args' => ['value' => 1]]], 'then' => 'high']],
                'fallback' => 'low',
            ]],
        ])->failed);
    }

    public function test_match_to_choice_fails_closed_on_a_choice_op_inside_a_when_pipeline(): void
    {
        // DEFENCE IN DEPTH: a producesChoice op inside a `when` would let the sub-run re-enter the choice
        // machinery (unbounded recursion) on an invalid config the validator should have rejected. The
        // executor rejects it BEFORE running the sub-run (pipelineHasChoiceOp) → fail CLOSED.
        $this->assertTrue($this->executor->execute('x', WorkflowVariableType::TEXT, [
            ['op' => 'match_to_choice', 'args' => [
                'rules' => [['when' => [
                    ['op' => 'match_to_choice', 'args' => ['rules' => [], 'fallback' => 'y']],
                    ['op' => 'enum_is', 'args' => ['value' => 'y']],
                ], 'then' => 'high']],
                'fallback' => 'low',
            ]],
        ])->failed);
    }

    public function test_match_to_choice_fails_closed_when_a_matched_rules_then_is_non_scalar(): void
    {
        // A MATCHED rule whose `then` is non-scalar (only ever an UNRESOLVABLE per-rule variable — a
        // literal null is rejected at write time) fails the op CLOSED, symmetric with enumMap, never
        // falling through to the fallback.
        $this->assertTrue($this->executor->execute('BREAKING', WorkflowVariableType::TEXT, [
            ['op' => 'match_to_choice', 'args' => [
                'rules' => [['when' => [['op' => 'text_equals', 'args' => ['value' => 'BREAKING']]], 'then' => ['a', 'b']]],
                'fallback' => 'low',
            ]],
        ])->failed);
    }

    public function test_match_to_choice_fails_closed_on_a_non_array_rule(): void
    {
        // A malformed (non-array) rule fails the op CLOSED rather than being silently skipped (a silent
        // skip could reach the fallback and OPEN a gate).
        $this->assertTrue($this->executor->execute('x', WorkflowVariableType::TEXT, [
            ['op' => 'match_to_choice', 'args' => ['rules' => ['not-a-rule'], 'fallback' => 'low']],
        ])->failed);
    }

    // ── op / operationId key tolerance ────────────────────────────────────────

    public function test_reads_the_op_id_from_operationid_editor_key(): void
    {
        // The next editor's VariablePipelineStep carries `operationId` (+ stepId/outputType); the
        // executor reads either key so directive/if-block pipelines run without re-shaping.
        $result = $this->executor->execute('abc', WorkflowVariableType::TEXT, [
            ['stepId' => 's1', 'operationId' => 'text_uppercase', 'args' => [], 'outputType' => 'text'],
        ]);

        $this->assertSame('ABC', $result->value);
    }

    // ── fail-closed envelope ──────────────────────────────────────────────────

    public function test_unnormalizable_base_value_fails(): void
    {
        $result = $this->executor->execute('not-a-number', WorkflowVariableType::NUMBER, []);

        $this->assertTrue($result->failed);
        $this->assertNull($result->value);
        $this->assertNull($result->type);
    }

    public function test_unknown_op_fails(): void
    {
        $this->assertTrue($this->executor->execute('x', WorkflowVariableType::TEXT, [['op' => 'nope']])->failed);
    }

    public function test_type_mismatch_between_steps_fails(): void
    {
        // num_add expects a number but the running value is text.
        $this->assertTrue($this->executor->execute('x', WorkflowVariableType::TEXT, [['op' => 'num_add', 'args' => ['value' => 1]]])->failed);
    }

    public function test_divide_by_zero_fails(): void
    {
        $this->assertTrue($this->executor->execute(10, WorkflowVariableType::NUMBER, [['op' => 'num_divide', 'args' => ['value' => 0]]])->failed);
    }

    public function test_unmapped_enum_option_fails(): void
    {
        $this->assertTrue($this->executor->execute('other', WorkflowVariableType::ENUM, [
            ['op' => 'enum_to_text', 'args' => ['mapping' => ['high' => 'H']]],
        ])->failed);
    }

    public function test_over_long_pipeline_fails_closed(): void
    {
        $steps = array_fill(0, 11, ['op' => 'text_trim']);

        $this->assertTrue($this->executor->execute('x', WorkflowVariableType::TEXT, $steps)->failed);
    }

    public function test_non_array_step_fails(): void
    {
        $this->assertTrue($this->executor->execute('x', WorkflowVariableType::TEXT, ['not-a-step'])->failed);
    }

    // ── presence family (append-only, phase-1b) ───────────────────────────────

    public function test_coalesce_returns_the_input_when_present(): void
    {
        $result = $this->executor->execute('hello', WorkflowVariableType::TEXT, [
            ['op' => 'coalesce', 'args' => ['fallback' => 'fallback-text']],
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame('hello', $result->value);
        $this->assertSame(WorkflowVariableType::TEXT, $result->type);
    }

    public function test_coalesce_returns_the_fallback_for_an_empty_string(): void
    {
        $result = $this->executor->execute('', WorkflowVariableType::TEXT, [
            ['op' => 'coalesce', 'args' => ['fallback' => 'fallback-text']],
        ]);

        $this->assertSame('fallback-text', $result->value);
        $this->assertSame(WorkflowVariableType::TEXT, $result->type);
    }

    public function test_coalesce_substitutes_the_fallback_for_a_null_base(): void
    {
        // A null base normally fails a TEXT pipeline closed; a LEADING coalesce instead consumes the
        // emptiness (the deferred normalization failure) and returns the fallback.
        $result = $this->executor->execute(null, WorkflowVariableType::TEXT, [
            ['op' => 'coalesce', 'args' => ['fallback' => 'x']],
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame('x', $result->value);
    }

    public function test_coalesce_result_flows_into_a_downstream_op(): void
    {
        // The fallback is normalized to the running type, so a following text op runs on it.
        $result = $this->executor->execute('', WorkflowVariableType::TEXT, [
            ['op' => 'coalesce', 'args' => ['fallback' => 'hi']],
            ['op' => 'text_uppercase'],
        ]);

        $this->assertSame('HI', $result->value);
    }

    public function test_is_present_truthiness_including_empty_and_zero_edges(): void
    {
        $present = fn (mixed $v, WorkflowVariableType $t) => $this->executor
            ->execute($v, $t, [['op' => 'is_present']]);

        $this->assertSame(false, $present('', WorkflowVariableType::TEXT)->value);
        $this->assertSame(false, $present(null, WorkflowVariableType::TEXT)->value);
        $this->assertSame(true, $present('x', WorkflowVariableType::TEXT)->value);
        // Zero is PRESENT (not empty), as text '0' and as the number 0.
        $this->assertSame(true, $present('0', WorkflowVariableType::TEXT)->value);
        $this->assertSame(true, $present(0, WorkflowVariableType::NUMBER)->value);
        $this->assertSame(WorkflowVariableType::BOOLEAN, $present('x', WorkflowVariableType::TEXT)->type);
    }

    public function test_is_null_is_the_negation_of_is_present(): void
    {
        $isNull = fn (mixed $v, WorkflowVariableType $t) => $this->executor
            ->execute($v, $t, [['op' => 'is_null']]);

        $this->assertSame(true, $isNull('', WorkflowVariableType::TEXT)->value);
        $this->assertSame(true, $isNull(null, WorkflowVariableType::TEXT)->value);
        $this->assertSame(false, $isNull('x', WorkflowVariableType::TEXT)->value);
        $this->assertSame(WorkflowVariableType::BOOLEAN, $isNull('x', WorkflowVariableType::TEXT)->type);
    }

    public function test_presence_ops_accept_any_source_type(): void
    {
        // The presence ops bypass the per-step type gate — a DATE / MULTI source works without a cast.
        $this->assertSame(true, $this->executor->execute('2026-01-09', WorkflowVariableType::DATE, [['op' => 'is_present']])->value);
        // An empty MULTI ([]) is "empty"; a non-empty MULTI is present.
        $this->assertSame(true, $this->executor->execute([], WorkflowVariableType::MULTI, [['op' => 'is_null']])->value);
        $this->assertSame(true, $this->executor->execute(['a'], WorkflowVariableType::MULTI, [['op' => 'is_present']])->value);
    }

    public function test_assert_present_passes_a_present_value_through(): void
    {
        $result = $this->executor->execute('kept', WorkflowVariableType::TEXT, [['op' => 'assert_present']]);

        $this->assertFalse($result->failed);
        $this->assertSame('kept', $result->value);
        $this->assertSame(WorkflowVariableType::TEXT, $result->type);
    }

    public function test_assert_present_hard_fails_on_an_empty_value(): void
    {
        // The one opt-in HARD failure: still a failure (so conditions stay false), flagged `hard` so a
        // value-producing caller re-raises it. Never a thrown exception at the executor boundary.
        $empty = $this->executor->execute('', WorkflowVariableType::TEXT, [['op' => 'assert_present']]);
        $this->assertTrue($empty->failed);
        $this->assertTrue($empty->hard);

        $null = $this->executor->execute(null, WorkflowVariableType::TEXT, [['op' => 'assert_present']]);
        $this->assertTrue($null->failed);
        $this->assertTrue($null->hard);
    }

    public function test_a_normal_failure_is_not_hard(): void
    {
        $result = $this->executor->execute('not-a-number', WorkflowVariableType::NUMBER, []);

        $this->assertTrue($result->failed);
        $this->assertFalse($result->hard);
    }

    // ── date_format (append-only, phase-1b) ───────────────────────────────────

    public function test_date_format_renders_a_date_with_a_safe_token_pattern(): void
    {
        $result = $this->executor->execute('2026-01-09', WorkflowVariableType::DATE, [
            ['op' => 'date_format', 'args' => ['pattern' => 'DD/MM/YYYY']],
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame('09/01/2026', $result->value);
        $this->assertSame(WorkflowVariableType::TEXT, $result->type);
    }

    public function test_date_format_supports_month_name_and_unpadded_day_tokens(): void
    {
        $result = $this->executor->execute('2026-01-09', WorkflowVariableType::DATE, [
            ['op' => 'date_format', 'args' => ['pattern' => 'D MMMM YYYY']],
        ]);

        $this->assertSame('9 January 2026', $result->value);
    }

    public function test_date_format_time_tokens_render_zeroed_for_a_date_only_value(): void
    {
        // HH:mm exercises the ':' separator + zeroed wall-clock (dates parse at start-of-day).
        $result = $this->executor->execute('2026-01-09', WorkflowVariableType::DATE, [
            ['op' => 'date_format', 'args' => ['pattern' => 'HH:mm']],
        ]);

        $this->assertSame('00:00', $result->value);
    }

    public function test_date_format_fails_soft_on_an_unsafe_pattern(): void
    {
        // A raw PHP format string is NEVER honored — an off-whitelist byte fails soft (no throw).
        $result = $this->executor->execute('2026-01-09', WorkflowVariableType::DATE, [
            ['op' => 'date_format', 'args' => ['pattern' => 'Y-m-d h:i:s']],
        ]);

        $this->assertTrue($result->failed);
        $this->assertFalse($result->hard);
    }

    public function test_date_format_fails_soft_on_a_non_date_base(): void
    {
        // A non-date base fails at normalization, before date_format runs — never a throw.
        $result = $this->executor->execute('not-a-date', WorkflowVariableType::DATE, [
            ['op' => 'date_format', 'args' => ['pattern' => 'YYYY']],
        ]);

        $this->assertTrue($result->failed);
    }

    // ── argument variables: the executor stays a PURE transformer (phase-4a) ───

    public function test_a_literal_arg_is_used_verbatim_the_executor_never_resolves(): void
    {
        // The executor receives already-resolved LITERAL args (the resolver pre-resolves any variable
        // args BEFORE calling it). A plain literal arg behaves exactly as before — the back-compat
        // anchor for phase-4a: nothing about the executor's arg handling changed.
        $result = $this->executor->execute(10, WorkflowVariableType::NUMBER, [
            ['op' => 'num_add', 'args' => ['value' => 5]],
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame(15.0, $result->value);
    }

    public function test_a_variable_union_arg_reaching_the_executor_fails_closed(): void
    {
        // The executor holds NO context/resolver handle: if an UNRESOLVED variable-union leaks in as an
        // arg (the resolver is meant to pre-resolve it to a literal), the executor must fail closed —
        // never treat the union array as a value, never read context. Pins the pure-transformer boundary.
        $result = $this->executor->execute(10, WorkflowVariableType::NUMBER, [
            ['op' => 'num_add', 'args' => ['value' => [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.n', 'type' => 'number'],
            ]]],
        ]);

        $this->assertTrue($result->failed);
        $this->assertFalse($result->hard);
    }

    // ── structural/option args resolve to a malformed shape → fail-soft (phase-4b) ──
    //
    // The executor receives already-resolved args (the resolver pre-resolves a variable arg to a literal).
    // Since option/structural args may now be supplied by a variable, a resolved value of the WRONG shape
    // must fail SOFT at the pure-transformer boundary — never a crash / TypeError.

    public function test_enum_map_fails_soft_on_a_list_shaped_mapping_arg(): void
    {
        // A sourceMap variable that resolved to a LIST (not a {option: target} map): the source option is
        // not a key → FAIL, not a crash.
        $result = $this->executor->execute('high', WorkflowVariableType::ENUM, [
            ['op' => 'enum_to_text', 'args' => ['mapping' => ['a', 'b']]],
        ]);

        $this->assertTrue($result->failed);
        $this->assertFalse($result->hard);
    }

    public function test_enum_map_fails_soft_on_a_non_array_mapping_arg(): void
    {
        // A sourceMap variable that resolved to null/scalar (its ref was a non-array) → FAIL.
        foreach (['not-a-map', null, 42] as $mapping) {
            $result = $this->executor->execute('high', WorkflowVariableType::ENUM, [
                ['op' => 'enum_to_text', 'args' => ['mapping' => $mapping]],
            ]);

            $this->assertTrue($result->failed);
        }
    }

    public function test_match_to_choice_fails_soft_on_a_non_array_rules_arg(): void
    {
        // A choiceRules variable that resolved to a scalar (a malformed shape) → FAIL, never a type error.
        $result = $this->executor->execute('x', WorkflowVariableType::TEXT, [
            ['op' => 'match_to_choice', 'args' => ['rules' => 'not-a-list', 'fallback' => 'low']],
        ]);

        $this->assertTrue($result->failed);
    }

    public function test_match_to_choice_treats_a_null_rules_arg_as_no_rules(): void
    {
        // A LITERAL null `rules` — an author who wrote no rules — is treated as "no rules" → the required
        // fallback is returned, total over the input. Fail-soft, no crash. This is the ONE array-shaped
        // argument with an absent-value semantic, and it is deliberate (pinned by ADR-0022).
        //
        // It used to ALSO be what a structural arg-VARIABLE resolved to when its ref was missing / a
        // non-array — which made an unresolvable reference indistinguishable from an authored "no rules"
        // and OPENED condition gates. That case now travels as a distinct marker
        // (test_an_unresolvable_structural_arg_marker_is_rejected_by_every_array_shaped_reader); this
        // literal behaviour is unchanged.
        $result = $this->executor->execute('x', WorkflowVariableType::TEXT, [
            ['op' => 'match_to_choice', 'args' => ['rules' => null, 'fallback' => 'low']],
        ]);

        $this->assertSame('low', $result->value);
    }

    public function test_option_arg_fails_soft_when_it_resolved_to_a_non_scalar(): void
    {
        // A single-OPTION arg (enum_is `value`) that resolved to a non-scalar (a fail-soft null/array) →
        // withStringArg returns FAIL rather than casting an array to string.
        foreach ([null, ['a', 'b']] as $value) {
            $result = $this->executor->execute('high', WorkflowVariableType::ENUM, [
                ['op' => 'enum_is', 'args' => ['value' => $value]],
            ]);

            $this->assertTrue($result->failed);
        }
    }

    // ── totality: the executor never throws, for ANY declared type or argument (safety batch B0.5) ──

    public function test_a_base_type_with_no_runtime_semantics_fails_closed_instead_of_throwing(): void
    {
        // WorkflowVariableType carries DESCRIPTOR-ONLY cases (`time`, `object`) past the closed 7-type
        // core normalizeInput() dispatches on. That match had no default arm, so such a base type raised
        // an UnhandledMatchError out of an engine documented to NEVER throw. It is now an ordinary
        // failure result — the caller's own doctrine (a condition → false, a directive → '') applies.
        foreach ([WorkflowVariableType::TIME, WorkflowVariableType::OBJECT] as $type) {
            $result = $this->executor->execute('12:30', $type, []);

            $this->assertTrue($result->failed);
            $this->assertFalse($result->hard);
            $this->assertNull($result->value);
            $this->assertNull($result->type);
        }
    }

    public function test_an_unsupported_base_type_is_not_read_as_an_empty_value_by_the_presence_family(): void
    {
        // An unreadable TYPE is not an empty VALUE: the presence family must not answer boolean TRUE for
        // a base the engine cannot represent at all (that would OPEN a condition gate on a legacy row).
        foreach ([WorkflowVariableType::TIME, WorkflowVariableType::OBJECT] as $type) {
            $this->assertTrue($this->executor->execute('12:30', $type, [['op' => 'is_null']])->failed);
            $this->assertTrue($this->executor->execute('12:30', $type, [['op' => 'is_present']])->failed);
            $this->assertTrue($this->executor->execute(null, $type, [['op' => 'coalesce', 'args' => ['fallback' => 'x']]])->failed);
        }

        // Unchanged: an unrepresentable VALUE of a SUPPORTED type still reads as empty (the deferred
        // fail-closed return the presence family opted out of).
        $isNull = $this->executor->execute('not-a-number', WorkflowVariableType::NUMBER, [['op' => 'is_null']]);

        $this->assertFalse($isNull->failed);
        $this->assertTrue($isNull->value);
    }

    public function test_an_array_shaped_arg_that_is_really_a_variable_union_is_rejected_not_consumed(): void
    {
        // The array-shaped readers (values / mapping / rules) checked only is_array — and a union IS an
        // array — so they consumed the union's own keys/values as data. They now reject it, exactly like
        // the scalar readers next to them (test_a_variable_union_arg_reaching_the_executor_fails_closed).
        $union = ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.x', 'type' => 'multi']];

        // enum_in used to read the union's `kind` VALUE ("variable") as a candidate option.
        $this->assertTrue($this->executor->execute('variable', WorkflowVariableType::ENUM, [
            ['op' => 'enum_in', 'args' => ['values' => $union]],
        ])->failed);

        // enum_to_* used to read the union's own KEYS as the option map.
        $this->assertTrue($this->executor->execute('kind', WorkflowVariableType::ENUM, [
            ['op' => 'enum_to_text', 'args' => ['mapping' => $union]],
        ])->failed);

        // multi_includes_any/all shared the option-list reader.
        foreach (['multi_includes_any', 'multi_includes_all'] as $op) {
            $this->assertTrue($this->executor->execute(['variable'], WorkflowVariableType::MULTI, [
                ['op' => $op, 'args' => ['values' => $union]],
            ])->failed);
        }

        // match_to_choice used to SUCCEED on union rules by falling through to its fallback.
        $this->assertTrue($this->executor->execute('x', WorkflowVariableType::TEXT, [
            ['op' => 'match_to_choice', 'args' => ['rules' => $union, 'fallback' => 'low']],
        ])->failed);
    }

    public function test_an_unresolvable_structural_arg_marker_is_rejected_by_every_array_shaped_reader(): void
    {
        // The SECOND guard on the one array-shaped reader (see UnresolvedArgument): a STRUCTURAL
        // arg-variable (sourceMap / choiceRules) that resolved to NOTHING arrives as a distinct marker
        // instead of null, because `$args[$key] ?? $whenAbsent` cannot tell null from absent — and
        // match_to_choice's absent-`rules` semantic then turned an unresolvable reference into "no
        // rules → return the fallback", i.e. a SUCCESS that opened condition gates.
        $marker = UnresolvedArgument::MARKER;

        $this->assertTrue($this->executor->execute('high', WorkflowVariableType::ENUM, [
            ['op' => 'enum_in', 'args' => ['values' => $marker]],
        ])->failed);

        foreach (['enum_to_text', 'enum_to_number', 'enum_to_date', 'enum_to_choice'] as $op) {
            $this->assertTrue($this->executor->execute('high', WorkflowVariableType::ENUM, [
                ['op' => $op, 'args' => ['mapping' => $marker]],
            ])->failed, $op . ' must reject the unresolvable-argument marker');
        }

        foreach (['multi_includes_any', 'multi_includes_all'] as $op) {
            $this->assertTrue($this->executor->execute(['high'], WorkflowVariableType::MULTI, [
                ['op' => $op, 'args' => ['values' => $marker]],
            ])->failed, $op . ' must reject the unresolvable-argument marker');
        }

        // The reader whose $whenAbsent made the marker necessary in the first place.
        $this->assertTrue($this->executor->execute('x', WorkflowVariableType::TEXT, [
            ['op' => 'match_to_choice', 'args' => ['rules' => $marker, 'fallback' => 'low']],
        ])->failed);
    }

    // ── ARRAY TRANSFORMS (wave 1): count + at over a MULTI value ───────────────

    public function test_array_count_yields_the_number_length_of_a_multi_value(): void
    {
        $result = $this->executor->execute(['a', 'b', 'c'], WorkflowVariableType::MULTI, [
            ['op' => 'array_count'],
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame(3.0, $result->value);
        $this->assertSame(WorkflowVariableType::NUMBER, $result->type);
    }

    public function test_array_count_of_an_empty_multi_is_zero(): void
    {
        $result = $this->executor->execute([], WorkflowVariableType::MULTI, [['op' => 'array_count']]);

        $this->assertFalse($result->failed);
        $this->assertSame(0.0, $result->value);
    }

    public function test_array_count_chains_into_the_number_vocabulary(): void
    {
        // count -> number terminal is usable by the whole num_* vocabulary (a condition can gate on it).
        $result = $this->executor->execute(['a', 'b'], WorkflowVariableType::MULTI, [
            ['op' => 'array_count'],
            ['op' => 'num_gt', 'args' => ['value' => 1]],
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame(true, $result->value);
        $this->assertSame(WorkflowVariableType::BOOLEAN, $result->type);
    }

    /**
     * The `at` 1-based signed CLAMP matrix — the single pinned contract for empty/first/in-range/
     * positive-overflow/negative-from-end/negative-overflow.
     *
     * @dataProvider arrayAtClampCases
     */
    public function test_array_at_clamps_a_signed_1_based_index(array $list, int $index, ?string $expected): void
    {
        $result = $this->executor->execute($list, WorkflowVariableType::MULTI, [
            ['op' => 'array_at', 'args' => ['index' => $index]],
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame($expected, $result->value);
        // array_at over a MULTI leaves the running type at the array's ELEMENT base — ENUM, not the
        // static TEXT — so it AGREES with the write-validator/FE walker (Finding A). The element VALUE is
        // still the raw string (or null); only the terminal TYPE tracks the descriptor.
        $this->assertSame(WorkflowVariableType::ENUM, $result->type);
    }

    public static function arrayAtClampCases(): array
    {
        $list = ['a', 'b', 'c'];

        return [
            'empty array -> null' => [[], 1, null],
            'index 0 -> first' => [$list, 0, 'a'],
            'first (1-based)' => [$list, 1, 'a'],
            'in range' => [$list, 2, 'b'],
            'last (1-based)' => [$list, 3, 'c'],
            'positive overflow -> last' => [$list, 99, 'c'],
            'negative -1 -> last' => [$list, -1, 'c'],
            'negative from end' => [$list, -2, 'b'],
            'negative reaches first' => [$list, -3, 'a'],
            'negative overflow -> first' => [$list, -99, 'a'],
        ];
    }

    public function test_array_at_without_a_numeric_index_fails_closed(): void
    {
        // The index is required (a signed integer); a missing / non-numeric one fails closed, never throws.
        $this->assertTrue($this->executor->execute(['a', 'b'], WorkflowVariableType::MULTI, [
            ['op' => 'array_at'],
        ])->failed);

        $this->assertTrue($this->executor->execute(['a', 'b'], WorkflowVariableType::MULTI, [
            ['op' => 'array_at', 'args' => ['index' => 'not-a-number']],
        ])->failed);
    }

    // ── array_at REQUIRED typed default (F4) ───────────────────────────────────

    public function test_array_at_substitutes_a_typed_default_for_an_absent_element(): void
    {
        // An EMPTY array with a valid typed default → the NORMALIZED default (not null), so a following op
        // sees a real value. The default's type is the element base (enum → a string here).
        $result = $this->executor->execute([], WorkflowVariableType::MULTI, [
            ['op' => 'array_at', 'args' => ['index' => 1, 'default' => ['type' => 'enum', 'value' => 'none']]],
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame('none', $result->value);
    }

    public function test_array_at_ignores_the_default_when_the_element_is_present(): void
    {
        // A present element WINS — the default only substitutes a null/absent element.
        $result = $this->executor->execute(['a', 'b'], WorkflowVariableType::MULTI, [
            ['op' => 'array_at', 'args' => ['index' => 1, 'default' => ['type' => 'enum', 'value' => 'z']]],
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame('a', $result->value);
    }

    public function test_array_at_default_flows_into_a_following_op(): void
    {
        // The whole point of F4: `array_at |> enum_is` over an EMPTY array reads the DEFAULT, so the gate is
        // EVALUABLE (true here) instead of the following op consuming a null it could not compare.
        $result = $this->executor->execute([], WorkflowVariableType::MULTI, [
            ['op' => 'array_at', 'args' => ['index' => 1, 'default' => ['type' => 'enum', 'value' => 'fb']]],
            ['op' => 'enum_is', 'args' => ['value' => 'fb']],
        ]);

        $this->assertFalse($result->failed);
        $this->assertTrue($result->value);
        $this->assertSame(WorkflowVariableType::BOOLEAN, $result->type);
    }

    public function test_array_at_default_normalizes_a_number_element(): void
    {
        // The default is normalized to its declared base (reduceSeed-style): a number default over an empty
        // array yields a float, chained into the number vocabulary.
        $result = $this->executor->execute([], WorkflowVariableType::MULTI, [
            ['op' => 'array_at', 'args' => ['index' => 1, 'default' => ['type' => 'number', 'value' => 7]]],
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame(7.0, $result->value);
    }

    public function test_array_at_does_not_substitute_a_blank_default(): void
    {
        // A BLANK ('') default is "not entered" (owner directive: an empty string is not a value) → NO
        // substitution: the raw null stays (a legal nullable terminal), so a following op fail-closes exactly
        // as without a default. This keeps runtime consistent with the write validator rejecting a blank one.
        $result = $this->executor->execute([], WorkflowVariableType::MULTI, [
            ['op' => 'array_at', 'args' => ['index' => 1, 'default' => ['type' => 'text', 'value' => '']]],
        ]);

        $this->assertFalse($result->failed);
        $this->assertNull($result->value);
    }

    public function test_array_at_substitutes_a_zero_or_false_default(): void
    {
        // Number 0 / boolean false ARE meaningful values (only '' and null are "not entered") — they
        // substitute for an absent element normally.
        $zero = $this->executor->execute([], WorkflowVariableType::MULTI, [
            ['op' => 'array_at', 'args' => ['index' => 1, 'default' => ['type' => 'number', 'value' => 0]]],
        ]);
        $this->assertFalse($zero->failed);
        $this->assertSame(0.0, $zero->value);

        $false = $this->executor->execute([], WorkflowVariableType::MULTI, [
            ['op' => 'array_at', 'args' => ['index' => 1, 'default' => ['type' => 'boolean', 'value' => false]]],
        ]);
        $this->assertFalse($false->failed);
        $this->assertSame(false, $false->value);
    }

    // ── ARRAY TRANSFORMS (wave 2): map / filter / sort / reduce ────────────────

    /** A per-element pipeline step. */
    private function op(string $id, array $args = []): array
    {
        return ['op' => $id, 'args' => $args];
    }

    /** A scoped element/index reference (the wire shape an element-pipeline arg carries). */
    private function scope(string $leaf, string $type = 'number'): array
    {
        return ['kind' => 'variable', 'ref' => ['source' => 'scope', 'path' => $leaf, 'type' => $type]];
    }

    public function test_map_collects_the_element_terminals_into_an_array(): void
    {
        // element type recovered from the first op (num_add → number); each element +10.
        $result = $this->executor->execute(['1', '2', '3'], WorkflowVariableType::MULTI, [
            $this->op('array_map', ['pipeline' => [$this->op('num_add', ['value' => 10])]]),
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame([11.0, 12.0, 13.0], $result->value);
        $this->assertSame(WorkflowVariableType::MULTI, $result->type);
    }

    public function test_map_fails_closed_when_one_element_pipeline_fails(): void
    {
        // A single un-convertible element ('x') fails the WHOLE map closed — output length must match.
        $result = $this->executor->execute(['1', 'x', '3'], WorkflowVariableType::MULTI, [
            $this->op('array_map', ['pipeline' => [$this->op('text_to_number')]]),
        ]);

        $this->assertTrue($result->failed);
    }

    public function test_filter_keeps_only_boolean_true_elements(): void
    {
        // Keep the ORIGINAL element where its pipeline terminates boolean true (> 2).
        $result = $this->executor->execute(['1', '2', '3', '4'], WorkflowVariableType::MULTI, [
            $this->op('array_filter', ['pipeline' => [$this->op('num_gt', ['value' => 2])]]),
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame(['3', '4'], $result->value);
        $this->assertSame(WorkflowVariableType::MULTI, $result->type);
    }

    public function test_filter_drops_an_element_whose_pipeline_fails_closed(): void
    {
        // 'x' fails text_to_number → the sub-run FAILs → the element is DROPPED (never kept on failure).
        $result = $this->executor->execute(['1', 'x', '3'], WorkflowVariableType::MULTI, [
            $this->op('array_filter', ['pipeline' => [
                $this->op('text_to_number'),
                $this->op('num_gt', ['value' => 0]),
            ]]),
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame(['1', '3'], $result->value);
    }

    public function test_filter_feeding_a_condition_gate_defaults_false_when_unresolved(): void
    {
        // Every element fails its predicate → filter yields [] → count 0 → 0 > 0 is FALSE. A fail-closed
        // filter can NEVER open a downstream gate.
        $result = $this->executor->execute(['x', 'y'], WorkflowVariableType::MULTI, [
            $this->op('array_filter', ['pipeline' => [
                $this->op('text_to_number'),
                $this->op('num_gt', ['value' => 0]),
            ]]),
            $this->op('array_count'),
            $this->op('num_gt', ['value' => 0]),
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame(false, $result->value);
        $this->assertSame(WorkflowVariableType::BOOLEAN, $result->type);
    }

    public function test_sort_orders_ascending_by_the_numeric_key(): void
    {
        $result = $this->executor->execute(['3', '1', '2'], WorkflowVariableType::MULTI, [
            $this->op('array_sort', ['pipeline' => [$this->op('text_to_number')]]),
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame(['1', '2', '3'], $result->value);
    }

    public function test_sort_sends_failed_keys_last_stably(): void
    {
        // 'x'/'y' fail text_to_number → null key → sorted LAST, keeping their original relative order.
        $result = $this->executor->execute(['3', 'x', '1', 'y'], WorkflowVariableType::MULTI, [
            $this->op('array_sort', ['pipeline' => [$this->op('text_to_number')]]),
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame(['1', '3', 'x', 'y'], $result->value);
    }

    public function test_reduce_folds_the_seed_across_the_elements_using_the_scoped_element(): void
    {
        // sum: seed 0, reducer acc + element (element resolved per iteration from the scope overlay).
        $result = $this->executor->execute(['1', '2', '3', '4'], WorkflowVariableType::MULTI, [
            $this->op('array_reduce', [
                'seed' => ['type' => 'number', 'value' => 0],
                'reducer' => [$this->op('num_add', ['value' => $this->scope('element')])],
            ]),
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame(10.0, $result->value);
        $this->assertSame(WorkflowVariableType::NUMBER, $result->type);
    }

    public function test_reduce_can_fold_over_the_scoped_1_based_index(): void
    {
        // sum of 1-based indices over 4 elements = 1+2+3+4 = 10.
        $result = $this->executor->execute(['a', 'b', 'c', 'd'], WorkflowVariableType::MULTI, [
            $this->op('array_reduce', [
                'seed' => ['type' => 'number', 'value' => 0],
                'reducer' => [$this->op('num_add', ['value' => $this->scope('index')])],
            ]),
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame(10.0, $result->value);
    }

    public function test_reduce_keeps_the_prior_accumulator_when_an_element_sub_run_fails(): void
    {
        // 'x' → num_add over a non-numeric element FAILs → the accumulator is UNCHANGED (fail-closed).
        $result = $this->executor->execute(['1', 'x', '3'], WorkflowVariableType::MULTI, [
            $this->op('array_reduce', [
                'seed' => ['type' => 'number', 'value' => 0],
                'reducer' => [$this->op('num_add', ['value' => $this->scope('element')])],
            ]),
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame(4.0, $result->value); // 0 + 1 + (skip x) + 3
    }

    public function test_reduce_keeps_the_seed_when_the_reducer_terminal_is_not_the_seed_type(): void
    {
        // A reducer whose terminal (text) is not the seed base (number) never folds → the accumulator
        // stays the seed. No type corruption ever reaches the accumulator.
        $result = $this->executor->execute(['1', '2'], WorkflowVariableType::MULTI, [
            $this->op('array_reduce', [
                'seed' => ['type' => 'number', 'value' => 7],
                'reducer' => [$this->op('num_to_text')],
            ]),
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame(7.0, $result->value);
    }

    public function test_reduce_with_a_malformed_seed_fails_closed(): void
    {
        $this->assertTrue($this->executor->execute(['1'], WorkflowVariableType::MULTI, [
            $this->op('array_reduce', [
                'seed' => ['type' => 'multi', 'value' => 'x'], // not a base literal type
                'reducer' => [$this->op('num_add', ['value' => 1])],
            ]),
        ])->failed);
    }

    public function test_element_and_index_are_unreferenceable_outside_an_element_pipeline(): void
    {
        // A scope reference in an ORDINARY (non-element) pipeline is a residual, unresolvable arg — the
        // op fails CLOSED (never reads an element/index that is not in scope). Proves no scope leak.
        $result = $this->executor->execute('5', WorkflowVariableType::NUMBER, [
            $this->op('num_gt', ['value' => $this->scope('element')]),
        ]);

        $this->assertTrue($result->failed);
    }

    public function test_more_than_the_iteration_cap_fails_the_op_closed(): void
    {
        $tooMany = array_fill(0, 1001, '1');
        $this->assertTrue($this->executor->execute($tooMany, WorkflowVariableType::MULTI, [
            $this->op('array_map', ['pipeline' => [$this->op('num_add', ['value' => 1])]]),
        ])->failed);

        $atCap = array_fill(0, 1000, '1');
        $this->assertFalse($this->executor->execute($atCap, WorkflowVariableType::MULTI, [
            $this->op('array_count'),
            $this->op('num_gte', ['value' => 1000]),
        ])->failed);
    }

    public function test_element_pipelines_nested_beyond_the_depth_cap_fail_closed(): void
    {
        // A normalized MULTI wraps a scalar into a 1-element list, so nested maps DO recurse — the guard
        // is the only thing that stops them. Three levels succeed; a fourth fails closed.
        $mapOf = fn (array $inner): array => [$this->op('array_map', ['pipeline' => $inner])];

        $threeDeep = $mapOf($mapOf([$this->op('text_uppercase')]));
        $ok = $this->executor->execute(['a'], WorkflowVariableType::MULTI, $mapOf($threeDeep));
        $this->assertFalse($ok->failed);
        $this->assertSame([[['A']]], $ok->value);

        $fourDeep = $mapOf($mapOf($mapOf([$this->op('text_uppercase')])));
        $this->assertTrue($this->executor->execute(['a'], WorkflowVariableType::MULTI, $mapOf($fourDeep))->failed);
    }

    // ── Finding A: array_at's runtime terminal type agrees with the walker/FE ──

    public function test_array_at_over_a_multi_chains_into_an_enum_op_at_runtime(): void
    {
        // The DIRECT `multi |> array_at |> enum_is` case. array_at now leaves the running type at the input
        // array's ELEMENT base (ENUM for a MULTI), AGREEING with the write-validator/FE walker (which types
        // `at` as the element descriptor). Before Finding A the runtime used the STATIC outputType() (TEXT),
        // so the following ENUM op met a TEXT-typed value and silently failed closed even though the write
        // side had accepted it (see WorkflowConditionTreeValidationTest — this same shape VALIDATES).
        $result = $this->executor->execute(['fb', 'ig', 'tw'], WorkflowVariableType::MULTI, [
            ['op' => 'array_at', 'args' => ['index' => 2]], // 1-based → 'ig'
            ['op' => 'enum_is', 'args' => ['value' => 'ig']],
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame(true, $result->value);
        $this->assertSame(WorkflowVariableType::BOOLEAN, $result->type);
    }

    public function test_map_produced_scalar_array_stays_enum_typed_after_at_so_a_text_op_fails_closed(): void
    {
        // The KNOWN, DOCUMENTED limitation (deferred, honest fail-CLOSED): the executor collapses every
        // array to a normalized MULTI/string[] and cannot recover a NON-enum scalar element base produced
        // MID-PIPELINE by map (here map(num_to_text) → array<text>). The walker types `at` here as TEXT, so
        // text_equals VALIDATES — but at RUNTIME `at` types ENUM (the MULTI element base) and the TEXT op
        // meets an ENUM-typed value → fail CLOSED (safe). The enum/text gate is deliberately NOT relaxed to
        // paper over this; only the DIRECT multi-source case above is made correct.
        $result = $this->executor->execute(['1', '2', '3'], WorkflowVariableType::MULTI, [
            $this->op('array_map', ['pipeline' => [$this->op('num_to_text')]]),
            $this->op('array_at', ['index' => 1]),
            $this->op('text_equals', ['value' => '1']),
        ]);

        $this->assertTrue($result->failed);
    }

    public function test_filter_predicate_failing_on_data_drops_elements_and_the_empty_gate_stays_closed(): void
    {
        // The accepted RESIDUAL (fail-closed doctrine): a correctly-typed filter whose per-element predicate
        // FAILs on specific element DATA drops those elements. Every element here is non-numeric, so num_gt
        // fails per element → ALL dropped → filter yields [] → multi_is_empty is TRUE. Feeding an EXCLUSION
        // gate (is_empty ⇒ "exclude"), an unevaluable predicate can never KEEP an element it could not
        // evaluate — the failure resolves to exclusion, never to a wrongly-included element.
        $result = $this->executor->execute(['x', 'y'], WorkflowVariableType::MULTI, [
            $this->op('array_filter', ['pipeline' => [$this->op('num_gt', ['value' => 0])]]),
            $this->op('multi_is_empty'),
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame(true, $result->value);
        $this->assertSame(WorkflowVariableType::BOOLEAN, $result->type);
    }

    // ── array<object> (repeater) + array<file> element access (wave 3) ─────────

    /** A scope element-SUBFIELD reference (`element.<field>`), the wire an object/file element pipeline uses. */
    private function scopeSub(string $path, string $type): array
    {
        return ['kind' => 'variable', 'ref' => ['source' => 'scope', 'path' => $path, 'type' => $type]];
    }

    /** A map/filter/sort element pipeline ROOTED at a scope subfield: the union `{ref, pipeline}`. */
    private function elementUnion(string $path, string $type, array $steps = []): array
    {
        return ['kind' => 'variable', 'ref' => ['source' => 'scope', 'path' => $path, 'type' => $type], 'pipeline' => $steps];
    }

    public function test_filter_a_repeater_by_an_element_subfield_keeps_and_drops_rows(): void
    {
        // "keep repeater rows where element.price > 100": the element pipeline is a UNION rooted at the
        // object subfield element.price, transformed to a boolean. The ORIGINAL rows are kept/dropped.
        $rows = [['price' => 150], ['price' => 50], ['price' => 200]];

        $result = $this->executor->execute($rows, WorkflowVariableType::MULTI, [
            $this->op('array_filter', ['pipeline' => $this->elementUnion('element.price', 'number', [
                $this->op('num_gt', ['value' => 100]),
            ])]),
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame([['price' => 150], ['price' => 200]], $result->value);
    }

    public function test_map_a_repeater_to_an_element_subfield_yields_an_array_of_that_field(): void
    {
        // "map each row to element.name": a union rooted at element.name with an empty transform.
        $rows = [['name' => 'Ann'], ['name' => 'Bob']];

        $result = $this->executor->execute($rows, WorkflowVariableType::MULTI, [
            $this->op('array_map', ['pipeline' => $this->elementUnion('element.name', 'text')]),
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame(['Ann', 'Bob'], $result->value);
        $this->assertSame(WorkflowVariableType::MULTI, $result->type);
    }

    public function test_sort_a_repeater_by_a_numeric_element_subfield(): void
    {
        $rows = [['qty' => 3], ['qty' => 1], ['qty' => 2]];

        $result = $this->executor->execute($rows, WorkflowVariableType::MULTI, [
            $this->op('array_sort', ['pipeline' => $this->elementUnion('element.qty', 'number')]),
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame([['qty' => 1], ['qty' => 2], ['qty' => 3]], $result->value);
    }

    public function test_reduce_a_repeater_folding_an_element_subfield(): void
    {
        // sum of element.price over the rows — the reducer roots at the seed (number) and reads the object
        // subfield element.price as a scope ARG-variable per iteration.
        $rows = [['price' => 10], ['price' => 20], ['price' => 5]];

        $result = $this->executor->execute($rows, WorkflowVariableType::MULTI, [
            $this->op('array_reduce', [
                'seed' => ['type' => 'number', 'value' => 0],
                'reducer' => [$this->op('num_add', ['value' => $this->scopeSub('element.price', 'number')])],
            ]),
        ]);

        $this->assertFalse($result->failed);
        $this->assertSame(35.0, $result->value);
        $this->assertSame(WorkflowVariableType::NUMBER, $result->type);
    }

    public function test_map_a_file_element_array_by_name_and_by_mime_aliased_type(): void
    {
        // array<file>: element.name is identity; element.type ALIASES the snapshot's mime_type.
        $files = [
            ['id' => '1', 'name' => 'a.pdf', 'mime_type' => 'application/pdf'],
            ['id' => '2', 'name' => 'b.png', 'mime_type' => 'image/png'],
        ];

        $names = $this->executor->execute($files, WorkflowVariableType::MULTI, [
            $this->op('array_map', ['pipeline' => $this->elementUnion('element.name', 'text')]),
        ]);
        $this->assertFalse($names->failed);
        $this->assertSame(['a.pdf', 'b.png'], $names->value);

        $types = $this->executor->execute($files, WorkflowVariableType::MULTI, [
            $this->op('array_map', ['pipeline' => $this->elementUnion('element.type', 'text')]),
        ]);
        $this->assertFalse($types->failed);
        $this->assertSame(['application/pdf', 'image/png'], $types->value);
    }

    public function test_an_element_subfield_is_unreferenceable_outside_an_element_pipeline(): void
    {
        // A scope element.<subfield> reference in an ORDINARY pipeline is a residual, unresolvable arg —
        // the op fails CLOSED (fail-soft null), never leaking a loop element's subfield. No scope leak.
        $result = $this->executor->execute('5', WorkflowVariableType::NUMBER, [
            $this->op('num_gt', ['value' => $this->scopeSub('element.price', 'number')]),
        ]);

        $this->assertTrue($result->failed);
    }

    public function test_at_over_a_repeater_returns_the_object_snapshot_and_null_on_empty(): void
    {
        $rows = [['name' => 'Ann', 'price' => 10], ['name' => 'Bob', 'price' => 20]];

        $first = $this->executor->execute($rows, WorkflowVariableType::MULTI, [
            $this->op('array_at', ['index' => 1]),
        ]);
        $this->assertFalse($first->failed);
        $this->assertSame(['name' => 'Ann', 'price' => 10], $first->value);

        $empty = $this->executor->execute([], WorkflowVariableType::MULTI, [
            $this->op('array_at', ['index' => 1]),
        ]);
        $this->assertFalse($empty->failed);
        $this->assertNull($empty->value);
    }
}
