<?php

namespace Tests\Unit\Workflows;

use App\Modules\Variables\Services\OperationExecutor as WorkflowOperationExecutor;
use App\Modules\Variables\Services\VariableResolver as WorkflowVariableResolver;
use App\Modules\Workflows\Services\WorkflowConditionEngine;
use App\Modules\Workflows\Services\WorkflowConditionEvaluator;
use App\Modules\Workflows\Services\WorkflowVariableCatalogService;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\ScriptedWorkflowAiTextService;
use Tests\TestCase;

/**
 * ARRAY-of-* condition SOURCES at RUNTIME (F2) — a repeater (array<object>) form field and an
 * array<object> workspace global are condition sources riding the `multi` wire type. The gate filters
 * the object rows by an element subfield (a scope-rooted element pipeline resolved PER ROW inside the
 * executor, NEVER pre-resolved against the run context), counts the survivors, and compares.
 *
 * FAIL-CLOSED throughout (this IS the trigger gate): an absent source, or a pipeline that cannot be
 * evaluated, keeps the gate CLOSED — a repeater condition can never open the gate on structure alone.
 *
 * The catalog is a strict mock: the workspace globals are a DB read, so a FORM-field source must never
 * touch it (an unexpected call fails the test), while a `globals.*` source reads it exactly ONCE per
 * evaluation (the cost guard).
 */
class WorkflowConditionEngineArraySourceTest extends TestCase
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

    /** One pipeline step. */
    private function op(string $id, array $args = []): array
    {
        return ['op' => $id, 'args' => $args];
    }

    /**
     * A repeater condition: keep rows where `element.amount > $threshold`, then require the survivor COUNT
     * to be `> $min`. The element pipeline is a scope-rooted union over the object subfield.
     */
    private function repeaterCondition(string $source, int $threshold, int $min): array
    {
        return [
            'kind' => 'condition',
            'source' => $source,
            'source_type' => 'multi',
            'pipeline' => [
                ['op' => 'array_filter', 'args' => ['pipeline' => [
                    'kind' => 'variable',
                    'ref' => ['source' => 'scope', 'path' => 'element.amount', 'type' => 'number'],
                    'pipeline' => [$this->op('num_gt', ['value' => $threshold])],
                ]]],
                $this->op('array_count'),
                $this->op('num_gt', ['value' => $min]),
            ],
        ];
    }

    /** Evaluate a one-condition tree over $payload. */
    private function gate(array $condition, array $payload = []): bool
    {
        return $this->engine->passes(['logic' => 'and', 'children' => [$condition]], $payload);
    }

    public function test_a_repeater_form_condition_filters_counts_and_gates(): void
    {
        // A FORM-field source needs no globals — the catalog must never be consulted.
        $this->catalog->shouldNotReceive('globalValues');

        $payload = ['fields' => ['line_items' => [['amount' => 150], ['amount' => 50], ['amount' => 200]]]];

        // 2 rows exceed 100 → count 2 > 1 → gate OPEN.
        $this->assertTrue($this->gate($this->repeaterCondition('fields.line_items', 100, 1), $payload));

        // Same rows, but the gate demands MORE than 5 survivors → 2 is not > 5 → gate CLOSED (evaluable-false).
        $this->assertFalse($this->gate($this->repeaterCondition('fields.line_items', 100, 5), $payload));
    }

    public function test_a_repeater_condition_fails_closed_when_the_source_is_absent(): void
    {
        $this->catalog->shouldNotReceive('globalValues');

        // The repeater field is simply not in the submission → MISSING → the gate stays CLOSED (fail-closed),
        // never reaching the pipeline (so no threshold could open it).
        $this->assertFalse($this->gate($this->repeaterCondition('fields.line_items', 0, -1), []));
    }

    public function test_an_array_of_object_global_condition_filters_counts_and_gates(): void
    {
        // F2: an array<object> global (a repeater-like constant) gates the SAME way, read off the globals
        // map. The map is read ONCE per evaluation (the cost guard), so the two gate() calls read it twice.
        $this->catalog->shouldReceive('globalValues')->times(2)
            ->andReturn(['pozycje' => [['amount' => 150], ['amount' => 50], ['amount' => 200]]]);

        $this->assertTrue($this->gate($this->repeaterCondition('globals.pozycje', 100, 1)));
        $this->assertFalse($this->gate($this->repeaterCondition('globals.pozycje', 100, 5)));
    }
}
