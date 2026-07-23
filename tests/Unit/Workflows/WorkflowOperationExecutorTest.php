<?php

namespace Tests\Unit\Workflows;

use App\Modules\Workflows\Enums\WorkflowVariableType;
use App\Modules\Workflows\Services\WorkflowOperationExecutor;
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

    public function test_match_to_choice_returns_the_first_matching_rules_then(): void
    {
        $result = $this->executor->execute('BREAKING', WorkflowVariableType::TEXT, [
            ['op' => 'match_to_choice', 'args' => [
                'rules' => [['when' => 'BREAKING', 'then' => 'urgent'], ['when' => 'note', 'then' => 'low']],
                'fallback' => 'medium',
            ]],
        ]);

        $this->assertSame('urgent', $result->value);
        $this->assertSame(WorkflowVariableType::ENUM, $result->type);
    }

    public function test_match_to_choice_returns_the_fallback_when_no_rule_matches(): void
    {
        $result = $this->executor->execute('anything', WorkflowVariableType::TEXT, [
            ['op' => 'match_to_choice', 'args' => [
                'rules' => [['when' => 'BREAKING', 'then' => 'urgent']],
                'fallback' => 'medium',
            ]],
        ]);

        $this->assertSame('medium', $result->value);
        $this->assertSame(WorkflowVariableType::ENUM, $result->type);
    }

    public function test_match_to_choice_fails_closed_when_the_fallback_is_missing(): void
    {
        $this->assertTrue($this->executor->execute('x', WorkflowVariableType::TEXT, [
            ['op' => 'match_to_choice', 'args' => ['rules' => [['when' => 'x', 'then' => 'high']]]],
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
        // A null `rules` (what a structural variable resolves to when its ref is a non-array / missing) is
        // treated as "no rules" → the fallback is returned. Fail-soft, no crash.
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
}
