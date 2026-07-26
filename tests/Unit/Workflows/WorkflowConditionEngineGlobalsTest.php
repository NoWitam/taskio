<?php

namespace Tests\Unit\Workflows;

use App\Modules\Variables\Services\OperationExecutor as WorkflowOperationExecutor;
use App\Modules\Workflows\Services\WorkflowConditionEngine;
use App\Modules\Workflows\Services\WorkflowConditionEvaluator;
use App\Modules\Workflows\Services\WorkflowVariableCatalogService;
use App\Modules\Workflows\Services\WorkflowVariableResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\ScriptedWorkflowAiTextService;
use Tests\TestCase;

/**
 * GLOBALS AS CONDITION SOURCES (B6) — a workflow can be gated on a workspace constant, not only on the
 * submitted form.
 *
 * THE SOURCE VOCABULARY (one rule, two roots):
 *   - `fields.<id>`            a field of the trigger form, read off the BARE trigger payload. Legacy,
 *                              unprefixed, unchanged — it is what every stored row carries.
 *   - `globals.<key>[.<sub>]`  a workspace global, read off the globals map. The FULL catalog path,
 *                              because a global's catalog source IS its root — no new spelling.
 * Nothing else is a condition source: `trigger.*` system vars are out of scope and `steps.*` cannot be
 * one (no step has run when the gate is evaluated).
 *
 * THE COST GUARD is pinned here rather than in a comment: the workspace globals are a DB read, so the
 * engine must not touch them for the common gate (a form-field condition with literal arguments), must
 * read them at most ONCE per evaluation however many conditions want them, and must never carry one
 * evaluation's map into the next (a queue worker serves many workspaces). The catalog is a strict mock,
 * so an unexpected call fails the test rather than silently costing a query.
 */
class WorkflowConditionEngineGlobalsTest extends TestCase
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

    /** One condition leaf; $extra merges on (e.g. a `default`). */
    private function cond(string $source, string $type, array $pipeline, array $extra = []): array
    {
        return array_merge(
            ['kind' => 'condition', 'source' => $source, 'source_type' => $type, 'pipeline' => $pipeline],
            $extra,
        );
    }

    /** Evaluate a one-condition tree over $payload. */
    private function gate(array $condition, array $payload = []): bool
    {
        return $this->engine->passes(['logic' => 'and', 'children' => [$condition]], $payload);
    }

    /** The workspace globals the catalog will hand the engine for this test. */
    private function globals(array $values, int $times = 1): void
    {
        $this->catalog->shouldReceive('globalValues')->times($times)->andReturn($values);
    }

    // ── a global as the condition SOURCE ──────────────────────────────────────

    public function test_a_scalar_global_opens_and_closes_the_gate(): void
    {
        $this->globals(['nazwa_marki' => 'Taskio'], times: 2);

        $this->assertTrue($this->gate($this->cond('globals.nazwa_marki', 'text', [
            $this->op('text_equals', ['value' => 'Taskio']),
        ])));

        $this->assertFalse($this->gate($this->cond('globals.nazwa_marki', 'text', [
            $this->op('text_equals', ['value' => 'Inna marka']),
        ])));
    }

    public function test_a_global_is_typed_like_any_other_source(): void
    {
        // A number global flows through the number vocabulary and a multi (array<scalar>) global through
        // the multi vocabulary — a global source is not a second type system.
        $this->globals(['budzet' => 5000, 'hashtagi' => ['#ai', '#automatyzacja']], times: 2);

        $this->assertTrue($this->gate($this->cond('globals.budzet', 'number', [
            $this->op('num_gte', ['value' => 1000]),
        ])));

        $this->assertTrue($this->gate($this->cond('globals.hashtagi', 'multi', [
            $this->op('multi_includes', ['value' => '#ai']),
        ])));
    }

    public function test_an_object_globals_scalar_leaf_is_addressable(): void
    {
        // Only the LEAVES of an object global are conditionable (the write-validator offers exactly
        // those); the runtime reads one with the same dotted lookup the resolver uses.
        $this->globals(['firma' => ['nazwa' => 'Taskio', 'miasto' => 'Warszawa']]);

        $this->assertTrue($this->gate($this->cond('globals.firma.miasto', 'text', [
            $this->op('text_equals', ['value' => 'Warszawa']),
        ])));
    }

    public function test_a_global_that_does_not_exist_fails_closed(): void
    {
        // The missing-path doctrine applies to the globals root exactly as to the payload: not even the
        // presence family can observe an absent global (its pipeline never runs).
        $this->globals(['nazwa_marki' => 'Taskio'], times: 2);

        $this->assertFalse($this->gate($this->cond('globals.nie_istnieje', 'text', [$this->op('is_null')])));
        $this->assertFalse($this->gate($this->cond('globals.nie_istnieje', 'text', [$this->op('text_is_empty')])));
    }

    public function test_a_globals_source_reads_the_globals_map_and_never_the_payload(): void
    {
        // A submitted form could answer a field literally named `globals` — it lives under the payload
        // (i.e. under the context's `trigger` root), so it can never be mistaken for a workspace global.
        $this->globals(['brand' => 'Taskio']);

        $this->assertFalse($this->gate(
            $this->cond('globals.brand', 'text', [$this->op('text_equals', ['value' => 'Spoofed'])]),
            ['globals' => ['brand' => 'Spoofed']],
        ));
    }

    public function test_a_global_source_honours_the_opt_in_default(): void
    {
        // Capabilities 1 + 3 compose: an absent global takes the condition's own default, if it has one.
        $this->globals([]);

        $this->assertTrue($this->gate($this->cond(
            'globals.nie_istnieje',
            'text',
            [$this->op('text_equals', ['value' => 'fallback'])],
            ['default' => 'fallback'],
        )));
    }

    // ── a global as an ARGUMENT ───────────────────────────────────────────────

    public function test_a_form_field_can_be_compared_against_a_global(): void
    {
        // The other half of the capability: the SOURCE is a form field and the ARGUMENT is a global —
        // "does this submission's budget exceed the workspace ceiling?".
        $this->globals(['limit' => 1000], times: 2);

        $argument = ['kind' => 'variable', 'ref' => ['source' => 'globals', 'path' => 'limit', 'type' => 'number']];

        $this->assertTrue($this->engine->passes([
            'logic' => 'and',
            'children' => [$this->cond('fields.budget', 'number', [$this->op('num_gt', ['value' => $argument])])],
        ], ['fields' => ['budget' => 5000]]));

        $this->assertFalse($this->engine->passes([
            'logic' => 'and',
            'children' => [$this->cond('fields.budget', 'number', [$this->op('num_gt', ['value' => $argument])])],
        ], ['fields' => ['budget' => 100]]));
    }

    // ── the COST GUARD ────────────────────────────────────────────────────────

    public function test_the_workspace_globals_are_never_read_for_a_gate_that_references_none(): void
    {
        // THE common path: a form-field condition with literal arguments must cost exactly what it
        // always did — no context, no resolver, and above all no query.
        $this->catalog->shouldNotReceive('globalValues');

        $this->assertTrue($this->engine->passes([
            'logic' => 'or',
            'children' => [
                $this->cond('fields.a', 'text', [$this->op('text_equals', ['value' => 'x'])]),
                ['kind' => 'group', 'logic' => 'and', 'children' => [
                    $this->cond('fields.n', 'number', [$this->op('num_gt', ['value' => 3])]),
                ]],
            ],
        ], ['fields' => ['a' => 'x', 'n' => 5]]));
    }

    public function test_the_workspace_globals_are_not_read_for_an_argument_that_references_only_the_trigger(): void
    {
        // An arg-variable DOES need a run context — but only its `trigger` tier. The globals tier is
        // gated separately on a `globals` ref root, so a field-to-field comparison stays query-free.
        $this->catalog->shouldNotReceive('globalValues');

        $this->assertTrue($this->engine->passes([
            'logic' => 'and',
            'children' => [$this->cond('fields.a', 'text', [
                $this->op('text_equals', ['value' => ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.b', 'type' => 'text']]]),
            ])],
        ], ['fields' => ['a' => 'match', 'b' => 'match']]));
    }

    public function test_the_workspace_globals_are_read_at_most_once_per_evaluation(): void
    {
        // Three conditions across two groups all want globals; the context is memoized for the call.
        $this->globals(['brand' => 'Taskio', 'budzet' => 5000], times: 1);

        $this->assertTrue($this->engine->passes([
            'logic' => 'and',
            'children' => [
                $this->cond('globals.brand', 'text', [$this->op('text_equals', ['value' => 'Taskio'])]),
                $this->cond('globals.budzet', 'number', [$this->op('num_gt', ['value' => 100])]),
                ['kind' => 'group', 'logic' => 'or', 'children' => [
                    $this->cond('fields.x', 'text', [
                        $this->op('text_equals', ['value' => ['kind' => 'variable', 'ref' => ['source' => 'globals', 'path' => 'brand', 'type' => 'text']]]),
                    ]),
                ]],
            ],
        ], ['fields' => ['x' => 'Taskio']]));
    }

    public function test_one_evaluations_globals_never_leak_into_the_next(): void
    {
        // A dispatch evaluates many workflows through ONE engine instance, and a queue worker serves
        // many workspaces: the memo is per passes() call, so each evaluation re-reads its own globals.
        $this->catalog->shouldReceive('globalValues')->twice()->andReturn(['brand' => 'Taskio'], ['brand' => 'Inna marka']);

        $condition = $this->cond('globals.brand', 'text', [$this->op('text_equals', ['value' => 'Taskio'])]);

        $this->assertTrue($this->gate($condition));
        $this->assertFalse($this->gate($condition));
    }

    public function test_a_globals_read_that_fails_is_an_ordinary_fail_closed_false(): void
    {
        // The gate NEVER throws into a form submission: a globals read that blows up (a broken tenant
        // connection, say) collapses the condition rather than the user's submit.
        $this->catalog->shouldReceive('globalValues')->andThrow(new \RuntimeException('db down'));

        $this->assertFalse($this->gate($this->cond('globals.brand', 'text', [$this->op('text_is_not_empty')])));
    }

    public function test_a_failing_globals_read_is_attempted_once_per_call_and_logged_once(): void
    {
        // A failing read used to be INVISIBLE (indistinguishable from an ordinary closed gate) and was
        // NOT memoized — the context memo keys on the `globals` entry existing, which a throw never
        // creates, so every globals-needing condition in the tree re-entered the read and a DB outage
        // cost N failing queries per evaluation. Now: ONE attempt, ONE warning, still fail-closed.
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $context === [
                'workflow_id' => 'wf-1',
                'exception' => RuntimeException::class,
            ],
        );

        $this->catalog->shouldReceive('globalValues')->once()->andThrow(new RuntimeException('db down'));

        $this->assertFalse($this->engine->passes([
            'logic' => 'or',
            'children' => [
                $this->cond('globals.brand', 'text', [$this->op('text_is_not_empty')]),
                $this->cond('globals.budzet', 'number', [$this->op('num_gt', ['value' => 1])]),
                // …including one that would take its opt-in DEFAULT over an empty globals map: a
                // memoized failure must fail closed, never degrade into "the global is simply absent".
                $this->cond('globals.brand', 'text', [$this->op('text_equals', ['value' => 'fallback'])], ['default' => 'fallback']),
                $this->cond('fields.x', 'text', [
                    $this->op('text_equals', ['value' => ['kind' => 'variable', 'ref' => ['source' => 'globals', 'path' => 'brand', 'type' => 'text']]]),
                ]),
            ],
        ], ['fields' => ['x' => 'Taskio']], 'wf-1'));
    }

    public function test_the_failure_log_carries_no_payload_or_global_data(): void
    {
        // The warning is an OPERATIONS signal, not a data dump: a global is a user-authored constant
        // and the payload is a form submission, so neither may ever reach the log — only the workflow
        // id and the exception CLASS (not even its message, which can quote a query).
        $logged = [];
        Log::shouldReceive('warning')->once()->andReturnUsing(function (string $message, array $context) use (&$logged): void {
            $logged = ['message' => $message, 'context' => $context];
        });

        $this->catalog->shouldReceive('globalValues')->andThrow(new RuntimeException('SQLSTATE: brand=Taskio'));

        $this->assertFalse($this->engine->passes(
            ['logic' => 'and', 'children' => [$this->cond('globals.brand', 'text', [$this->op('text_is_not_empty')])]],
            ['fields' => ['secret' => 'PESEL 90010112345']],
            'wf-2',
        ));

        $this->assertSame(['workflow_id' => 'wf-2', 'exception' => RuntimeException::class], $logged['context']);
        $this->assertStringNotContainsString('Taskio', $logged['message']);
        $this->assertStringNotContainsString('PESEL', $logged['message']);
    }

    public function test_a_healthy_gate_logs_nothing(): void
    {
        // No warning for the ordinary path — the signal stays rare enough to be worth alerting on.
        Log::shouldReceive('warning')->never();

        $this->globals(['brand' => 'Taskio']);

        $this->assertTrue($this->gate($this->cond('globals.brand', 'text', [$this->op('text_equals', ['value' => 'Taskio'])])));
    }
}
