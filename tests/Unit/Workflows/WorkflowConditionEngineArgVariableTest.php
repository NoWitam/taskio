<?php

namespace Tests\Unit\Workflows;

use App\Modules\Variables\Enums\VariableType as WorkflowVariableType;
use App\Modules\Variables\Services\OperationExecutor as WorkflowOperationExecutor;
use App\Modules\Variables\Services\VariableResolver as WorkflowVariableResolver;
use App\Modules\Workflows\Services\WorkflowConditionEngine;
use App\Modules\Workflows\Services\WorkflowConditionEvaluator;
use App\Modules\Workflows\Services\WorkflowVariableCatalogService;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\Support\ScriptedWorkflowAiTextService;
use Tests\TestCase;

/**
 * The trigger gate's operation ARGUMENTS may be VARIABLES — a condition can compare a field against
 * ANOTHER field.
 *
 * ── WHAT THIS FILE WAS, AND WHY IT FLIPPED (B6) ──────────────────────────────────────────────────
 * It was written as a CHARACTERIZATION pin of a LIMITATION: arguments in a condition pipeline were
 * LITERAL-ONLY. Everywhere else in the module an argument may be a value-or-variable union
 * (`{kind:'variable', ref:{source,path,type}, pipeline?}`) that WorkflowVariableResolver pre-resolves
 * into a literal BEFORE the (pure) WorkflowOperationExecutor runs (resolvePipelineArgs) — but
 * WorkflowConditionEngine::evaluateCondition() called the executor DIRECTLY, so the raw union reached
 * the executor's argument readers and every condition carrying one failed CLOSED (the workflow simply
 * never fired). The file's own docblock said B6 would flip these expectations deliberately.
 *
 * THIS IS THAT FLIP. The gate now pre-resolves variable-shaped arguments through the SAME resolver
 * machinery the step runtime uses — a narrow public seam, WorkflowVariableResolver::resolveArgsFor-
 * Pipeline(), wrapping the identical private resolvePipelineArgs — so what an argument MEANS can never
 * drift between a gate and a step. Every `..._does_not_resolve_today_and_fails_closed` expectation
 * below became "resolves and behaves exactly like its literal control", and each test still asserts
 * the LITERAL control beside it so the union is isolated as the only variable.
 *
 * TWO THINGS DELIBERATELY DID NOT MOVE:
 *   - The executor's union guard (ValueOrVariable::isVariable, safety batch B0.5) stays as defence in
 *     depth: pre-resolution replaces every union with a literal before the executor, so no reader
 *     should ever see one again — but a legacy / hand-written / imported row that bypasses the gate's
 *     pre-resolution still must not have its union consumed AS DATA. The two GATE-OPENING repros
 *     (a field whose value is the literal string `variable`, and a source option named `kind`) are
 *     kept below and still assert FALSE — now because the union resolves to the REAL data, which does
 *     not match, rather than because it was rejected.
 *   - The CONTEXT SHAPE. The gate is handed the BARE trigger payload (`fields.<id>`), so it builds the
 *     standard run context `{trigger: payload, steps: [], globals: …}` and an argument ref uses the
 *     module-wide FULL path (`trigger.fields.<id>`) exactly as it does in a step config. `steps` is
 *     empty BY DESIGN (no step has run when the gate is evaluated) — pinned at the bottom.
 *
 * The catalog is a test double here: this file only ever references `trigger.*`, so the engine's cost
 * guard must never ask it for the workspace globals — an accidental call is an unexpected-call
 * failure, which is exactly the tripwire we want. The globals half of B6 lives in
 * WorkflowConditionEngineGlobalsTest.
 */
class WorkflowConditionEngineArgVariableTest extends TestCase
{
    private WorkflowConditionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $executor = new WorkflowOperationExecutor;

        $this->engine = new WorkflowConditionEngine(
            $executor,
            new WorkflowConditionEvaluator,
            new WorkflowVariableResolver($executor, new ScriptedWorkflowAiTextService),
            // No globals are referenced anywhere in this file, so any call here is a real defect.
            Mockery::mock(WorkflowVariableCatalogService::class),
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
     * The value-or-variable UNION an operation argument may carry. The ref is written the way the
     * resolver's whitelist expects it (rooted at trigger/steps/globals) — the gate wraps its bare
     * payload under `trigger`, so `fields.b` addresses the same answer the condition's own
     * `source: 'fields.b'` would.
     */
    private function argVariable(string $path, string $type = 'text', array $pipeline = []): array
    {
        $union = ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => $path, 'type' => $type]];

        return $pipeline === [] ? $union : $union + ['pipeline' => $pipeline];
    }

    /** Run a one-condition tree (the gate's smallest possible shape) over $payload. */
    private function gate(string $source, string $type, array $pipeline, array $payload): bool
    {
        return $this->engine->passes([
            'logic' => 'and',
            'children' => [[
                'kind' => 'condition',
                'source' => $source,
                'source_type' => $type,
                'pipeline' => $pipeline,
            ]],
        ], $payload);
    }

    // ── scalar argument readers: the union resolves to the literal its control uses ──

    public function test_an_arg_variable_in_a_condition_pipeline_resolves_to_the_referenced_field(): void
    {
        // FLIPPED by B6 (was: `..._does_not_resolve_today_and_fails_closed`). "Does field A equal field
        // B" — the canonical field-to-field comparison a user would author — now works.
        $payload = ['fields' => ['a' => 'match', 'b' => 'match']];

        // CONTROL: the same comparison with a literal argument.
        $this->assertTrue($this->gate('fields.a', 'text', [
            $this->op('text_equals', ['value' => 'match']),
        ], $payload));

        $this->assertTrue($this->gate('fields.a', 'text', [
            $this->op('text_equals', ['value' => $this->argVariable('fields.b')]),
        ], $payload));

        // …and it is a real comparison, not a trivially-true one: a DIFFERENT value closes the gate.
        $this->assertFalse($this->gate('fields.a', 'text', [
            $this->op('text_equals', ['value' => $this->argVariable('fields.b')]),
        ], ['fields' => ['a' => 'match', 'b' => 'other']]));
    }

    public function test_a_number_arg_variable_resolves_to_the_referenced_field(): void
    {
        // FLIPPED by B6.
        $payload = ['fields' => ['n' => 10, 'threshold' => 3]];

        $this->assertTrue($this->gate('fields.n', 'number', [$this->op('num_gt', ['value' => 3])], $payload));

        $this->assertTrue($this->gate('fields.n', 'number', [
            $this->op('num_gt', ['value' => $this->argVariable('fields.threshold', 'number')]),
        ], $payload));

        $this->assertFalse($this->gate('fields.n', 'number', [
            $this->op('num_gt', ['value' => $this->argVariable('fields.threshold', 'number')]),
        ], ['fields' => ['n' => 10, 'threshold' => 30]]));
    }

    public function test_a_date_arg_variable_resolves_to_the_referenced_field(): void
    {
        // FLIPPED by B6 — and this one needed a DEFECT FIX to be honest: a date-typed arg-variable
        // coerced to the ISO-8601 INSTANT a step FIELD stores (`2026-02-01T00:00:00+00:00`), which the
        // executor's STRICT `Y-m-d` argument reader rejects, so it failed the operation closed on BOTH
        // the gate and the step side. WorkflowVariableResolver::argWireDate now narrows a date ARGUMENT
        // to the same `Y-m-d` bytes a literal date argument carries.
        $payload = ['fields' => ['due' => '2026-01-01', 'deadline' => '2026-02-01']];

        $this->assertTrue($this->gate('fields.due', 'date', [$this->op('date_before', ['value' => '2026-02-01'])], $payload));

        $this->assertTrue($this->gate('fields.due', 'date', [
            $this->op('date_before', ['value' => $this->argVariable('fields.deadline', 'date')]),
        ], $payload));

        $this->assertFalse($this->gate('fields.due', 'date', [
            $this->op('date_before', ['value' => $this->argVariable('fields.deadline', 'date')]),
        ], ['fields' => ['due' => '2026-03-01', 'deadline' => '2026-02-01']]));
    }

    public function test_a_single_option_arg_variable_resolves_to_the_referenced_field(): void
    {
        // FLIPPED by B6.
        $payload = ['fields' => ['priority' => 'high', 'wanted' => 'high']];

        $this->assertTrue($this->gate('fields.priority', 'enum', [$this->op('enum_is', ['value' => 'high'])], $payload));

        $this->assertTrue($this->gate('fields.priority', 'enum', [
            $this->op('enum_is', ['value' => $this->argVariable('fields.wanted', 'enum')]),
        ], $payload));

        $this->assertFalse($this->gate('fields.priority', 'enum', [
            $this->op('enum_is', ['value' => $this->argVariable('fields.wanted', 'enum')]),
        ], ['fields' => ['priority' => 'high', 'wanted' => 'low']]));
    }

    public function test_an_arg_variable_carrying_its_own_pipeline_is_transformed_before_the_comparison(): void
    {
        // FLIPPED by B6 (was: `..._does_not_resolve_today_either`). The argument's OWN sub-pipeline runs
        // first — uppercase the other field, then compare — because pre-resolution walks the union
        // exactly as resolveValueOrVariable does anywhere else.
        $payload = ['fields' => ['a' => 'MATCH', 'b' => 'match']];

        $this->assertTrue($this->gate('fields.a', 'text', [$this->op('text_equals', ['value' => 'MATCH'])], $payload));

        $this->assertTrue($this->gate('fields.a', 'text', [
            $this->op('text_equals', ['value' => $this->argVariable('fields.b', 'text', [$this->op('text_uppercase')])]),
        ], $payload));

        // Without the sub-pipeline the same two values are NOT equal — so the transformation is what
        // opened the gate, not a lenient comparison.
        $this->assertFalse($this->gate('fields.a', 'text', [
            $this->op('text_equals', ['value' => $this->argVariable('fields.b')]),
        ], $payload));
    }

    public function test_every_scalar_argument_reader_accepts_an_arg_variable(): void
    {
        // FLIPPED by B6 (was: `..._rejects_an_arg_variable_today`). A sweep over the readers that gate a
        // SCALAR argument; each pipeline is written so its literal form is TRUE, so a false here would
        // mean the union failed to resolve.
        $payload = ['fields' => [
            'text' => 'abcdef', 'blank' => '', 'n' => 3.14159, 'flag' => true,
            'd' => '2026-01-01', 'm' => ['a'],
            'arg' => 'a', 'argNum' => 2, 'argDate' => '2025-12-31', 'argPattern' => 'YYYY',
        ]];

        $cases = [
            // withStringArg
            ['fields.text', 'text', [$this->op('text_contains', ['value' => $this->argVariable('fields.arg')])]],
            ['fields.m', 'multi', [$this->op('multi_includes', ['value' => $this->argVariable('fields.arg')])]],
            ['fields.flag', 'boolean', [
                $this->op('bool_to_text', ['when_true' => $this->argVariable('fields.arg'), 'when_false' => 'n']),
                $this->op('text_is_not_empty'),
            ]],
            // withNumberArg
            ['fields.n', 'number', [
                $this->op('num_round', ['precision' => $this->argVariable('fields.argNum', 'number')]),
                $this->op('num_gt', ['value' => 0]),
            ]],
            ['fields.n', 'number', [$this->op('num_between', ['from' => $this->argVariable('fields.argNum', 'number'), 'to' => 100])]],
            // withDateArg
            ['fields.d', 'date', [$this->op('date_between', ['from' => $this->argVariable('fields.argDate', 'date'), 'to' => '2027-01-01'])]],
            // bespoke scalar readers
            ['fields.text', 'text', [
                $this->op('text_substring', ['start' => $this->argVariable('fields.argNum', 'number'), 'length' => 2]),
                $this->op('text_is_not_empty'),
            ]],
            ['fields.text', 'text', [
                $this->op('text_replace', ['search' => $this->argVariable('fields.arg'), 'replace' => 'z']),
                $this->op('text_is_not_empty'),
            ]],
            ['fields.d', 'date', [
                $this->op('date_format', ['pattern' => $this->argVariable('fields.argPattern')]),
                $this->op('text_is_not_empty'),
            ]],
            // the presence family's fallback literal
            ['fields.blank', 'text', [
                $this->op('coalesce', ['fallback' => $this->argVariable('fields.arg')]),
                $this->op('text_is_not_empty'),
            ]],
        ];

        foreach ($cases as [$source, $type, $pipeline]) {
            $this->assertTrue(
                $this->gate($source, $type, $pipeline, $payload),
                'Expected the ' . $pipeline[0]['op'] . ' arg-variable to resolve like its literal control.',
            );
        }
    }

    // ── option-list argument readers: the WHOLE-arg union resolves to the referenced option list ──

    public function test_an_option_set_arg_variable_resolves_to_the_referenced_option_list(): void
    {
        // FLIPPED TWICE. B0.5 turned "misread as an option list" into "rejected as malformed"; B6 turns
        // that into "resolved to the real list" (a `multi` ref coerces to an array of option values).
        $union = $this->argVariable('fields.allowed', 'multi');

        $this->assertTrue($this->gate('fields.priority', 'enum', [
            $this->op('enum_in', ['values' => ['high', 'urgent']]),
        ], ['fields' => ['priority' => 'high']]));

        $this->assertTrue($this->gate('fields.priority', 'enum', [
            $this->op('enum_in', ['values' => $union]),
        ], ['fields' => ['priority' => 'high', 'allowed' => ['high', 'urgent']]]));

        // THE GATE-OPENING REPRO (kept): a field whose value is the literal string "variable" used to
        // MATCH the union's own `kind` value and open the gate. It stays FALSE — now because the union
        // resolves to the REAL option list, which does not contain it.
        $this->assertFalse($this->gate('fields.priority', 'enum', [
            $this->op('enum_in', ['values' => $union]),
        ], ['fields' => ['priority' => 'variable', 'allowed' => ['high']]]));

        // multi_includes_any shares the reader family.
        $this->assertFalse($this->gate('fields.tags', 'multi', [
            $this->op('multi_includes_any', ['values' => $union]),
        ], ['fields' => ['tags' => ['variable'], 'allowed' => ['high']]]));
    }

    // ── STRUCTURAL CONTAINERS: each ENTRY may be a variable (Defect-3) ───────

    public function test_a_source_map_entry_variable_resolves_per_option(): void
    {
        // PER-ENTRY (Defect-3): a sourceMap is a {option: Entry} map whose ENTRIES may EACH be a variable.
        // The picked option's TARGET is a variable referencing another field; the executor still reads a
        // plain {option: scalar} map after pre-resolution.
        $payload = ['fields' => ['priority' => 'high', 'target' => 'urgent']];

        // CONTROL: a fully literal map.
        $this->assertTrue($this->gate('fields.priority', 'enum', [
            $this->op('enum_to_text', ['mapping' => ['high' => 'urgent']]),
            $this->op('text_equals', ['value' => 'urgent']),
        ], $payload));

        // The 'high' target is a VARIABLE resolving to 'urgent'.
        $this->assertTrue($this->gate('fields.priority', 'enum', [
            $this->op('enum_to_text', ['mapping' => ['high' => $this->argVariable('fields.target')]]),
            $this->op('text_equals', ['value' => 'urgent']),
        ], $payload));

        // A MIX of a literal entry and a variable entry works; only the picked option's entry matters.
        $this->assertTrue($this->gate('fields.priority', 'enum', [
            $this->op('enum_to_text', ['mapping' => ['low' => 'normal', 'high' => $this->argVariable('fields.target')]]),
            $this->op('text_equals', ['value' => 'urgent']),
        ], $payload));

        // …and it is a real resolution: a DIFFERENT referenced value closes the equality.
        $this->assertFalse($this->gate('fields.priority', 'enum', [
            $this->op('enum_to_text', ['mapping' => ['high' => $this->argVariable('fields.target')]]),
            $this->op('text_equals', ['value' => 'urgent']),
        ], ['fields' => ['priority' => 'high', 'target' => 'meh']]));

        // enum_to_choice shares enumMap — a variable entry resolves the same way.
        $this->assertTrue($this->gate('fields.priority', 'enum', [
            $this->op('enum_to_choice', ['mapping' => ['high' => $this->argVariable('fields.target')]]),
            $this->op('enum_is', ['value' => 'urgent']),
        ], $payload));
    }

    public function test_a_choice_rules_then_entry_variable_resolves(): void
    {
        // PER-ENTRY (Defect-3): match_to_choice's `rules` is a [{when, then}] list whose `then` may be a
        // variable. `when` is now a boolean-terminal pipeline over the op's TEXT input; the matched
        // rule's variable `then` resolves.
        $payload = ['fields' => ['headline' => 'BREAKING', 'chosen' => 'high']];

        $when = fn (string $v) => [$this->op('text_equals', ['value' => $v])];

        // CONTROL: a literal then.
        $this->assertTrue($this->gate('fields.headline', 'text', [
            $this->op('match_to_choice', ['rules' => [['when' => $when('BREAKING'), 'then' => 'high']], 'fallback' => 'low']),
            $this->op('enum_is', ['value' => 'high']),
        ], $payload));

        // The matched rule's `then` is a VARIABLE resolving to 'high'.
        $this->assertTrue($this->gate('fields.headline', 'text', [
            $this->op('match_to_choice', ['rules' => [['when' => $when('BREAKING'), 'then' => $this->argVariable('fields.chosen', 'enum')]], 'fallback' => 'low']),
            $this->op('enum_is', ['value' => 'high']),
        ], $payload));

        // A non-match still takes the literal fallback (the variable `then` is never reached).
        $this->assertTrue($this->gate('fields.headline', 'text', [
            $this->op('match_to_choice', ['rules' => [['when' => $when('NOPE'), 'then' => $this->argVariable('fields.chosen', 'enum')]], 'fallback' => 'low']),
            $this->op('enum_is', ['value' => 'low']),
        ], $payload));
    }

    public function test_every_option_list_argument_reader_accepts_a_whole_arg_variable(): void
    {
        // The option-list controls (`values` = memberOfArg / multiOverlap) accept a WHOLE-arg multi
        // variable — unchanged by Defect-3. Each case asserts BOTH forms TRUE: the literal control, and
        // the same data reached through a reference.
        $payload = ['fields' => [
            'priority' => 'high', 'tags' => ['a', 'b'],
            'allowed' => ['high'], 'wantedTags' => ['a'],
        ]];

        // [source, source_type, pipeline builder taking the array-shaped ARG, the LITERAL arg, the REF]
        $cases = [
            // memberOfArg (`values`)
            ['fields.priority', 'enum', fn ($arg) => [$this->op('enum_in', ['values' => $arg])], ['high'], $this->argVariable('fields.allowed', 'multi')],
            // multiOverlap (`values`) — both the ANY and the ALL flavour
            ['fields.tags', 'multi', fn ($arg) => [$this->op('multi_includes_any', ['values' => $arg])], ['a'], $this->argVariable('fields.wantedTags', 'multi')],
            ['fields.tags', 'multi', fn ($arg) => [$this->op('multi_includes_all', ['values' => $arg])], ['a'], $this->argVariable('fields.wantedTags', 'multi')],
        ];

        foreach ($cases as [$source, $type, $pipeline, $literal, $ref]) {
            $op = $pipeline($literal)[0]['op'];

            $this->assertTrue(
                $this->gate($source, $type, $pipeline($literal), $payload),
                'The LITERAL control for ' . $op . ' must pass.',
            );
            $this->assertTrue(
                $this->gate($source, $type, $pipeline($ref), $payload),
                'Expected the ' . $op . ' arg-variable to resolve to the referenced option list.',
            );
        }
    }

    // ── an UNRESOLVABLE per-entry variable fails the gate CLOSED ──────────────

    public function test_an_unresolvable_rules_then_entry_fails_the_matched_rule_closed(): void
    {
        // PER-ENTRY fail-closed (Defect-3 REOPENED, the security repro). A rule whose `when` MATCHES but
        // whose `then` is an UNRESOLVABLE variable resolves to a non-scalar (null) target. matchToChoice
        // now fails the WHOLE OP closed there (symmetric with enumMap) instead of falling through to the
        // fallback — because returning the fallback as a SUCCESS when the fallback happens to be a
        // gate-opening choice is exactly how an unanswered optional field could OPEN a gate.
        $rules = [['when' => [$this->op('text_equals', ['value' => 'BREAKING'])], 'then' => $this->argVariable('fields.missing', 'enum')]];
        $pipeline = fn (string $want) => [
            $this->op('match_to_choice', ['rules' => $rules, 'fallback' => 'low']),
            $this->op('enum_is', ['value' => $want]),
        ];

        // The rule matches 'BREAKING' but its `then` is unresolvable → the op FAILS closed. The intended
        // 'high' branch is CLOSED…
        $this->assertFalse($this->gate('fields.headline', 'text', $pipeline('high'), ['fields' => ['headline' => 'BREAKING']]));

        // …and — THE FIX — the fallback 'low' is NOT leaked as a success either: the op failed, so nothing
        // reaches enum_is. (Before the fix this was TRUE, which is precisely the fail-OPEN when a fallback
        // is itself a gate-opening choice.)
        $this->assertFalse($this->gate('fields.headline', 'text', $pipeline('low'), ['fields' => ['headline' => 'BREAKING']]));

        // CONTROL: with the `then` ref ANSWERED, the rule matches and yields 'high'.
        $this->assertTrue($this->gate('fields.headline', 'text', [
            $this->op('match_to_choice', ['rules' => [['when' => [$this->op('text_equals', ['value' => 'BREAKING'])], 'then' => $this->argVariable('fields.pick', 'enum')]], 'fallback' => 'low']),
            $this->op('enum_is', ['value' => 'high']),
        ], ['fields' => ['headline' => 'BREAKING', 'pick' => 'high']]));
    }

    public function test_an_unanswered_optional_source_cannot_open_a_gate_a_filled_field_keeps_closed(): void
    {
        // THE EXACT REVIEWER REPRO. status='active' matches the rule; the rule's `then` is a variable
        // referencing the OPTIONAL field `prio`; the fallback IS the gate-opening choice 'blocked'; the
        // final reader is `enum_is('blocked')`. The asymmetry to close: an UNANSWERED optional field must
        // not open a gate that a FILLED field keeps closed.
        $condition = fn (array $fields) => $this->gate('fields.status', 'text', [
            $this->op('match_to_choice', [
                'rules' => [['when' => [$this->op('text_equals', ['value' => 'active'])], 'then' => $this->argVariable('fields.prio', 'enum')]],
                'fallback' => 'blocked',
            ]),
            $this->op('enum_is', ['value' => 'blocked']),
        ], ['fields' => $fields]);

        // CASE A — `prio` UNANSWERED: the matched rule's `then` is unresolvable → the op fails closed →
        // the fallback 'blocked' is NEVER returned → the gate stays CLOSED. (The fail-OPEN, now closed.)
        $this->assertFalse($condition(['status' => 'active']));

        // CASE B — `prio` FILLED with the gate-opening value: the rule resolves to 'blocked' → gate OPEN.
        $this->assertTrue($condition(['status' => 'active', 'prio' => 'blocked']));

        // CASE C — `prio` FILLED with something else: the rule resolves to 'high' → gate CLOSED. Proves
        // the working path is untouched by the fix.
        $this->assertFalse($condition(['status' => 'active', 'prio' => 'high']));
    }

    public function test_an_unresolvable_arg_variable_inside_a_matched_when_fails_the_gate_closed(): void
    {
        // THE C1/DEFECT-3 FAIL-OPEN PROBE, now for the `when` SIDE. A rule's `when` pipeline compares the
        // op input against an ARG-VARIABLE; the fallback IS the gate-opening choice; the final reader is
        // enum_is on it. When the arg-variable is UNANSWERED it pre-resolves to null, text_equals fails
        // closed, the `when` sub-run fails → matchToChoice fails the WHOLE OP closed. The fallback must
        // NEVER be returned as a success (that is the fail-OPEN class).
        $condition = fn (array $fields, string $want) => $this->gate('fields.status', 'text', [
            $this->op('match_to_choice', [
                'rules' => [['when' => [$this->op('text_equals', ['value' => $this->argVariable('fields.wanted', 'text')])], 'then' => 'high']],
                'fallback' => 'blocked',
            ]),
            $this->op('enum_is', ['value' => $want]),
        ], ['fields' => $fields]);

        // CASE A — `wanted` UNANSWERED: the `when` can't be evaluated → op fails → neither the intended
        // 'high' branch NOR the gate-opening fallback 'blocked' leaks. The gate stays CLOSED both ways.
        $this->assertFalse($condition(['status' => 'active'], 'high'));
        $this->assertFalse($condition(['status' => 'active'], 'blocked'));

        // CASE B — `wanted` ANSWERED to the input: the `when` is TRUE → 'high'. The working path is intact.
        $this->assertTrue($condition(['status' => 'active', 'wanted' => 'active'], 'high'));

        // …and the fallback path is not spuriously taken when the `when` legitimately does not match.
        $this->assertFalse($condition(['status' => 'active', 'wanted' => 'other'], 'high'));
    }

    public function test_an_unresolvable_mapping_entry_or_option_list_variable_fails_the_condition_closed(): void
    {
        // A sourceMap ENTRY variable that resolves to nothing makes its option map to null → enumMap fails
        // closed (Defect-3). The `values` whole-arg option-list variable (memberOfArg / multiOverlap) is
        // likewise closed when unresolvable — those controls are unchanged. This is the sweep that keeps
        // the readers from drifting apart again.
        $mapEntry = $this->argVariable('fields.mapTarget', 'text');
        $values = $this->argVariable('fields.allowed', 'multi');

        $cases = [
            // [source, type, pipeline, the payload FIELDS that RESOLVE the ref]
            ['fields.priority', 'enum', [
                $this->op('enum_to_text', ['mapping' => ['high' => $mapEntry]]),
                $this->op('text_equals', ['value' => 'urgent']),
            ], ['mapTarget' => 'urgent']],
            ['fields.priority', 'enum', [
                $this->op('enum_to_choice', ['mapping' => ['high' => $mapEntry]]),
                $this->op('enum_is', ['value' => 'urgent']),
            ], ['mapTarget' => 'urgent']],
            ['fields.priority', 'enum', [$this->op('enum_in', ['values' => $values])], ['allowed' => ['high']]],
            ['fields.tags', 'multi', [$this->op('multi_includes_any', ['values' => $values])], ['allowed' => ['high']]],
            ['fields.tags', 'multi', [$this->op('multi_includes_all', ['values' => $values])], ['allowed' => ['high']]],
        ];

        foreach ($cases as [$source, $type, $pipeline, $resolvable]) {
            $op = $pipeline[0]['op'];
            $base = ['priority' => 'high', 'tags' => ['high']];

            $this->assertTrue(
                $this->gate($source, $type, $pipeline, ['fields' => array_merge($base, $resolvable)]),
                'The resolvable control for ' . $op . ' must pass.',
            );
            $this->assertFalse(
                $this->gate($source, $type, $pipeline, ['fields' => $base]),
                'Expected ' . $op . ' to fail closed on an unresolvable entry / option-list reference.',
            );
        }
    }

    public function test_a_literal_null_or_absent_rules_still_means_no_rules_and_takes_the_fallback(): void
    {
        // THE PRESERVED SEMANTIC (ADR-0022): an author who wrote NO rules still gets "the fallback is
        // total over the input". Only the UNRESOLVABLE VARIABLE case changed — a literal is untouched,
        // in both of its spellings.
        $payload = ['fields' => ['headline' => 'anything at all']];

        $this->assertTrue($this->gate('fields.headline', 'text', [
            $this->op('match_to_choice', ['rules' => null, 'fallback' => 'low']),
            $this->op('enum_is', ['value' => 'low']),
        ], $payload), 'a LITERAL null rules still means no rules');

        $this->assertTrue($this->gate('fields.headline', 'text', [
            $this->op('match_to_choice', ['fallback' => 'low']),
            $this->op('enum_is', ['value' => 'low']),
        ], $payload), 'an ABSENT rules key still means no rules');

        // …and an empty literal list is the same thing spelled a third way.
        $this->assertTrue($this->gate('fields.headline', 'text', [
            $this->op('match_to_choice', ['rules' => [], 'fallback' => 'low']),
            $this->op('enum_is', ['value' => 'low']),
        ], $payload), 'an EMPTY literal rules list still means no rules');
    }

    // ── the asymmetry is CLOSED: the gate and the step side now agree ─────────

    public function test_the_gate_and_the_step_side_resolve_the_same_arg_variable_identically(): void
    {
        // This test used to be titled `..._which_is_the_gap`: the resolver (step config) performed the
        // field-to-field comparison while the gate, over the very same data, stayed closed. Both are
        // TRUE now, and they are the same code path — the gate calls the resolver.
        $resolver = new WorkflowVariableResolver(new WorkflowOperationExecutor, new ScriptedWorkflowAiTextService);
        $payload = ['fields' => ['a' => 'match', 'b' => 'match']];

        $this->assertTrue($resolver->resolveValueOrVariable(
            [
                'kind' => 'variable',
                'ref' => ['source' => 'trigger', 'path' => 'fields.a', 'type' => 'text'],
                'pipeline' => [$this->op('text_equals', ['value' => $this->argVariable('fields.b')])],
            ],
            ['trigger' => $payload, 'steps' => []],
            WorkflowVariableType::BOOLEAN,
        ));

        $this->assertTrue($this->gate('fields.a', 'text', [
            $this->op('text_equals', ['value' => $this->argVariable('fields.b')]),
        ], $payload));
    }

    // ── what stays fail-closed ───────────────────────────────────────────────

    public function test_a_steps_reference_in_a_gate_argument_resolves_empty_and_fails_the_condition_closed(): void
    {
        // `steps` is structurally EMPTY in a gate context — the gate runs before any step has executed,
        // which is why the write-validator builds its reference index with no prior steps and rejects a
        // `steps.*` argument ref with a 422. A stored row that carries one anyway resolves fail-soft to
        // null, so the operation fails and the condition is false. It can never read a later run.
        $payload = ['fields' => ['a' => 'match']];

        $this->assertFalse($this->gate('fields.a', 'text', [
            $this->op('text_equals', ['value' => ['kind' => 'variable', 'ref' => ['source' => 'steps', 'path' => 'make_task.title', 'type' => 'text']]]),
        ], $payload));
    }

    public function test_a_non_whitelisted_argument_ref_root_resolves_empty_and_fails_the_condition_closed(): void
    {
        // The resolver's ROOT whitelist (trigger/steps/globals) is the exfiltration boundary; an
        // argument is no exception, so `env.*` / `config.*` are simply not references.
        $this->assertFalse($this->gate('fields.a', 'text', [
            $this->op('text_equals', ['value' => ['kind' => 'variable', 'ref' => ['source' => 'env', 'path' => 'APP_KEY', 'type' => 'text']]]),
        ], ['fields' => ['a' => 'match']]));
    }

    public function test_an_argument_whose_sub_pipeline_hard_fails_never_throws_out_of_the_gate(): void
    {
        // assert_present is the ONE op that reports a HARD failure, which a value-producing resolution
        // re-raises as the run's step-failure (a RuntimeException). Inside a GATE that exception would
        // be a 500 on a user's form submission, so the engine catches it into its ordinary
        // fail-closed false — the never-throws contract holds for the new pre-resolution step too.
        $argument = $this->argVariable('fields.blank', 'text', [$this->op('assert_present')]);

        $this->assertFalse($this->gate('fields.a', 'text', [
            $this->op('text_equals', ['value' => $argument]),
        ], ['fields' => ['a' => 'match', 'blank' => '']]));
    }
}
