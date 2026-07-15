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
}
