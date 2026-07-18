<?php

namespace Tests\Unit\Workflows;

use App\Modules\Workflows\Enums\WorkflowOperation;
use App\Modules\Workflows\Services\WorkflowConditionEngine;
use App\Modules\Workflows\Services\WorkflowConditionEvaluator;
use App\Modules\Workflows\Services\WorkflowOperationExecutor;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The NEW condition-TREE engine: a logic tree of AND/OR groups whose leaves are conditions, each a
 * source + a typed pipeline of operations terminating in boolean. These tests pin the 66 CONDITION
 * operations (happy + fail-closed edges), the tree combinators (and/or, nesting, laziness by result), the
 * missing-path = false doctrine, the defensive caps (depth/children/steps), the non-boolean-terminal
 * = false rule, and the LEGACY-list delegation (the flat evaluator still runs unchanged).
 *
 * Extends the Laravel TestCase (no DB) so the engine's config('app.timezone') + Carbon resolve; the
 * clock is pinned so the relative date predicates are deterministic.
 */
class WorkflowConditionEngineTest extends TestCase
{
    private WorkflowConditionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new WorkflowConditionEngine(new WorkflowOperationExecutor, new WorkflowConditionEvaluator);
        Carbon::setTestNow('2026-07-14 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** One pipeline step. */
    private function op(string $id, array $args = []): array
    {
        return ['op' => $id, 'args' => $args];
    }

    /** Evaluate a single condition (source `v`, given type + pipeline) over a one-key payload. */
    private function pipe(string $type, mixed $value, array $pipeline): bool
    {
        return $this->engine->passes([
            'logic' => 'and',
            'children' => [[
                'kind' => 'condition',
                'source' => 'v',
                'source_type' => $type,
                'pipeline' => $pipeline,
            ]],
        ], ['v' => $value]);
    }

    // ── wire contract (must mirror standardOperations.ts 1:1) ─────────────────

    public function test_operation_ids_are_the_pinned_wire_contract(): void
    {
        // The backend enum ids ARE the stable wire contract mirrored from the FE
        // resources/js/next/ui/editor/extensions/standardOperations.ts (68 ops). This pin catches
        // any accidental backend rename/removal/addition; the FE list is the source of truth. The two
        // choice terminals (match_to_choice / enum_to_choice) map a value into a target field's option set.
        $expected = [
            'text_uppercase', 'text_lowercase', 'text_trim', 'text_substring', 'text_replace',
            'text_append', 'text_prepend', 'text_length', 'text_to_number', 'text_equals',
            'text_not_equals', 'text_contains', 'text_starts_with', 'text_ends_with', 'text_is_empty', 'text_is_not_empty',
            'match_to_choice',
            'num_add', 'num_subtract', 'num_multiply', 'num_divide', 'num_abs', 'num_round', 'num_floor', 'num_ceil',
            'num_to_text', 'num_eq', 'num_neq', 'num_gt', 'num_gte', 'num_lt', 'num_lte', 'num_between',
            'bool_not', 'bool_to_number', 'bool_to_text',
            'date_add_days', 'date_subtract_days', 'date_add_months', 'date_add_years', 'date_start_of_month',
            'date_end_of_month', 'date_day', 'date_month', 'date_year', 'date_weekday', 'date_to_text',
            'date_before', 'date_after', 'date_on', 'date_between', 'date_is_weekend', 'date_is_past', 'date_is_future',
            'enum_is', 'enum_is_not', 'enum_in', 'enum_to_text', 'enum_to_number', 'enum_to_date', 'enum_to_choice',
            'multi_includes', 'multi_excludes', 'multi_includes_any', 'multi_includes_all', 'multi_count',
            'multi_is_empty', 'multi_to_text',
            // file: two boolean terminals (so a file field is usable in the condition tree at
            // all) + two converters that hand off to the text/number vocabulary.
            'file_is_empty', 'file_is_not_empty', 'file_count', 'file_name',
        ];

        $this->assertSame($expected, array_map(fn (WorkflowOperation $op) => $op->value, WorkflowOperation::cases()));
        $this->assertCount(72, WorkflowOperation::cases());
    }

    // ── FILE (4 ops) ──────────────────────────────────────────────────────────

    /** A file snapshot as the trigger payload carries it. */
    private function file(string $name, string $mime = 'application/pdf'): array
    {
        return ['id' => '019f-' . $name, 'name' => $name, 'mime_type' => $mime, 'size' => 1024];
    }

    public function test_file_boolean_terminals(): void
    {
        $this->assertTrue($this->pipe('file', [], [$this->op('file_is_empty')]));
        $this->assertFalse($this->pipe('file', [$this->file('a.pdf')], [$this->op('file_is_empty')]));

        $this->assertTrue($this->pipe('file', [$this->file('a.pdf')], [$this->op('file_is_not_empty')]));
        $this->assertFalse($this->pipe('file', [], [$this->op('file_is_not_empty')]));
    }

    public function test_file_count_chains_into_the_number_vocabulary(): void
    {
        // No file-specific comparison ops: file_count hands off to num_*.
        $this->assertTrue($this->pipe('file', [$this->file('a.pdf'), $this->file('b.pdf')], [
            $this->op('file_count'),
            $this->op('num_eq', ['value' => 2]),
        ]));
        $this->assertTrue($this->pipe('file', [$this->file('a.pdf')], [
            $this->op('file_count'),
            $this->op('num_lt', ['value' => 2]),
        ]));
        $this->assertTrue($this->pipe('file', [], [
            $this->op('file_count'),
            $this->op('num_eq', ['value' => 0]),
        ]));
    }

    public function test_file_name_chains_into_the_text_vocabulary(): void
    {
        // This is how "is it a PDF" is asked — sharper than a coarse type check would be.
        $this->assertTrue($this->pipe('file', [$this->file('raport.pdf')], [
            $this->op('file_name'),
            $this->op('text_ends_with', ['value' => '.pdf']),
        ]));
        $this->assertFalse($this->pipe('file', [$this->file('zdjecie.png', 'image/png')], [
            $this->op('file_name'),
            $this->op('text_ends_with', ['value' => '.pdf']),
        ]));

        // Several files join like multi_to_text does.
        $this->assertTrue($this->pipe('file', [$this->file('a.pdf'), $this->file('b.pdf')], [
            $this->op('file_name'),
            $this->op('text_equals', ['value' => 'a.pdf, b.pdf']),
        ]));
    }

    public function test_file_ops_tolerate_the_shapes_a_looser_payload_can_hold(): void
    {
        // A bare id (no snapshot) still means "a file is here"...
        $this->assertTrue($this->pipe('file', 'some-uuid', [$this->op('file_is_not_empty')]));
        // ...and a single snapshot need not be wrapped in a list.
        $this->assertTrue($this->pipe('file', $this->file('a.pdf'), [$this->op('file_is_not_empty')]));
        // An unanswered field is empty, however it is spelled.
        $this->assertTrue($this->pipe('file', null, [$this->op('file_is_empty')]));
        $this->assertTrue($this->pipe('file', '', [$this->op('file_is_empty')]));
    }

    // ── TEXT (16 ops) ─────────────────────────────────────────────────────────

    public function test_text_transform_ops(): void
    {
        $this->assertTrue($this->pipe('text', 'abc', [$this->op('text_uppercase'), $this->op('text_equals', ['value' => 'ABC'])]));
        $this->assertTrue($this->pipe('text', 'ABC', [$this->op('text_lowercase'), $this->op('text_equals', ['value' => 'abc'])]));
        $this->assertTrue($this->pipe('text', '  hi  ', [$this->op('text_trim'), $this->op('text_equals', ['value' => 'hi'])]));
        $this->assertTrue($this->pipe('text', 'foo', [$this->op('text_append', ['value' => 'bar']), $this->op('text_equals', ['value' => 'foobar'])]));
        $this->assertTrue($this->pipe('text', 'bar', [$this->op('text_prepend', ['value' => 'foo']), $this->op('text_equals', ['value' => 'foobar'])]));
        $this->assertTrue($this->pipe('text', 'a-b-c', [$this->op('text_replace', ['search' => '-', 'replace' => '_']), $this->op('text_equals', ['value' => 'a_b_c'])]));
    }

    public function test_text_substring_is_one_based_and_zero_length_runs_to_end(): void
    {
        // start=2 (1-based → drop the first char), length=3.
        $this->assertTrue($this->pipe('text', 'abcdef', [$this->op('text_substring', ['start' => 2, 'length' => 3]), $this->op('text_equals', ['value' => 'bcd'])]));
        // length=0 → to the end.
        $this->assertTrue($this->pipe('text', 'abcdef', [$this->op('text_substring', ['start' => 4, 'length' => 0]), $this->op('text_equals', ['value' => 'def'])]));
    }

    public function test_text_length_and_to_number(): void
    {
        $this->assertTrue($this->pipe('text', 'abcd', [$this->op('text_length'), $this->op('num_eq', ['value' => 4])]));
        $this->assertTrue($this->pipe('text', '42', [$this->op('text_to_number'), $this->op('num_eq', ['value' => 42])]));
        // Fail-closed: non-numeric text NEVER coerces to 0 — the whole condition is false.
        $this->assertFalse($this->pipe('text', 'abc', [$this->op('text_to_number'), $this->op('num_eq', ['value' => 0])]));
    }

    public function test_text_predicates(): void
    {
        $this->assertTrue($this->pipe('text', 'x', [$this->op('text_equals', ['value' => 'x'])]));
        $this->assertFalse($this->pipe('text', 'x', [$this->op('text_equals', ['value' => 'y'])]));
        $this->assertTrue($this->pipe('text', 'x', [$this->op('text_not_equals', ['value' => 'y'])]));
        $this->assertTrue($this->pipe('text', 'hello world', [$this->op('text_contains', ['value' => 'lo w'])]));
        // Empty needle is false (fail-closed, never trivially true).
        $this->assertFalse($this->pipe('text', 'x', [$this->op('text_contains', ['value' => ''])]));
        $this->assertTrue($this->pipe('text', 'hello', [$this->op('text_starts_with', ['value' => 'he'])]));
        $this->assertTrue($this->pipe('text', 'hello', [$this->op('text_ends_with', ['value' => 'lo'])]));
        $this->assertTrue($this->pipe('text', '', [$this->op('text_is_empty')]));
        $this->assertFalse($this->pipe('text', 'x', [$this->op('text_is_empty')]));
        $this->assertTrue($this->pipe('text', 'x', [$this->op('text_is_not_empty')]));
    }

    // ── NUMBER (16 ops) ───────────────────────────────────────────────────────

    public function test_number_arithmetic_ops(): void
    {
        $this->assertTrue($this->pipe('number', 5, [$this->op('num_add', ['value' => 3]), $this->op('num_eq', ['value' => 8])]));
        $this->assertTrue($this->pipe('number', 5, [$this->op('num_subtract', ['value' => 2]), $this->op('num_eq', ['value' => 3])]));
        $this->assertTrue($this->pipe('number', 5, [$this->op('num_multiply', ['value' => 2]), $this->op('num_eq', ['value' => 10])]));
        $this->assertTrue($this->pipe('number', 10, [$this->op('num_divide', ['value' => 2]), $this->op('num_eq', ['value' => 5])]));
        $this->assertTrue($this->pipe('number', -5, [$this->op('num_abs'), $this->op('num_eq', ['value' => 5])]));
        $this->assertTrue($this->pipe('number', 3.14159, [$this->op('num_round', ['precision' => 2]), $this->op('num_eq', ['value' => 3.14])]));
        $this->assertTrue($this->pipe('number', 3.9, [$this->op('num_floor'), $this->op('num_eq', ['value' => 3])]));
        $this->assertTrue($this->pipe('number', 3.1, [$this->op('num_ceil'), $this->op('num_eq', ['value' => 4])]));
        $this->assertTrue($this->pipe('number', 42, [$this->op('num_to_text'), $this->op('text_equals', ['value' => '42'])]));
    }

    public function test_number_divide_by_zero_fails_closed(): void
    {
        $this->assertFalse($this->pipe('number', 10, [$this->op('num_divide', ['value' => 0]), $this->op('num_eq', ['value' => 0])]));
    }

    public function test_number_comparison_ops(): void
    {
        $this->assertTrue($this->pipe('number', 5, [$this->op('num_eq', ['value' => 5])]));
        $this->assertTrue($this->pipe('number', 5, [$this->op('num_neq', ['value' => 6])]));
        $this->assertTrue($this->pipe('number', 5, [$this->op('num_gt', ['value' => 3])]));
        $this->assertFalse($this->pipe('number', 5, [$this->op('num_gt', ['value' => 10])]));
        $this->assertTrue($this->pipe('number', 5, [$this->op('num_gte', ['value' => 5])]));
        $this->assertTrue($this->pipe('number', 5, [$this->op('num_lt', ['value' => 9])]));
        $this->assertTrue($this->pipe('number', 5, [$this->op('num_lte', ['value' => 5])]));
        $this->assertTrue($this->pipe('number', 5, [$this->op('num_between', ['from' => 1, 'to' => 10])]));
        $this->assertFalse($this->pipe('number', 15, [$this->op('num_between', ['from' => 1, 'to' => 10])]));
    }

    public function test_non_numeric_source_fails_closed(): void
    {
        $this->assertFalse($this->pipe('number', 'abc', [$this->op('num_eq', ['value' => 0])]));
    }

    // ── BOOLEAN (3 ops) ───────────────────────────────────────────────────────

    public function test_boolean_ops(): void
    {
        $this->assertTrue($this->pipe('boolean', false, [$this->op('bool_not')]));
        $this->assertFalse($this->pipe('boolean', true, [$this->op('bool_not')]));

        $this->assertTrue($this->pipe('boolean', true, [$this->op('bool_to_number', ['when_true' => 1, 'when_false' => 0]), $this->op('num_eq', ['value' => 1])]));
        $this->assertTrue($this->pipe('boolean', false, [$this->op('bool_to_number', ['when_true' => 1, 'when_false' => 0]), $this->op('num_eq', ['value' => 0])]));
        $this->assertTrue($this->pipe('boolean', true, [$this->op('bool_to_text', ['when_true' => 'Y', 'when_false' => 'N']), $this->op('text_equals', ['value' => 'Y'])]));
        $this->assertTrue($this->pipe('boolean', false, [$this->op('bool_to_text', ['when_true' => 'Y', 'when_false' => 'N']), $this->op('text_equals', ['value' => 'N'])]));
    }

    public function test_boolean_source_with_empty_pipeline_is_a_direct_condition(): void
    {
        $this->assertTrue($this->pipe('boolean', true, []));
        $this->assertFalse($this->pipe('boolean', false, []));
        // Lenient truthiness (mirrors the legacy evaluator).
        $this->assertTrue($this->pipe('boolean', 'true', []));
        $this->assertTrue($this->pipe('boolean', 1, []));
    }

    // ── DATE (18 ops) ─────────────────────────────────────────────────────────

    public function test_date_arithmetic_ops(): void
    {
        $this->assertTrue($this->pipe('date', '2026-01-10', [$this->op('date_add_days', ['value' => 5]), $this->op('date_on', ['value' => '2026-01-15'])]));
        $this->assertTrue($this->pipe('date', '2026-01-10', [$this->op('date_subtract_days', ['value' => 5]), $this->op('date_on', ['value' => '2026-01-05'])]));
        $this->assertTrue($this->pipe('date', '2026-01-15', [$this->op('date_add_months', ['value' => 2]), $this->op('date_on', ['value' => '2026-03-15'])]));
        $this->assertTrue($this->pipe('date', '2026-01-15', [$this->op('date_add_years', ['value' => 1]), $this->op('date_on', ['value' => '2027-01-15'])]));
        $this->assertTrue($this->pipe('date', '2026-01-15', [$this->op('date_start_of_month'), $this->op('date_on', ['value' => '2026-01-01'])]));
        $this->assertTrue($this->pipe('date', '2026-02-10', [$this->op('date_end_of_month'), $this->op('date_on', ['value' => '2026-02-28'])]));
    }

    public function test_date_component_ops(): void
    {
        $this->assertTrue($this->pipe('date', '2026-03-17', [$this->op('date_day'), $this->op('num_eq', ['value' => 17])]));
        $this->assertTrue($this->pipe('date', '2026-03-17', [$this->op('date_month'), $this->op('num_eq', ['value' => 3])]));
        $this->assertTrue($this->pipe('date', '2026-03-17', [$this->op('date_year'), $this->op('num_eq', ['value' => 2026])]));
        // 2026-01-04 is a Sunday → weekday 0 (module convention 0=Sunday).
        $this->assertTrue($this->pipe('date', '2026-01-04', [$this->op('date_weekday'), $this->op('num_eq', ['value' => 0])]));
        // 2026-01-03 is a Saturday → weekday 6.
        $this->assertTrue($this->pipe('date', '2026-01-03', [$this->op('date_weekday'), $this->op('num_eq', ['value' => 6])]));
        $this->assertTrue($this->pipe('date', '2026-03-17', [$this->op('date_to_text'), $this->op('text_equals', ['value' => '2026-03-17'])]));
    }

    public function test_date_predicate_ops(): void
    {
        $this->assertTrue($this->pipe('date', '2026-01-10', [$this->op('date_before', ['value' => '2026-01-20'])]));
        $this->assertTrue($this->pipe('date', '2026-01-20', [$this->op('date_after', ['value' => '2026-01-10'])]));
        $this->assertTrue($this->pipe('date', '2026-01-15', [$this->op('date_on', ['value' => '2026-01-15'])]));
        $this->assertFalse($this->pipe('date', '2026-01-15', [$this->op('date_on', ['value' => '2026-01-16'])]));
        $this->assertTrue($this->pipe('date', '2026-01-15', [$this->op('date_between', ['from' => '2026-01-01', 'to' => '2026-01-31'])]));
        $this->assertFalse($this->pipe('date', '2026-02-15', [$this->op('date_between', ['from' => '2026-01-01', 'to' => '2026-01-31'])]));

        // 2026-01-03 Saturday → weekend; 2026-01-05 Monday → not.
        $this->assertTrue($this->pipe('date', '2026-01-03', [$this->op('date_is_weekend')]));
        $this->assertFalse($this->pipe('date', '2026-01-05', [$this->op('date_is_weekend')]));

        // "now" is pinned at 2026-07-14.
        $this->assertTrue($this->pipe('date', '2026-07-13', [$this->op('date_is_past')]));
        $this->assertFalse($this->pipe('date', '2026-07-14', [$this->op('date_is_past')]));
        $this->assertTrue($this->pipe('date', '2026-07-15', [$this->op('date_is_future')]));
        $this->assertFalse($this->pipe('date', '2026-07-14', [$this->op('date_is_future')]));
    }

    public function test_invalid_or_non_strict_date_fails_closed(): void
    {
        $this->assertFalse($this->pipe('date', 'not-a-date', [$this->op('date_is_past')]));
        $this->assertFalse($this->pipe('date', '2026-13-40', [$this->op('date_is_past')]));   // calendar roll-over rejected
        $this->assertFalse($this->pipe('date', '2026-1-1', [$this->op('date_is_past')]));      // non-zero-padded rejected
        $this->assertFalse($this->pipe('date', '2026-02-30', [$this->op('date_on', ['value' => '2026-03-02'])])); // Feb 30 rejected
    }

    // ── ENUM (6 ops) ──────────────────────────────────────────────────────────

    public function test_enum_ops(): void
    {
        $this->assertTrue($this->pipe('enum', 'blog', [$this->op('enum_is', ['value' => 'blog'])]));
        $this->assertFalse($this->pipe('enum', 'blog', [$this->op('enum_is', ['value' => 'news'])]));
        $this->assertTrue($this->pipe('enum', 'blog', [$this->op('enum_is_not', ['value' => 'news'])]));
        $this->assertTrue($this->pipe('enum', 'blog', [$this->op('enum_in', ['values' => ['blog', 'news']])]));
        $this->assertFalse($this->pipe('enum', 'blog', [$this->op('enum_in', ['values' => ['news', 'press']])]));

        $this->assertTrue($this->pipe('enum', 'blog', [$this->op('enum_to_text', ['mapping' => ['blog' => 'B', 'news' => 'N']]), $this->op('text_equals', ['value' => 'B'])]));
        $this->assertTrue($this->pipe('enum', 'high', [$this->op('enum_to_number', ['mapping' => ['high' => 3, 'low' => 1]]), $this->op('num_eq', ['value' => 3])]));
        $this->assertTrue($this->pipe('enum', 'q1', [$this->op('enum_to_date', ['mapping' => ['q1' => '2026-01-01']]), $this->op('date_on', ['value' => '2026-01-01'])]));
    }

    public function test_enum_mapping_fails_closed_on_unmapped_or_unparseable(): void
    {
        // Unmapped option fails.
        $this->assertFalse($this->pipe('enum', 'other', [$this->op('enum_to_text', ['mapping' => ['blog' => 'B']]), $this->op('text_is_not_empty')]));
        // Unparseable mapped number fails.
        $this->assertFalse($this->pipe('enum', 'high', [$this->op('enum_to_number', ['mapping' => ['high' => 'abc']]), $this->op('num_gte', ['value' => 0])]));
        // Unparseable mapped date fails.
        $this->assertFalse($this->pipe('enum', 'q1', [$this->op('enum_to_date', ['mapping' => ['q1' => 'nope']]), $this->op('date_is_past')]));
    }

    // ── MULTI (7 ops) ─────────────────────────────────────────────────────────

    public function test_multi_ops(): void
    {
        $this->assertTrue($this->pipe('multi', ['a', 'b'], [$this->op('multi_includes', ['value' => 'a'])]));
        $this->assertFalse($this->pipe('multi', ['a', 'b'], [$this->op('multi_includes', ['value' => 'z'])]));
        $this->assertTrue($this->pipe('multi', ['a', 'b'], [$this->op('multi_excludes', ['value' => 'z'])]));
        $this->assertFalse($this->pipe('multi', ['a', 'b'], [$this->op('multi_excludes', ['value' => 'a'])]));
        $this->assertTrue($this->pipe('multi', ['a', 'b'], [$this->op('multi_includes_any', ['values' => ['x', 'b']])]));
        $this->assertFalse($this->pipe('multi', ['a', 'b'], [$this->op('multi_includes_any', ['values' => ['x', 'y']])]));
        $this->assertTrue($this->pipe('multi', ['a', 'b', 'c'], [$this->op('multi_includes_all', ['values' => ['a', 'b']])]));
        $this->assertFalse($this->pipe('multi', ['a', 'b', 'c'], [$this->op('multi_includes_all', ['values' => ['a', 'z']])]));
        $this->assertTrue($this->pipe('multi', ['a', 'b', 'c'], [$this->op('multi_count'), $this->op('num_eq', ['value' => 3])]));
        $this->assertTrue($this->pipe('multi', [], [$this->op('multi_is_empty')]));
        $this->assertFalse($this->pipe('multi', ['a'], [$this->op('multi_is_empty')]));
        // multi_to_text joins the SELECTED option VALUES (the runtime holds values, not labels).
        $this->assertTrue($this->pipe('multi', ['a', 'b'], [$this->op('multi_to_text'), $this->op('text_equals', ['value' => 'a, b'])]));
    }

    public function test_multi_non_array_source_is_wrapped_as_a_single_value_set(): void
    {
        $this->assertTrue($this->pipe('multi', 'a', [$this->op('multi_includes', ['value' => 'a'])]));
    }

    // ── tree: and / or / nesting / laziness ───────────────────────────────────

    private function cond(string $source, string $type, array $pipeline): array
    {
        return ['kind' => 'condition', 'source' => $source, 'source_type' => $type, 'pipeline' => $pipeline];
    }

    public function test_and_group_requires_every_child(): void
    {
        $payload = ['fields' => ['a' => 'x', 'n' => 5]];

        $tree = ['logic' => 'and', 'children' => [
            $this->cond('fields.a', 'text', [$this->op('text_equals', ['value' => 'x'])]),
            $this->cond('fields.n', 'number', [$this->op('num_gt', ['value' => 3])]),
        ]];
        $this->assertTrue($this->engine->passes($tree, $payload));

        $tree['children'][1] = $this->cond('fields.n', 'number', [$this->op('num_gt', ['value' => 100])]);
        $this->assertFalse($this->engine->passes($tree, $payload));
    }

    public function test_or_group_requires_some_child(): void
    {
        $payload = ['fields' => ['a' => 'x', 'n' => 5]];

        $tree = ['logic' => 'or', 'children' => [
            $this->cond('fields.a', 'text', [$this->op('text_equals', ['value' => 'nope'])]),
            $this->cond('fields.n', 'number', [$this->op('num_gt', ['value' => 3])]),
        ]];
        $this->assertTrue($this->engine->passes($tree, $payload));

        $tree['children'][1] = $this->cond('fields.n', 'number', [$this->op('num_gt', ['value' => 100])]);
        $this->assertFalse($this->engine->passes($tree, $payload));
    }

    public function test_three_level_nesting_evaluates(): void
    {
        $payload = ['fields' => ['a' => 'x', 'n' => 5, 'b' => 'yes']];

        // and( a=x , or( n>100 , and( b=yes ) ) )  →  true (inner and true → or true → outer and true)
        $tree = ['logic' => 'and', 'children' => [
            $this->cond('fields.a', 'text', [$this->op('text_equals', ['value' => 'x'])]),
            ['kind' => 'group', 'logic' => 'or', 'children' => [
                $this->cond('fields.n', 'number', [$this->op('num_gt', ['value' => 100])]),
                ['kind' => 'group', 'logic' => 'and', 'children' => [
                    $this->cond('fields.b', 'text', [$this->op('text_equals', ['value' => 'yes'])]),
                ]],
            ]],
        ]];
        $this->assertTrue($this->engine->passes($tree, $payload));
    }

    public function test_short_circuit_by_result(): void
    {
        // A malformed (always-false) child is harmless: or(true, garbage) → true; and(false, garbage) → false.
        $garbage = ['kind' => 'condition', 'source' => 'fields.missing', 'source_type' => 'text', 'pipeline' => [$this->op('bogus')]];
        $payload = ['fields' => ['a' => 'x']];

        $this->assertTrue($this->engine->passes([
            'logic' => 'or',
            'children' => [$this->cond('fields.a', 'text', [$this->op('text_equals', ['value' => 'x'])]), $garbage],
        ], $payload));

        $this->assertFalse($this->engine->passes([
            'logic' => 'and',
            'children' => [$this->cond('fields.a', 'text', [$this->op('text_equals', ['value' => 'nope'])]), $garbage],
        ], $payload));
    }

    // ── fail-closed doctrine ──────────────────────────────────────────────────

    public function test_missing_path_is_false(): void
    {
        $this->assertFalse($this->engine->passes(
            ['logic' => 'and', 'children' => [$this->cond('fields.nope', 'text', [$this->op('text_is_empty')])]],
            ['fields' => []],
        ));
    }

    public function test_non_boolean_terminal_is_false(): void
    {
        // A pipeline that ends in text (not boolean) can never open the gate.
        $this->assertFalse($this->pipe('text', 'abc', [$this->op('text_uppercase')]));
        $this->assertFalse($this->pipe('number', 5, [$this->op('num_add', ['value' => 1])]));
    }

    public function test_unknown_op_and_type_mismatch_are_false(): void
    {
        $this->assertFalse($this->pipe('text', 'x', [$this->op('does_not_exist')]));
        // num_add expects a number input but the current value is text.
        $this->assertFalse($this->pipe('text', 'x', [$this->op('num_add', ['value' => 1])]));
    }

    public function test_defensive_caps_fail_closed(): void
    {
        // 11 pipeline steps (> MAX 10) → false, even though each step is individually valid.
        $steps = array_fill(0, 10, $this->op('text_trim'));
        $steps[] = $this->op('text_is_not_empty');
        $this->assertFalse($this->pipe('text', 'x', $steps));

        // 11 children in a group (> MAX 10) → false.
        $children = array_fill(0, 11, $this->cond('fields.a', 'text', [$this->op('text_is_not_empty')]));
        $this->assertFalse($this->engine->passes(['logic' => 'or', 'children' => $children], ['fields' => ['a' => 'x']]));

        // Empty group → false.
        $this->assertFalse($this->engine->passes(['logic' => 'and', 'children' => []], ['fields' => []]));
        // Unknown logic → false.
        $this->assertFalse($this->engine->passes(['logic' => 'xor', 'children' => [$this->cond('fields.a', 'text', [$this->op('text_is_not_empty')])]], ['fields' => ['a' => 'x']]));
    }

    public function test_depth_cap_allows_five_levels_and_rejects_six(): void
    {
        $this->assertTrue($this->engine->passes($this->nest(5), ['fields' => ['a' => 'x']]));
        $this->assertFalse($this->engine->passes($this->nest(6), ['fields' => ['a' => 'x']]));
    }

    /** Build $levels nested groups; the innermost holds a single always-true condition. */
    private function nest(int $levels): array
    {
        $node = $this->cond('fields.a', 'text', [$this->op('text_equals', ['value' => 'x'])]);

        for ($i = 0; $i < $levels; $i++) {
            $node = ['kind' => 'group', 'logic' => 'and', 'children' => [$node]];
        }

        return $node;
    }

    // ── legacy delegation (the flat list is untouched) ────────────────────────

    public function test_empty_or_null_conditions_pass(): void
    {
        $this->assertTrue($this->engine->passes(null, ['fields' => []]));
        $this->assertTrue($this->engine->passes([], ['fields' => []]));
    }

    public function test_legacy_flat_clause_list_is_delegated_unchanged(): void
    {
        $payload = ['fields' => ['priority' => 'high', 'score' => 42]];

        $this->assertTrue($this->engine->passes(
            [['field' => 'fields.priority', 'field_type' => 'text', 'operator' => 'equals', 'value' => 'high']],
            $payload,
        ));

        $this->assertFalse($this->engine->passes(
            [['field' => 'fields.priority', 'field_type' => 'text', 'operator' => 'equals', 'value' => 'low']],
            $payload,
        ));

        // The legacy AND-combination and absence-operator semantics still hold through the engine.
        $this->assertTrue($this->engine->passes([
            ['field' => 'fields.priority', 'field_type' => 'text', 'operator' => 'equals', 'value' => 'high'],
            ['field' => 'fields.score', 'field_type' => 'number', 'operator' => 'gte', 'value' => 40],
        ], $payload));

        $this->assertTrue($this->engine->passes(
            [['field' => 'fields.nope', 'field_type' => 'text', 'operator' => 'not_equals', 'value' => 'x']],
            $payload,
        ));
    }
}
