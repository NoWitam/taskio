<?php

namespace Tests\Unit\Workflows;

use App\Modules\Variables\Enums\Operation as WorkflowOperation;
use App\Modules\Variables\Services\OperationExecutor as WorkflowOperationExecutor;
use App\Modules\Variables\Services\VariableResolver as WorkflowVariableResolver;
use App\Modules\Workflows\Services\WorkflowConditionEngine;
use App\Modules\Workflows\Services\WorkflowConditionEvaluator;
use App\Modules\Workflows\Services\WorkflowVariableCatalogService;
use ArrayAccess;
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
        // The resolver + catalog are the B6 collaborators (argument pre-resolution and the workspace
        // globals). Every test in THIS file uses form-field sources with literal arguments, so the
        // engine's cost guard never reaches either of them — the file stays DB-free.
        $this->engine = new WorkflowConditionEngine(
            new WorkflowOperationExecutor,
            new WorkflowConditionEvaluator,
            app(WorkflowVariableResolver::class),
            app(WorkflowVariableCatalogService::class),
        );
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
        // resources/js/next/ui/editor/extensions/standardOperations.ts. This pin catches any accidental
        // backend rename/removal/reorder; ids may only be APPENDED. The two choice terminals
        // (match_to_choice / enum_to_choice) map a value into a target field's option set. The final
        // block is the phase-1b append (presence helpers + a safe date formatter).
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
            // phase-1b append-only: presence helpers + a safe date formatter.
            'coalesce', 'is_present', 'is_null', 'assert_present', 'date_format',
            // array-ops wave 1 append-only: array transforms (count/at) gated on an array input.
            'array_count', 'array_at',
            // array-ops wave 2 append-only: the higher-order transforms (per-element pipelines).
            'array_map', 'array_filter', 'array_sort', 'array_reduce',
        ];

        $this->assertSame($expected, array_map(fn (WorkflowOperation $op) => $op->value, WorkflowOperation::cases()));
        $this->assertCount(83, WorkflowOperation::cases());
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

    // ══════════════════════════════════════════════════════════════════════════
    //  CHARACTERIZATION PINS
    //
    //  Everything below records behaviour the gate has TODAY, captured before the
    //  variable-typesystem refactor touches evaluateCondition() (an opt-in per-condition
    //  `default`, and resolver-backed pre-resolution of variable-shaped operation arguments —
    //  the latter pinned separately in WorkflowConditionEngineArgVariableTest).
    //
    //  These are a regression net, not a wish list. If a later batch makes one of them fail,
    //  that is a BEHAVIOUR CHANGE to surface and accept deliberately in that batch's diff —
    //  never a test to quietly edit into agreement.
    //
    //  B6 STATUS: every pin below is UNCHANGED by that batch — it shipped the `default` as strictly
    //  OPT-IN (gated on the node carrying the key), so the missing-path doctrine these pins record is
    //  exactly what a condition WITHOUT the key still gets. The batch only ADDED the
    //  "…the OPT-IN per-condition `default`…" section, whose first test is the deliberate
    //  counterpart to test_a_missing_source_path_is_false_in_every_shape. The argument-variable
    //  expectations DID flip, in WorkflowConditionEngineArgVariableTest, as that file predicted.
    // ══════════════════════════════════════════════════════════════════════════

    // ── dispatch seam: shape alone picks the model ────────────────────────────

    public function test_shape_alone_picks_the_evaluator_so_a_list_of_tree_nodes_takes_the_legacy_path(): void
    {
        // array_is_list() is the ONLY discriminator. A LIST whose entries happen to be tree nodes is
        // still handed to the flat evaluator, which knows no kind/source/pipeline vocabulary and fails
        // the clause closed — the tree walk is never reached.
        $this->assertFalse($this->engine->passes([$this->cond('v', 'boolean', [])], ['v' => true]));

        // ...and conversely an OBJECT never reaches the legacy evaluator, even carrying a clause's keys.
        $this->assertFalse($this->engine->passes(
            ['field' => 'v', 'field_type' => 'text', 'operator' => 'equals', 'value' => 'x'],
            ['v' => 'x'],
        ));

        // An object that is neither a group nor a clause is simply not a tree.
        $this->assertFalse($this->engine->passes(['foo' => 'bar'], ['v' => true]));
    }

    public function test_the_root_node_is_always_read_as_a_group_whatever_kind_it_claims(): void
    {
        // passes() enters evaluateGroup() directly, so the root's own `kind` is ignored: a root that
        // spells itself a CONDITION is still required to carry group children — it has none → false.
        $this->assertFalse($this->engine->passes(
            array_merge($this->cond('v', 'boolean', []), ['logic' => 'and']),
            ['v' => true],
        ));
    }

    public function test_the_two_models_disagree_on_a_missing_path_and_that_is_current_behaviour(): void
    {
        // The LEGACY list keeps its ABSENCE operators — an absent field trivially "is not X" and PASSES.
        // The TREE has no such operator: an absent path is simply false. Both are current behaviour, and
        // because the engine routes by shape alone the SAME payload passes one model and fails the other.
        $payload = ['fields' => ['priority' => 'high']];

        $this->assertTrue($this->engine->passes(
            [['field' => 'fields.nope', 'field_type' => 'text', 'operator' => 'not_equals', 'value' => 'x']],
            $payload,
        ));
        $this->assertFalse($this->engine->passes(
            ['logic' => 'and', 'children' => [$this->cond('fields.nope', 'text', [$this->op('text_not_equals', ['value' => 'x'])])]],
            $payload,
        ));
    }

    // ── the MISSING-path doctrine (exactly what the future `default` will opt out of) ──

    public function test_a_missing_source_path_is_false_in_every_shape(): void
    {
        // Every pipeline here is TRUE over an empty value, so ONLY the missing-path rule can explain
        // the false. A missing leaf under a present parent...
        $this->assertFalse($this->missingProbe(['fields' => ['other' => 'x']], 'fields.nope'));
        // ...a missing INTERMEDIATE segment...
        $this->assertFalse($this->missingProbe(['fields' => ['other' => 'x']], 'nope.deep'));
        // ...an entirely empty payload...
        $this->assertFalse($this->missingProbe([], 'fields.a'));
        // ...and an empty source string, which can address nothing.
        $this->assertFalse($this->missingProbe(['v' => 'x'], ''));
    }

    /** A condition that would be TRUE over an empty value, so only a missing path can falsify it. */
    private function missingProbe(array $payload, string $source): bool
    {
        return $this->engine->passes(
            ['logic' => 'and', 'children' => [$this->cond($source, 'text', [$this->op('text_is_empty')])]],
            $payload,
        );
    }

    public function test_a_missing_path_is_false_even_for_the_presence_ops_while_a_present_null_is_not(): void
    {
        // THE pin the future opt-in `default` will change. The MISSING sentinel short-circuits BEFORE the
        // pipeline runs, so today not even the presence family — whose whole job is to read absence — can
        // observe an absent path. A path that EXISTS holding null behaves completely differently.
        $isNull = [$this->op('is_null')];

        $this->assertTrue($this->pipe('text', null, $isNull));            // present, holding null
        $this->assertFalse($this->engine->passes(                          // absent — never reaches is_null
            ['logic' => 'and', 'children' => [$this->cond('v', 'text', $isNull)]],
            [],
        ));

        // Same asymmetry for coalesce: a present null takes the fallback, an ABSENT path cannot.
        $coalesce = [$this->op('coalesce', ['fallback' => 'x']), $this->op('text_equals', ['value' => 'x'])];

        $this->assertTrue($this->pipe('text', null, $coalesce));
        $this->assertFalse($this->engine->passes(
            ['logic' => 'and', 'children' => [$this->cond('v', 'text', $coalesce)]],
            [],
        ));

        // And a present null is still "not present" to is_present — it is the VALUE that is empty, not
        // the path. So absence and emptiness are distinguishable today only in the missing direction.
        $this->assertFalse($this->pipe('text', null, [$this->op('is_present')]));
    }

    public function test_a_present_but_falsy_value_is_never_treated_as_missing(): void
    {
        $this->assertTrue($this->pipe('boolean', false, [$this->op('bool_not')]));
        $this->assertTrue($this->pipe('text', '', [$this->op('text_is_empty')]));
        $this->assertTrue($this->pipe('number', 0, [$this->op('num_eq', ['value' => 0])]));
        $this->assertTrue($this->pipe('multi', [], [$this->op('multi_is_empty')]));
    }

    public function test_a_missing_path_is_a_plain_false_in_the_combinators(): void
    {
        // The rule is per CONDITION, not per tree: a missing-path child does not veto a true sibling.
        $this->assertTrue($this->engine->passes([
            'logic' => 'or',
            'children' => [
                $this->cond('fields.nope', 'text', [$this->op('text_is_empty')]),
                $this->cond('v', 'boolean', []),
            ],
        ], ['v' => true]));
    }

    // ── the OPT-IN per-condition `default` (B6) — the ONLY way out of the doctrine above ──

    /** A one-condition tree over $payload; $node is merged onto the condition (e.g. a `default`). */
    private function gate(string $source, string $type, array $pipeline, array $payload, array $node = []): bool
    {
        return $this->engine->passes(
            ['logic' => 'and', 'children' => [array_merge($this->cond($source, $type, $pipeline), $node)]],
            $payload,
        );
    }

    public function test_an_opt_in_default_substitutes_for_a_missing_path(): void
    {
        // The counterpart to test_a_missing_source_path_is_false_in_every_shape: the SAME shapes, with
        // a `default` key added, now evaluate the default instead of collapsing to false. The pins
        // above still hold — a condition WITHOUT the key is untouched, which is the whole contract.
        $equalsFallback = [$this->op('text_equals', ['value' => 'fallback'])];

        $this->assertFalse($this->gate('fields.nope', 'text', $equalsFallback, ['fields' => ['other' => 'x']]));
        $this->assertTrue($this->gate('fields.nope', 'text', $equalsFallback, ['fields' => ['other' => 'x']], ['default' => 'fallback']));

        // A missing INTERMEDIATE segment, and an entirely empty payload, substitute just the same.
        $this->assertTrue($this->gate('nope.deep', 'text', $equalsFallback, ['fields' => []], ['default' => 'fallback']));
        $this->assertTrue($this->gate('fields.a', 'text', $equalsFallback, [], ['default' => 'fallback']));
    }

    public function test_a_default_also_substitutes_for_a_present_null_or_empty_value(): void
    {
        // "Missing or empty" is ONE rule here, mirroring WorkflowVariableResolver::applyDefault — an
        // unanswered optional field reaches the gate as null or '' just as often as it is absent.
        $isFallback = [$this->op('text_equals', ['value' => 'fallback'])];

        $this->assertTrue($this->gate('v', 'text', $isFallback, ['v' => null], ['default' => 'fallback']));
        $this->assertTrue($this->gate('v', 'text', $isFallback, ['v' => ''], ['default' => 'fallback']));
    }

    public function test_a_default_never_displaces_a_present_value_however_falsy(): void
    {
        // The substitution triggers on ABSENCE/emptiness only: false, 0 and [] are real answers.
        $this->assertTrue($this->gate('v', 'boolean', [$this->op('bool_not')], ['v' => false], ['default' => true]));
        $this->assertTrue($this->gate('v', 'number', [$this->op('num_eq', ['value' => 0])], ['v' => 0], ['default' => 99]));
        $this->assertTrue($this->gate('v', 'multi', [$this->op('multi_is_empty')], ['v' => []], ['default' => 'x']));
        $this->assertTrue($this->gate('v', 'text', [$this->op('text_equals', ['value' => 'real'])], ['v' => 'real'], ['default' => 'fallback']));
    }

    public function test_a_default_flows_through_the_pipeline_exactly_like_a_looked_up_value(): void
    {
        // It is substituted BEFORE the pipeline runs, so it is typed/transformed identically — a date
        // default is parsed and compared, a number default is arithmetic, an enum default is mapped.
        $this->assertTrue($this->gate('fields.due', 'date', [
            $this->op('date_add_days', ['value' => 5]),
            $this->op('date_on', ['value' => '2026-01-15']),
        ], ['fields' => []], ['default' => '2026-01-10']));

        $this->assertTrue($this->gate('fields.n', 'number', [
            $this->op('num_add', ['value' => 3]),
            $this->op('num_eq', ['value' => 10]),
        ], ['fields' => []], ['default' => 7]));

        $this->assertTrue($this->gate('fields.priority', 'enum', [
            $this->op('enum_is', ['value' => 'low']),
        ], ['fields' => []], ['default' => 'low']));

        // …and a default that cannot be represented as the declared type is an ordinary fail-closed
        // false, exactly as the same value looked up off the payload would be.
        $this->assertFalse($this->gate('fields.n', 'number', [
            $this->op('num_eq', ['value' => 0]),
        ], ['fields' => []], ['default' => 'not-a-number']));
    }

    public function test_a_null_or_non_scalar_default_is_ignored_and_the_missing_path_stays_false(): void
    {
        // The KEY is what opts in, but only a SCALAR is a usable literal (the write-validator rejects
        // anything else, so these are legacy/hand-written rows): the condition falls back to false
        // rather than substituting an array/object nobody can type.
        foreach ([null, ['a'], ['k' => 'v']] as $default) {
            $this->assertFalse($this->gate('fields.nope', 'text', [$this->op('text_is_empty')], ['fields' => []], ['default' => $default]));
        }
    }

    public function test_a_default_is_read_per_condition_and_does_not_leak_to_siblings(): void
    {
        // Both children address a missing path; only the FIRST opts in to a default.
        $withDefault = array_merge($this->cond('fields.a', 'text', [$this->op('text_equals', ['value' => 'x'])]), ['default' => 'x']);
        $without = $this->cond('fields.b', 'text', [$this->op('text_is_empty')]);

        // The sibling without the key keeps the doctrine's false, so the AND cannot pass…
        $this->assertFalse($this->engine->passes(['logic' => 'and', 'children' => [$withDefault, $without]], ['fields' => []]));
        // …while the OR does, which is only possible if the first child really took its default.
        $this->assertTrue($this->engine->passes(['logic' => 'or', 'children' => [$withDefault, $without]], ['fields' => []]));
    }

    public function test_a_source_path_traverses_nested_arrays_and_numeric_indexes(): void
    {
        $this->assertTrue($this->engine->passes(
            ['logic' => 'and', 'children' => [$this->cond('fields.tags.0', 'text', [$this->op('text_equals', ['value' => 'a'])])]],
            ['fields' => ['tags' => ['a', 'b']]],
        ));
    }

    // ── condition leaf: source / source_type / pipeline shape ─────────────────

    public function test_a_non_string_source_or_an_unknown_source_type_is_false(): void
    {
        // The source must be a string — a numeric key that Arr::get could otherwise resolve does not
        // qualify, and an absent source key is false.
        $this->assertFalse($this->engine->passes(
            ['logic' => 'and', 'children' => [['kind' => 'condition', 'source' => 5, 'source_type' => 'text', 'pipeline' => [$this->op('text_is_empty')]]]],
            [5 => ''],
        ));
        $this->assertFalse($this->engine->passes(
            ['logic' => 'and', 'children' => [['kind' => 'condition', 'source_type' => 'boolean', 'pipeline' => []]]],
            ['v' => true],
        ));

        // ...and the source_type must name a real variable type (absent or unknown → false).
        $this->assertFalse($this->engine->passes(
            ['logic' => 'and', 'children' => [['kind' => 'condition', 'source' => 'v', 'pipeline' => []]]],
            ['v' => true],
        ));
        $this->assertFalse($this->engine->passes(
            ['logic' => 'and', 'children' => [$this->cond('v', 'blob', [])]],
            ['v' => true],
        ));
    }

    public function test_an_absent_or_non_array_pipeline_is_false_but_an_empty_one_evaluates_the_bare_source(): void
    {
        // An ABSENT `pipeline` key is false: the condition never runs at all.
        $this->assertFalse($this->engine->passes(
            ['logic' => 'and', 'children' => [['kind' => 'condition', 'source' => 'v', 'source_type' => 'boolean']]],
            ['v' => true],
        ));

        foreach (['nope', 0, false, 1.5] as $pipeline) {
            $this->assertFalse($this->engine->passes(
                ['logic' => 'and', 'children' => [['kind' => 'condition', 'source' => 'v', 'source_type' => 'boolean', 'pipeline' => $pipeline]]],
                ['v' => true],
            ));
        }

        // An EMPTY pipeline is a different thing entirely — it RUNS, and the bare source is the terminal.
        // So `null` and `[]` are not interchangeable here, which is the seam a future `default` sits next to.
        $this->assertTrue($this->pipe('boolean', true, []));
        $this->assertFalse($this->pipe('boolean', false, []));
    }

    public function test_a_malformed_pipeline_step_is_false(): void
    {
        $this->assertFalse($this->pipe('boolean', false, ['bool_not']));     // a step that is not an array
        $this->assertFalse($this->pipe('boolean', true, [['args' => []]]));  // a step carrying no op id
    }

    public function test_the_editor_operation_id_key_is_honoured_through_the_gate(): void
    {
        // The executor reads `op` OR the next editor's `operationId`, so a tree serialized straight out
        // of the editor evaluates identically through the gate.
        $this->assertTrue($this->pipe('boolean', false, [['operationId' => 'bool_not']]));
    }

    // ── terminal: only a boolean TRUE opens the gate ──────────────────────────

    public function test_only_a_boolean_true_terminal_opens_the_gate(): void
    {
        // Every NON-boolean terminal is false, however successful the pipeline was.
        $this->assertFalse($this->pipe('text', 'abc', []));
        $this->assertFalse($this->pipe('number', 5, []));
        $this->assertFalse($this->pipe('enum', 'a', []));
        $this->assertFalse($this->pipe('multi', ['a'], []));
        $this->assertFalse($this->pipe('date', '2026-01-01', []));
        $this->assertFalse($this->pipe('file', [], [$this->op('file_count')]));            // number terminal
        $this->assertFalse($this->pipe('text', 'a', [$this->op('text_length')]));          // number terminal

        // A boolean terminal that is FALSE is false; only boolean TRUE passes.
        $this->assertFalse($this->pipe('boolean', true, [$this->op('bool_not')]));
        $this->assertTrue($this->pipe('boolean', false, [$this->op('bool_not')]));
    }

    public function test_every_executor_failure_collapses_the_condition_to_the_same_false(): void
    {
        // The gate reads only `failed` — every dead end the executor reports is indistinguishable here.
        $this->assertFalse($this->pipe('text', 'x', [$this->op('does_not_exist')]));                   // unknown op
        $this->assertFalse($this->pipe('text', 'x', [$this->op('num_add', ['value' => 1])]));          // input-type mismatch
        $this->assertFalse($this->pipe('text', 'x', [$this->op('text_equals')]));                      // missing required arg
        $this->assertFalse($this->pipe('number', 'abc', [$this->op('num_eq', ['value' => 0])]));       // unnormalizable base
        $this->assertFalse($this->pipe('enum', 'zzz', [                                               // unmapped enum option
            $this->op('enum_to_text', ['mapping' => ['a' => 'A']]),
            $this->op('text_is_not_empty'),
        ]));

        // A HARD failure (assert_present over an empty value) is still just a failure HERE: the gate
        // never reads `hard` and never re-raises it, so a condition can not fail a form submission the
        // way a step's value pipeline can.
        $this->assertFalse($this->pipe('text', '', [$this->op('assert_present'), $this->op('text_is_not_empty')]));
    }

    // ── tree structure: kinds, logic, children, caps ──────────────────────────

    public function test_a_child_without_a_kind_is_read_as_a_group(): void
    {
        // evaluateNode() defaults a kind-less child to 'group', so an editor that omits `kind` on nested
        // groups still evaluates.
        $this->assertTrue($this->engine->passes([
            'logic' => 'and',
            'children' => [['logic' => 'and', 'children' => [$this->cond('v', 'boolean', [])]]],
        ], ['v' => true]));
    }

    public function test_an_unknown_kind_or_a_non_array_child_is_false(): void
    {
        $valid = $this->cond('v', 'boolean', []);

        $this->assertFalse($this->engine->passes(['logic' => 'and', 'children' => [array_merge($valid, ['kind' => 'rule'])]], ['v' => true]));
        $this->assertFalse($this->engine->passes(['logic' => 'and', 'children' => ['nope']], ['v' => true]));
        $this->assertFalse($this->engine->passes(['logic' => 'and', 'children' => [null]], ['v' => true]));

        // A garbage child is just a false child: an OR carrying a real true still passes.
        $this->assertTrue($this->engine->passes(['logic' => 'or', 'children' => ['nope', $valid]], ['v' => true]));
    }

    public function test_logic_must_be_exactly_and_or_or(): void
    {
        // The match is strict, so case, whitespace and type all matter.
        $children = [$this->cond('v', 'boolean', [])];

        foreach ([null, '', 'AND', 'Or', 'and ', 'xor', 1, true, ['and']] as $logic) {
            $this->assertFalse($this->engine->passes(['logic' => $logic, 'children' => $children], ['v' => true]));
        }

        // An absent logic key is the same false.
        $this->assertFalse($this->engine->passes(['children' => $children], ['v' => true]));
    }

    public function test_children_must_be_a_non_empty_array_but_need_not_be_a_list(): void
    {
        $this->assertFalse($this->engine->passes(['logic' => 'and'], ['v' => true]));
        $this->assertFalse($this->engine->passes(['logic' => 'and', 'children' => 'x'], ['v' => true]));
        $this->assertFalse($this->engine->passes(['logic' => 'and', 'children' => []], ['v' => true]));

        // An ASSOCIATIVE children map evaluates exactly like a list — the engine only foreaches.
        $this->assertTrue($this->engine->passes(
            ['logic' => 'and', 'children' => ['first' => $this->cond('v', 'boolean', [])]],
            ['v' => true],
        ));
    }

    public function test_the_child_and_step_caps_are_inclusive_upper_bounds(): void
    {
        $child = $this->cond('v', 'boolean', []);

        // Exactly MAX_CHILDREN (10) evaluates; the 11th collapses the whole group.
        $this->assertTrue($this->engine->passes(['logic' => 'and', 'children' => array_fill(0, 10, $child)], ['v' => true]));
        $this->assertFalse($this->engine->passes(['logic' => 'and', 'children' => array_fill(0, 11, $child)], ['v' => true]));

        // Exactly MAX_PIPELINE_STEPS (10) evaluates; the 11th collapses the whole pipeline, even though
        // every individual step is valid.
        $ten = array_merge(array_fill(0, 9, $this->op('text_trim')), [$this->op('text_is_not_empty')]);
        $eleven = array_merge(array_fill(0, 10, $this->op('text_trim')), [$this->op('text_is_not_empty')]);

        $this->assertTrue($this->pipe('text', 'x', $ten));
        $this->assertFalse($this->pipe('text', 'x', $eleven));
    }

    public function test_the_depth_cap_is_local_to_its_branch(): void
    {
        // nest(N) wraps a true condition in N groups. Used as a CHILD of the root those groups occupy
        // depths 2..N+1, so nest(4) fits (max depth 5) and nest(5) does not.
        $payload = ['fields' => ['a' => 'x'], 'v' => true];
        $fits = $this->nest(4);
        $tooDeep = $this->nest(5);

        $this->assertTrue($this->engine->passes(['logic' => 'and', 'children' => [$fits]], $payload));
        $this->assertFalse($this->engine->passes(['logic' => 'and', 'children' => [$tooDeep]], $payload));

        // An over-deep branch is false WHERE IT SITS — it does not poison the tree, so an OR with a
        // valid sibling still passes.
        $this->assertTrue($this->engine->passes(
            ['logic' => 'or', 'children' => [$tooDeep, $this->cond('v', 'boolean', [])]],
            $payload,
        ));
    }

    // ── laziness, observed through the payload rather than a mock ─────────────

    public function test_an_and_group_stops_evaluating_after_the_first_false(): void
    {
        $fields = $this->readSpy(['a' => 'x', 'b' => 'x']);

        $this->assertFalse($this->engine->passes([
            'logic' => 'and',
            'children' => [
                $this->cond('fields.a', 'text', [$this->op('text_equals', ['value' => 'NOPE'])]),
                $this->cond('fields.b', 'text', [$this->op('text_is_not_empty')]),
            ],
        ], ['fields' => $fields]));

        // The claim is only that the second child is never evaluated — not how many times the first
        // one is read (that would pin an implementation detail).
        $this->assertContains('a', $fields->reads);
        $this->assertNotContains('b', $fields->reads, 'The AND must short-circuit before the second child.');
    }

    public function test_an_or_group_stops_evaluating_after_the_first_true(): void
    {
        $fields = $this->readSpy(['a' => 'x', 'b' => 'x']);

        $this->assertTrue($this->engine->passes([
            'logic' => 'or',
            'children' => [
                $this->cond('fields.a', 'text', [$this->op('text_equals', ['value' => 'x'])]),
                $this->cond('fields.b', 'text', [$this->op('text_is_not_empty')]),
            ],
        ], ['fields' => $fields]));

        $this->assertContains('a', $fields->reads);
        $this->assertNotContains('b', $fields->reads, 'The OR must short-circuit before the second child.');
    }

    /**
     * A payload BRANCH that records which keys were read. This mocks nothing the engine owns: Arr::get
     * walks an ArrayAccess exactly as it walks a nested array, so the spy observes only the public fact
     * "did the engine look this source up?" — the one way group LAZINESS is visible from outside.
     *
     * @param  array<string, mixed>  $values
     */
    private function readSpy(array $values): object
    {
        return new class($values) implements ArrayAccess
        {
            /** @var array<int, string> */
            public array $reads = [];

            /** @param array<string, mixed> $values */
            public function __construct(private array $values) {}

            public function offsetExists(mixed $offset): bool
            {
                $this->reads[] = (string) $offset;

                return array_key_exists($offset, $this->values);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->values[$offset] ?? null;
            }

            public function offsetSet(mixed $offset, mixed $value): void {}

            public function offsetUnset(mixed $offset): void {}
        };
    }

    // ── the never-throws contract, over the WHOLE type enum ───────────────────

    public function test_a_descriptor_only_source_type_fails_closed_instead_of_escaping_the_never_throws_contract(): void
    {
        // FIXED (safety batch B0.5 — this pin used to assert the UnhandledMatchError below).
        // WorkflowVariableType carries two DESCRIPTOR-ONLY cases (`time`, `object`) past its closed
        // 7-type core, and the executor's normalizeInput() matched that core with NO default arm.
        // tryFrom() accepts them, so a stored tree naming one raised an UnhandledMatchError straight out
        // of a gate whose documented contract is that it NEVER throws — a 500 during form submission.
        // WorkflowConditionTreeValidator rejects such a source_type at write time, so it was only
        // reachable through a hand-written / legacy / imported row — a real row, not a hypothetical.
        //
        // The engine is now TOTAL over its own type enum: a base type it has no runtime semantics for is
        // an ordinary fail-closed failure, so the condition is simply false.
        foreach (['time', 'object'] as $descriptorOnlyType) {
            $this->assertFalse($this->engine->passes(
                ['logic' => 'and', 'children' => [$this->cond('v', $descriptorOnlyType, [$this->op('text_is_not_empty')])]],
                ['v' => 'x'],
            ));
        }
    }

    public function test_a_descriptor_only_source_type_is_false_for_every_pipeline_shape(): void
    {
        // The type is rejected BEFORE the pipeline runs, so no shape of pipeline can rescue it — most
        // importantly the presence family, which reads EMPTINESS and would otherwise answer boolean TRUE
        // for a value the engine cannot even represent (an unreadable TYPE is not an empty VALUE). That
        // distinction is the difference between failing closed and OPENING the gate on a legacy row.
        foreach (['time', 'object'] as $type) {
            $this->assertFalse($this->pipe($type, 'x', []));                                  // bare source
            $this->assertFalse($this->pipe($type, 'x', [$this->op('is_null')]));              // leading presence op
            $this->assertFalse($this->pipe($type, '', [$this->op('is_null')]));
            $this->assertFalse($this->pipe($type, 'x', [$this->op('is_present')]));
            $this->assertFalse($this->pipe($type, null, [$this->op('coalesce', ['fallback' => 'x']), $this->op('text_is_not_empty')]));
        }

        // The SUPPORTED types are untouched: an unrepresentable VALUE of a real type still reads as
        // empty to the presence family, exactly as before (this is the behaviour that must NOT move).
        $this->assertTrue($this->pipe('number', 'not-a-number', [$this->op('is_null')]));
        $this->assertTrue($this->pipe('text', null, [$this->op('is_null')]));
    }
}
