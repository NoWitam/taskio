<?php

namespace Tests\Unit\Workflows;

use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Services\OperationExecutor as WorkflowOperationExecutor;
use App\Modules\Variables\Support\CustomFunctionOperation;
use App\Modules\Workflows\Services\WorkflowConditionEngine;
use App\Modules\Workflows\Services\WorkflowConditionEvaluator;
use App\Modules\Workflows\Services\WorkflowVariableCatalogService;
use App\Modules\Workflows\Services\WorkflowVariableResolver;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\ScriptedWorkflowAiTextService;
use Tests\TestCase;

/**
 * CUSTOM FUNCTIONS AS CONDITION LOGIC (Phase 3b) — a workflow gate can call a boolean-returning custom
 * function, so a reusable transform can decide a condition. The engine resolves + EXECUTES the `fn:<uuid>`
 * op through the shared executor (by expansion), reading the boolean terminal exactly like a built-in.
 *
 * FAIL-CLOSED is the point (a gate must never OPEN on unevaluable logic): an absent/deleted function, or a
 * function whose terminal is not boolean, collapses the condition to false.
 *
 * THE COST GUARD is pinned like the globals one: the catalog (a DB read) is asked for the workspace
 * functions ONLY when a condition actually references a `fn:` op — never for the common gate. The catalog
 * is a strict mock, so an unexpected call is a test failure.
 */
class WorkflowConditionEngineFunctionTest extends TestCase
{
    private WorkflowConditionEngine $engine;

    private MockInterface $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $executor = new WorkflowOperationExecutor;
        $this->catalog = Mockery::mock(WorkflowVariableCatalogService::class);

        $this->engine = new WorkflowConditionEngine(
            $executor,
            new WorkflowConditionEvaluator,
            new WorkflowVariableResolver($executor, new ScriptedWorkflowAiTextService),
            $this->catalog,
        );

        Carbon::setTestNow('2026-07-14 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function op(string $id, array $args = []): array
    {
        return ['op' => $id, 'args' => $args];
    }

    private function cond(string $source, string $type, array $pipeline): array
    {
        return ['kind' => 'condition', 'source' => $source, 'source_type' => $type, 'pipeline' => $pipeline];
    }

    private function gate(array $condition, array $payload = []): bool
    {
        return $this->engine->passes(['logic' => 'and', 'children' => [$condition]], $payload);
    }

    /** The workspace functions the catalog hands the engine (a `fn:` op must be referenced first). */
    private function functions(array $functions, int $times = 1): void
    {
        $this->catalog->shouldReceive('customFunctionOperations')->times($times)->andReturn($functions);
    }

    private function fn(string $uuid, VariableType $input, VariableType $return, array $body): CustomFunctionOperation
    {
        return new CustomFunctionOperation($uuid, $input, $return, [], $body, []);
    }

    // ── a boolean function decides the gate ───────────────────────────────────

    public function test_a_boolean_function_opens_and_closes_the_gate(): void
    {
        // fn:isTaskio(text) -> boolean: "is the brand exactly Taskio". Used as the whole condition pipeline.
        $isTaskio = $this->fn('isTaskio', VariableType::TEXT, VariableType::BOOLEAN, [
            $this->op('text_equals', ['value' => 'Taskio']),
        ]);
        $this->functions([$isTaskio], times: 2);

        $this->assertTrue($this->gate(
            $this->cond('fields.brand', 'text', [$this->op('fn:isTaskio')]),
            ['fields' => ['brand' => 'Taskio']],
        ));

        $this->assertFalse($this->gate(
            $this->cond('fields.brand', 'text', [$this->op('fn:isTaskio')]),
            ['fields' => ['brand' => 'Inna marka']],
        ));
    }

    public function test_an_absent_function_condition_fails_closed(): void
    {
        // The pipeline references a function that is not among the workspace functions (deleted / renamed
        // uuid). It resolves to null → the executor fails closed → the gate stays SHUT, never opens.
        $this->functions([], times: 1);

        $this->assertFalse($this->gate(
            $this->cond('fields.brand', 'text', [$this->op('fn:00000000-0000-0000-0000-000000000000')]),
            ['fields' => ['brand' => 'Taskio']],
        ));
    }

    public function test_a_non_boolean_function_terminal_fails_the_gate_closed(): void
    {
        // fn:shout returns TEXT, not boolean. A condition demands a boolean terminal, so a text-returning
        // function can never open the gate (fail closed) — even though it "ran" successfully.
        $shout = $this->fn('shout', VariableType::TEXT, VariableType::TEXT, [$this->op('text_uppercase')]);
        $this->functions([$shout], times: 1);

        $this->assertFalse($this->gate(
            $this->cond('fields.brand', 'text', [$this->op('fn:shout')]),
            ['fields' => ['brand' => 'Taskio']],
        ));
    }

    public function test_the_cost_guard_never_reads_functions_for_a_function_less_gate(): void
    {
        // A plain field condition (no `fn:` op) must never ask the catalog for functions or globals — the
        // strict mock turns any such call into a failure.
        $this->catalog->shouldNotReceive('customFunctionOperations');
        $this->catalog->shouldNotReceive('globalValues');

        $this->assertTrue($this->gate(
            $this->cond('fields.brand', 'text', [$this->op('text_equals', ['value' => 'Taskio'])]),
            ['fields' => ['brand' => 'Taskio']],
        ));
    }
}
