<?php

namespace Tests\Unit\Workflows;

use App\Modules\Workflows\Services\WorkflowConditionEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * The TYPED condition gate: a flat list of {field, field_type, operator, value} clauses,
 * AND-combined over the payload via dotted paths. These tests pin each type's operators (happy
 * + failing), the missing-path rule (fails except the absence operators), invalid-date safety
 * (fails, never throws), AND combination, and fail-closed on unknown type/operator.
 */
class WorkflowConditionEvaluatorTest extends TestCase
{
    private WorkflowConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new WorkflowConditionEvaluator;
    }

    private function payload(): array
    {
        return [
            'fields' => [
                'priority' => 'high',
                'score' => 42,
                'agree' => true,
                'due' => '2026-06-15',
                'category' => 'blog',
                'tags' => ['a', 'b'],
                'title' => 'Weekly report',
                'bad_date' => 'not-a-date',
                // A file field carries a snapshot list (empty when unanswered).
                'attachment' => [['id' => 'f1', 'name' => 'raport.pdf', 'mime_type' => 'application/pdf', 'size' => 10]],
                'no_attachment' => [],
            ],
        ];
    }

    /** One clause helper. */
    private function passes(string $field, string $type, string $operator, mixed $value = null): bool
    {
        return $this->evaluator->passes(
            [['field' => $field, 'field_type' => $type, 'operator' => $operator, 'value' => $value]],
            $this->payload(),
        );
    }

    public function test_empty_or_absent_conditions_pass(): void
    {
        $this->assertTrue($this->evaluator->passes(null, $this->payload()));
        $this->assertTrue($this->evaluator->passes([], $this->payload()));
    }

    // ---- text -----------------------------------------------------------------

    public function test_text_operators(): void
    {
        $this->assertTrue($this->passes('fields.priority', 'text', 'equals', 'high'));
        $this->assertFalse($this->passes('fields.priority', 'text', 'equals', 'low'));
        $this->assertTrue($this->passes('fields.priority', 'text', 'not_equals', 'low'));
        $this->assertTrue($this->passes('fields.title', 'text', 'contains', 'report'));
        $this->assertFalse($this->passes('fields.title', 'text', 'contains', 'invoice'));
    }

    // ---- number ---------------------------------------------------------------

    public function test_number_operators(): void
    {
        $this->assertTrue($this->passes('fields.score', 'number', 'eq', 42));
        $this->assertTrue($this->passes('fields.score', 'number', 'neq', 7));
        $this->assertTrue($this->passes('fields.score', 'number', 'gt', 10));
        $this->assertTrue($this->passes('fields.score', 'number', 'gte', 42));
        $this->assertTrue($this->passes('fields.score', 'number', 'lt', 100));
        $this->assertTrue($this->passes('fields.score', 'number', 'lte', 42));
        $this->assertFalse($this->passes('fields.score', 'number', 'gt', 100));
    }

    public function test_number_non_numeric_value_fails(): void
    {
        $this->assertFalse($this->passes('fields.priority', 'number', 'gt', 5));
    }

    // ---- date -----------------------------------------------------------------

    public function test_date_operators(): void
    {
        $this->assertTrue($this->passes('fields.due', 'date', 'before', '2026-07-01'));
        $this->assertTrue($this->passes('fields.due', 'date', 'after', '2026-01-01'));
        $this->assertTrue($this->passes('fields.due', 'date', 'on', '2026-06-15'));
        $this->assertFalse($this->passes('fields.due', 'date', 'on', '2026-06-16'));
    }

    public function test_date_between_requires_two_element_array(): void
    {
        $this->assertTrue($this->passes('fields.due', 'date', 'between', ['2026-06-01', '2026-06-30']));
        $this->assertFalse($this->passes('fields.due', 'date', 'between', ['2026-07-01', '2026-07-30']));
        // Malformed between value fails, never throws.
        $this->assertFalse($this->passes('fields.due', 'date', 'between', ['2026-06-01']));
    }

    public function test_invalid_stored_date_value_fails_and_does_not_throw(): void
    {
        $this->assertFalse($this->passes('fields.bad_date', 'date', 'before', '2026-07-01'));
    }

    // ---- enum -----------------------------------------------------------------

    public function test_enum_operators(): void
    {
        $this->assertTrue($this->passes('fields.category', 'enum', 'is', 'blog'));
        $this->assertTrue($this->passes('fields.category', 'enum', 'is_not', 'news'));
        $this->assertTrue($this->passes('fields.category', 'enum', 'in', ['blog', 'news']));
        $this->assertFalse($this->passes('fields.category', 'enum', 'in', ['news', 'press']));
    }

    // ---- multi ----------------------------------------------------------------

    public function test_multi_operators(): void
    {
        $this->assertTrue($this->passes('fields.tags', 'multi', 'includes', 'a'));
        $this->assertFalse($this->passes('fields.tags', 'multi', 'includes', 'z'));
        $this->assertTrue($this->passes('fields.tags', 'multi', 'excludes', 'z'));
        $this->assertFalse($this->passes('fields.tags', 'multi', 'excludes', 'a'));
    }

    // ---- file -----------------------------------------------------------------

    public function test_file_operators_are_value_less(): void
    {
        $this->assertTrue($this->passes('fields.attachment', 'file', 'filled'));
        $this->assertFalse($this->passes('fields.attachment', 'file', 'empty'));

        $this->assertTrue($this->passes('fields.no_attachment', 'file', 'empty'));
        $this->assertFalse($this->passes('fields.no_attachment', 'file', 'filled'));
    }

    public function test_an_absent_file_field_is_empty_not_filled(): void
    {
        // `empty` asserts an absence, so like not_equals/is_not it PASSES on a missing path —
        // a field the submitter never answered is genuinely un-answered.
        $this->assertTrue($this->passes('fields.nope', 'file', 'empty'));
        $this->assertFalse($this->passes('fields.nope', 'file', 'filled'));
    }

    public function test_a_file_operator_is_refused_on_a_non_file_field(): void
    {
        // Operators are gated by the field's type allow-list, so the vocabularies cannot mix.
        $this->assertFalse($this->passes('fields.title', 'text', 'filled'));
        $this->assertFalse($this->passes('fields.attachment', 'file', 'equals', 'raport.pdf'));
    }

    // ---- boolean --------------------------------------------------------------

    public function test_boolean_operators_ignore_value(): void
    {
        $this->assertTrue($this->passes('fields.agree', 'boolean', 'is_true'));
        $this->assertFalse($this->passes('fields.agree', 'boolean', 'is_false'));
    }

    // ---- missing-path semantics ----------------------------------------------

    public function test_missing_path_fails_except_absence_operators(): void
    {
        // Positive operators fail on a missing field.
        $this->assertFalse($this->passes('fields.nope', 'text', 'equals', 'x'));
        $this->assertFalse($this->passes('fields.nope', 'number', 'gt', 1));
        $this->assertFalse($this->passes('fields.nope', 'date', 'before', '2026-01-01'));
        $this->assertFalse($this->passes('fields.nope', 'enum', 'is', 'x'));
        $this->assertFalse($this->passes('fields.nope', 'multi', 'includes', 'x'));
        $this->assertFalse($this->passes('fields.nope', 'boolean', 'is_true'));

        // Absence operators PASS on a missing field.
        $this->assertTrue($this->passes('fields.nope', 'text', 'not_equals', 'x'));
        $this->assertTrue($this->passes('fields.nope', 'number', 'neq', 1));
        $this->assertTrue($this->passes('fields.nope', 'enum', 'is_not', 'x'));
        $this->assertTrue($this->passes('fields.nope', 'multi', 'excludes', 'x'));
    }

    // ---- combination + fail-closed -------------------------------------------

    public function test_and_combination_all_must_pass(): void
    {
        $conditions = [
            ['field' => 'fields.priority', 'field_type' => 'text', 'operator' => 'equals', 'value' => 'high'],
            ['field' => 'fields.score', 'field_type' => 'number', 'operator' => 'gte', 'value' => 40],
        ];

        $this->assertTrue($this->evaluator->passes($conditions, $this->payload()));

        $conditions[1]['value'] = 100;
        $this->assertFalse($this->evaluator->passes($conditions, $this->payload()));
    }

    public function test_unknown_type_or_operator_fails_closed(): void
    {
        $this->assertFalse($this->passes('fields.priority', 'bogus', 'equals', 'high'));
        $this->assertFalse($this->passes('fields.priority', 'text', 'not_a_real_operator', 'high'));
        // A valid operator from the WRONG type also fails closed.
        $this->assertFalse($this->passes('fields.priority', 'text', 'gt', 1));
    }
}
