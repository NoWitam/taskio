<?php

namespace Tests\Unit\Variables;

use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Services\OperationExecutor;
use App\Modules\Variables\Support\CustomFunctionOperation;
use App\Modules\Variables\Support\FunctionScope;
use Tests\TestCase;

/**
 * CUSTOM-FUNCTION EXECUTION BY EXPANSION (Phase 3b) — the pure executor resolves a `fn:<uuid>` op through
 * the OperationResolver, binds {input + args} into a scope FRAME, and re-enters the body. These drive the
 * executor DIRECTLY with function VOs threaded through the run context under FunctionScope (no DB), so the
 * expansion, the frame STACK (a body's own map sees element/index AND the enclosing input/args), and the
 * three fail-CLOSED backstops (depth cap, active-function visited-set, absent function) are all pinned in
 * isolation. The function VOs are hand-built to also cover CORRUPTED rows the write-validator would reject
 * (a cycle, a wrong return type) — the runtime must fail those closed regardless.
 */
class OperationExecutorFunctionTest extends TestCase
{
    private OperationExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = new OperationExecutor;
    }

    /** A function VO (bypassing persistence): id `fn:<uuid>`, a body pipeline, and named arg types. */
    private function fn(string $uuid, VariableType $input, VariableType $return, array $body, array $argTypes = []): CustomFunctionOperation
    {
        return new CustomFunctionOperation($uuid, $input, $return, [], $body, $argTypes);
    }

    /** A run context carrying the given functions under FunctionScope (depth 0, no chain, no frame). */
    private function ctx(CustomFunctionOperation ...$functions): array
    {
        return FunctionScope::forFunctions($functions)->writeInto([]);
    }

    /** One pipeline step. */
    private function op(string $id, array $args = []): array
    {
        return ['op' => $id, 'args' => $args];
    }

    /** A scope reference union (a body reads its input/args this way). */
    private function scope(string $path, string $type, array $pipeline = []): array
    {
        $union = ['kind' => 'variable', 'ref' => ['source' => 'scope', 'path' => $path, 'type' => $type]];

        return $pipeline === [] ? $union : $union + ['pipeline' => $pipeline];
    }

    // ---- end-to-end value pipelines -------------------------------------------

    public function test_a_function_executes_on_a_value_pipeline(): void
    {
        // fn:upper = text -> text, body uppercases the input. A pipeline of just that op transforms the base.
        $upper = $this->fn('upper', VariableType::TEXT, VariableType::TEXT, [$this->op('text_uppercase')]);

        $result = $this->executor->execute('hello', VariableType::TEXT, [$this->op('fn:upper')], $this->ctx($upper));

        $this->assertFalse($result->failed);
        $this->assertSame(VariableType::TEXT, $result->type);
        $this->assertSame('HELLO', $result->value);
    }

    public function test_a_function_binds_and_reads_a_named_arg(): void
    {
        // fn:greet(input: text, suffix: text) body appends the `suffix` ARG (read as a scope ref) to input.
        $greet = $this->fn('greet', VariableType::TEXT, VariableType::TEXT, [
            $this->op('text_append', ['value' => $this->scope('suffix', 'text')]),
        ], ['suffix' => VariableType::TEXT]);

        $result = $this->executor->execute('hi', VariableType::TEXT, [
            $this->op('fn:greet', ['suffix' => '!!!']),
        ], $this->ctx($greet));

        $this->assertFalse($result->failed);
        $this->assertSame('hi!!!', $result->value);
    }

    public function test_a_boolean_function_yields_its_terminal_for_a_gate(): void
    {
        // fn:isTaskio = text -> boolean. This is the shape a CONDITION uses (the gate reads the boolean).
        $isTaskio = $this->fn('isTaskio', VariableType::TEXT, VariableType::BOOLEAN, [
            $this->op('text_equals', ['value' => 'Taskio']),
        ]);

        $hit = $this->executor->execute('Taskio', VariableType::TEXT, [$this->op('fn:isTaskio')], $this->ctx($isTaskio));
        $miss = $this->executor->execute('Other', VariableType::TEXT, [$this->op('fn:isTaskio')], $this->ctx($isTaskio));

        $this->assertTrue($hit->value === true && $hit->type === VariableType::BOOLEAN);
        $this->assertTrue($miss->value === false && $miss->type === VariableType::BOOLEAN);
    }

    public function test_a_nested_function_executes(): void
    {
        // fn:outer's body CALLS fn:inner (nesting): inner uppercases, outer just delegates to inner.
        $inner = $this->fn('inner', VariableType::TEXT, VariableType::TEXT, [$this->op('text_uppercase')]);
        $outer = $this->fn('outer', VariableType::TEXT, VariableType::TEXT, [$this->op('fn:inner')]);

        $result = $this->executor->execute('deep', VariableType::TEXT, [$this->op('fn:outer')], $this->ctx($inner, $outer));

        $this->assertFalse($result->failed);
        $this->assertSame('DEEP', $result->value);
    }

    public function test_a_body_maps_over_the_input_with_a_function_arg_in_scope(): void
    {
        // THE FRAME STACK: fn:suffixEach(input: multi, suffix: text) maps over the INPUT array; the map's
        // element pipeline references the enclosing function's `suffix` ARG — so BOTH the map's element
        // (the running value in the element pipeline) AND the function frame's arg are in scope at once.
        $suffixEach = $this->fn('suffixEach', VariableType::MULTI, VariableType::MULTI, [
            $this->op('array_map', [
                'pipeline' => [
                    $this->op('text_append', ['value' => $this->scope('suffix', 'text')]),
                ],
            ]),
        ], ['suffix' => VariableType::TEXT]);

        $result = $this->executor->execute(['a', 'b', 'c'], VariableType::MULTI, [
            $this->op('fn:suffixEach', ['suffix' => '-x']),
        ], $this->ctx($suffixEach));

        $this->assertFalse($result->failed);
        $this->assertSame(VariableType::MULTI, $result->type);
        $this->assertSame(['a-x', 'b-x', 'c-x'], $result->value);
    }

    public function test_a_body_filters_the_input_with_a_function_arg_in_scope(): void
    {
        // THE FRAME STACK on a FILTER (runtime twin of the A1 write headline): fn:keep(input: multi,
        // needle: text) filters the INPUT array, keeping the elements EQUAL to the enclosing `needle` ARG —
        // the element (per iteration) AND the function frame's arg are both in scope in the predicate.
        $keep = $this->fn('keep', VariableType::MULTI, VariableType::MULTI, [
            $this->op('array_filter', [
                'pipeline' => [
                    $this->op('enum_is', ['value' => $this->scope('needle', 'text')]),
                ],
            ]),
        ], ['needle' => VariableType::TEXT]);

        $result = $this->executor->execute(['a', 'b', 'a'], VariableType::MULTI, [
            $this->op('fn:keep', ['needle' => 'a']),
        ], $this->ctx($keep));

        $this->assertFalse($result->failed);
        $this->assertSame(VariableType::MULTI, $result->type);
        $this->assertSame(['a', 'a'], $result->value);
    }

    public function test_a_body_reduces_the_input_with_a_function_arg_in_scope(): void
    {
        // THE FRAME STACK on a REDUCE (runtime twin of the A1 reduce write test): fn:sumFactor(input: multi,
        // factor: number) folds the INPUT, adding the enclosing `factor` ARG once per element — the reducer
        // (rooted at the accumulator) resolves BOTH the element scope AND the function frame's arg.
        $sum = $this->fn('sumFactor', VariableType::MULTI, VariableType::NUMBER, [
            $this->op('array_reduce', [
                'seed' => ['type' => 'number', 'value' => 0],
                'reducer' => [
                    $this->op('num_add', ['value' => $this->scope('factor', 'number')]),
                ],
            ]),
        ], ['factor' => VariableType::NUMBER]);

        $result = $this->executor->execute(['a', 'b', 'c'], VariableType::MULTI, [
            $this->op('fn:sumFactor', ['factor' => 2]),
        ], $this->ctx($sum));

        $this->assertFalse($result->failed);
        $this->assertSame(VariableType::NUMBER, $result->type);
        $this->assertEquals(6, $result->value);
    }

    public function test_a_function_runs_inside_a_match_to_choice_rule_condition(): void
    {
        // The `when` of a match_to_choice rule is a boolean-terminal sub-run over the op's TEXT input — a
        // re-entrant call that must inherit the FunctionScope so a rule condition may itself call a function.
        $isTaskio = $this->fn('isTaskio', VariableType::TEXT, VariableType::BOOLEAN, [
            $this->op('text_equals', ['value' => 'Taskio']),
        ]);

        $pipeline = [$this->op('match_to_choice', [
            'rules' => [['when' => [$this->op('fn:isTaskio')], 'then' => 'high']],
            'fallback' => 'low',
        ])];

        $hit = $this->executor->execute('Taskio', VariableType::TEXT, $pipeline, $this->ctx($isTaskio));
        $miss = $this->executor->execute('Other', VariableType::TEXT, $pipeline, $this->ctx($isTaskio));

        $this->assertSame('high', $hit->value);
        $this->assertSame('low', $miss->value);
    }

    // ---- fail-closed backstops (non-negotiable) -------------------------------

    public function test_a_five_deep_function_chain_executes_but_a_deeper_one_fails_closed(): void
    {
        // Distinct functions fn0 -> fn1 -> … chained by body. A chain up to the cap succeeds; one function
        // DEEPER than MAX_FUNCTION_EXPANSION_DEPTH (5) fails CLOSED at the over-cap expansion (no loop).
        $chain = fn (int $length): array => collect(range(0, $length - 1))
            ->map(fn (int $i): CustomFunctionOperation => $this->fn(
                "fn{$i}",
                VariableType::TEXT,
                VariableType::TEXT,
                // The last link does real work; every earlier link just calls the next.
                $i === $length - 1 ? [$this->op('text_uppercase')] : [$this->op('fn:fn' . ($i + 1))],
            ))
            ->all();

        // 5 nested expansions is exactly the cap → succeeds.
        $ok = $this->executor->execute('go', VariableType::TEXT, [$this->op('fn:fn0')], $this->ctx(...$chain(5)));
        $this->assertFalse($ok->failed);
        $this->assertSame('GO', $ok->value);

        // 6 links needs a 6th expansion (depth 5 → 6, over the cap) → fails CLOSED.
        $over = $this->executor->execute('go', VariableType::TEXT, [$this->op('fn:fn0')], $this->ctx(...$chain(6)));
        $this->assertTrue($over->failed);
    }

    public function test_a_corrupted_cycle_is_caught_by_the_visited_set(): void
    {
        // A -> B -> A: a cycle the write-time acyclic check would reject, hand-built to simulate a corrupted
        // / raced row. The active-function visited-set catches the re-entry of A at depth 2 (well under the
        // depth cap) → fail CLOSED, never a loop.
        $a = $this->fn('A', VariableType::TEXT, VariableType::TEXT, [$this->op('fn:B')]);
        $b = $this->fn('B', VariableType::TEXT, VariableType::TEXT, [$this->op('fn:A')]);

        $result = $this->executor->execute('x', VariableType::TEXT, [$this->op('fn:A')], $this->ctx($a, $b));

        $this->assertTrue($result->failed);
    }

    public function test_a_direct_self_cycle_is_caught_by_the_visited_set(): void
    {
        $self = $this->fn('self', VariableType::TEXT, VariableType::TEXT, [$this->op('fn:self')]);

        $result = $this->executor->execute('x', VariableType::TEXT, [$this->op('fn:self')], $this->ctx($self));

        $this->assertTrue($result->failed);
    }

    public function test_an_absent_or_deleted_function_fails_closed(): void
    {
        $present = $this->fn('present', VariableType::TEXT, VariableType::TEXT, [$this->op('text_uppercase')]);

        // `fn:gone` is not among the threaded functions (deleted / cross-workspace) → resolves null → fail closed.
        $result = $this->executor->execute('x', VariableType::TEXT, [$this->op('fn:gone')], $this->ctx($present));

        $this->assertTrue($result->failed);
    }

    public function test_a_function_with_no_threaded_functions_fails_closed(): void
    {
        // The bare executor (no FunctionScope in context) resolves a `fn:` id to null — byte-identical to
        // the old Operation::tryFrom for a non-built-in — so a function op fails closed with none threaded.
        $result = $this->executor->execute('x', VariableType::TEXT, [$this->op('fn:anything')]);

        $this->assertTrue($result->failed);
    }

    public function test_a_body_whose_terminal_type_is_not_the_return_type_fails_closed(): void
    {
        // A corrupted row: declared return NUMBER, but the body produces TEXT. The write-validator forces
        // these equal; the runtime VERIFIES it and fails CLOSED on a mismatch rather than coercing a wrong
        // value that could open a gate.
        $wrong = $this->fn('wrong', VariableType::TEXT, VariableType::NUMBER, [$this->op('text_uppercase')]);

        $result = $this->executor->execute('hi', VariableType::TEXT, [$this->op('fn:wrong')], $this->ctx($wrong));

        $this->assertTrue($result->failed);
    }

    public function test_a_function_input_gate_rejects_a_mismatched_running_type(): void
    {
        // fn:num expects NUMBER; running a TEXT value into it fails the input gate CLOSED (like any op).
        $num = $this->fn('num', VariableType::NUMBER, VariableType::NUMBER, [$this->op('num_abs')]);

        $result = $this->executor->execute('not-a-number', VariableType::TEXT, [$this->op('fn:num')], $this->ctx($num));

        $this->assertTrue($result->failed);
    }
}
