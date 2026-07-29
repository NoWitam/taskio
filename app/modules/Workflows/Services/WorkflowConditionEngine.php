<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Variables\Enums\PipelineLimits;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Services\FunctionDefinitionValidator;
use App\Modules\Variables\Services\OperationExecutor;
use App\Modules\Variables\Services\VariableResolver;
use App\Modules\Variables\Support\FunctionScope;
use App\Modules\Variables\Support\ValueOrVariable;
use App\Modules\Workflows\Enums\ConditionTreeLimits;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Evaluates a workflow's optional gate conditions against a form_submitted trigger payload — the
 * single seam the dispatch service funnels through. It accepts BOTH condition shapes and dispatches:
 *
 *   - null / []            no gate → PASSES (unchanged behavior).
 *   - a LIST of clauses    the LEGACY flat {field, field_type, operator, value} model → delegated
 *                          verbatim to WorkflowConditionEvaluator (untouched; read/write of pre-B2 rows).
 *   - an OBJECT tree       the NEW logic tree {logic, children[]} of groups + conditions, each
 *                          condition a source + a typed pipeline of operations terminating in boolean.
 *
 * TREE DOCTRINE (deliberately simple, fail-closed to FALSE — the engine NEVER throws into the
 * authoring write path):
 *   - group and = every child (lazy), or = some child (lazy); an unknown logic / empty or
 *     over-full group / over-deep nesting → false.
 *   - a condition reads its `source` (a dotted path); a MISSING path → false (no "absence" operators
 *     in the new model) UNLESS the node opts in to a `default` (see below). The value + pipeline are
 *     handed to the shared OperationExecutor; ANY failure it reports (a wrong input type, an
 *     unknown op, an unparseable number/date, divide-by-zero, an unmapped enum option, too many steps,
 *     a `source_type` the executor has no runtime semantics for, an operation argument that is really
 *     an unresolved variable union) collapses the whole condition to false. A pipeline that does not
 *     terminate in a boolean true is false.
 *
 * SOURCE VOCABULARY (the ONE rule — B6). A condition `source` is a dotted path in exactly two roots:
 *
 *   - `fields.<id>`            a FIELD of the trigger form, read straight off the BARE trigger payload.
 *                              UNPREFIXED by design: this is the legacy condition vocabulary (shared
 *                              with the flat clause list) and it is what every stored row carries.
 *   - `globals.<key>[.<sub>]`  a workspace GLOBAL (a user-created literal constant), read off the
 *                              `globals` map — the SAME full catalog path a reference uses everywhere
 *                              else in the module, because a global's catalog `source` IS its root.
 *
 * No other root is a condition source: `trigger.*` system vars are out of scope, and `steps.*` can
 * never be one — the gate runs BEFORE any step has executed (the write-side reference index is built
 * with NO prior steps, so a `steps.*` argument reference is a 422). Only a global's SCALAR leaves are
 * conditionable; an object global itself is not offered (it has no meaningful operator set), exactly
 * as a form section/repeater container is filtered out of the condition catalog.
 *
 * ARGUMENT VARIABLES (B6): an operation ARGUMENT inside a condition pipeline may itself be a value-or-
 * variable union, so a condition can compare a field against ANOTHER field / a global. Those are
 * pre-resolved to literals by VariableResolver::resolveArgsForPipeline BEFORE the (pure)
 * executor runs — the SAME machinery the step runtime uses, so the two can never drift. An argument
 * ref uses the module-wide FULL path (`trigger.fields.<id>`, `globals.<key>`), NOT the source's short
 * vocabulary, because it is resolved against a real run context.
 *
 * COST GUARD: that run context (`{trigger, steps, globals}`) is built LAZILY, at most once per
 * passes() call, and only for a condition that actually needs it — a `globals.*` SOURCE, or a pipeline
 * carrying at least one variable-shaped argument (argVariableRoots). The workspace globals QUERY is
 * gated once more, on a global actually being referenced (by the source or by an argument's ref root),
 * so the common gate — a form-field condition with literal arguments — runs exactly as it always did:
 * no context, no resolver, no query, and a byte-identical executor call.
 *
 * NEVER-THROWS, FOR REAL: this gate runs INSIDE form submission (WorkflowDispatchService::dispatch),
 * so any exception escaping it is a 500 on a user's submit. Two ways it could escape were closed as
 * a safety batch — a descriptor-only `source_type` (`time`/`object`) that a legacy/hand-written row
 * could carry into the executor's formerly-exhaustive match, and array-shaped operation arguments
 * that consumed an unresolved variable union AS DATA (which could even OPEN the gate). Both now fail
 * closed inside the executor; see the ADR-0022 amendment. The B6 additions keep the contract: the
 * context build (a DB read) and the argument pre-resolution (which may raise the run's standard
 * assert_present step-failure) are the ONLY new failure modes and both are caught into the same
 * fail-closed false.
 *
 * OBSERVABILITY, WITHOUT LEAKING USER DATA: that same catch made a genuine INFRASTRUCTURE failure
 * (an unreadable globals tier) look exactly like an ordinary closed gate. The globals read is now
 * attempted at most ONCE per passes() call and, when it blows up, logs ONE warning carrying the
 * workflow id + the exception CLASS only — never a value, a payload or a global (all user data). See
 * readGlobals().
 *
 * The operation SEMANTICS live on OperationExecutor (the single 77-op engine this and the
 * variable resolver both call), so they can never drift between the gate and the step runtime.
 */
class WorkflowConditionEngine
{
    /** Distinct from null so a genuine null payload value is not read as "missing". */
    private const MISSING = "\0__workflow_condition_missing__\0";

    /**
     * The one condition-SOURCE root that is NOT a form field: a workspace global (`globals.<key>`).
     * Also the run-context key its values live under, and the ref root that makes an argument variable
     * need them — so the source vocabulary, the context shape and the cost guard read one constant.
     */
    private const GLOBALS_ROOT = 'globals';

    /** The one log message a globals read that BLEW UP emits — see readGlobals(). */
    private const GLOBALS_UNAVAILABLE = 'Workflow condition gate closed: the workspace globals could not be read.';

    /**
     * The run context ({trigger, steps, globals?}) for the passes() call currently in flight, built
     * lazily and reset in passes()' finally — so it is per-CALL state (never shared between workflows
     * or workspaces) without threading a by-reference accumulator through the whole tree walk. Null
     * whenever no call is in flight, and whenever no condition has needed a context yet.
     *
     * @var array<string, mixed>|null
     */
    private ?array $runContext = null;

    /**
     * Whether the globals read already FAILED during the call in flight (per-CALL state, like
     * $runContext). Memoizes the failure so a DB outage costs ONE attempt per evaluation rather than
     * one per globals-needing condition, and so every such condition fails closed identically.
     */
    private bool $globalsFailed = false;

    /**
     * The workflow whose gate is being evaluated — LOG METADATA ONLY (per-CALL state, like
     * $runContext), so an infrastructure failure is attributable without threading an argument
     * through the whole tree walk. Never read as data by any condition.
     */
    private ?string $workflowId = null;

    /**
     * The workspace's custom functions for the call in flight (per-CALL state, like $runContext),
     * memoized so a gate carrying several function conditions reads them at most once. Null until a
     * condition first REFERENCES a function — the common gate (no `fn:` op) never touches the catalog,
     * so the cost guard holds and a test double catalog is never called for a function-less gate.
     *
     * @var array<int, \App\Modules\Variables\Support\CustomFunctionOperation>|null
     */
    private ?array $functions = null;

    public function __construct(
        private OperationExecutor $executor,
        private WorkflowConditionEvaluator $legacy,
        private VariableResolver $resolver,
        private WorkflowVariableCatalogService $catalog,
    ) {}

    /**
     * Whether the conditions gate is open for this payload.
     *
     * $workflowId is OPTIONAL log metadata only (the caller's workflow id, for the one warning an
     * unreadable globals tier emits); omitting it changes no gate decision.
     *
     * @param  array<int|string, mixed>|null  $conditions  a legacy clause list OR a logic tree
     * @param  array<string, mixed>  $payload
     */
    public function passes(?array $conditions, array $payload, ?string $workflowId = null): bool
    {
        if ($conditions === null || $conditions === []) {
            return true;
        }

        // A list is the legacy flat model; an object (associative) is the new logic tree.
        if (array_is_list($conditions)) {
            return $this->legacy->passes($conditions, $payload);
        }

        $this->runContext = null;
        $this->globalsFailed = false;
        $this->functions = null;
        $this->workflowId = $workflowId;

        try {
            return $this->evaluateGroup($conditions, $payload, 1);
        } finally {
            // Never let one workflow's (or workspace's) per-call state outlive its call.
            $this->runContext = null;
            $this->globalsFailed = false;
            $this->functions = null;
            $this->workflowId = null;
        }
    }

    // ---- tree walk ------------------------------------------------------------

    /**
     * A group node: and = every child, or = some child (both lazy). Defensive caps (depth, child
     * count, empty/unknown logic) all collapse to false so a corrupted tree can never open the gate.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $payload
     */
    private function evaluateGroup(array $node, array $payload, int $depth): bool
    {
        if ($depth > ConditionTreeLimits::MAX_DEPTH) {
            return false;
        }

        $children = $node['children'] ?? null;

        if (!is_array($children) || $children === [] || count($children) > ConditionTreeLimits::MAX_CHILDREN) {
            return false;
        }

        return match ($node['logic'] ?? null) {
            'and' => $this->all($children, $payload, $depth),
            'or' => $this->any($children, $payload, $depth),
            default => false,
        };
    }

    /** @param array<int, mixed> $children */
    private function all(array $children, array $payload, int $depth): bool
    {
        foreach ($children as $child) {
            if (!$this->evaluateNode($child, $payload, $depth)) {
                return false; // lazy: a single false short-circuits the AND
            }
        }

        return true;
    }

    /** @param array<int, mixed> $children */
    private function any(array $children, array $payload, int $depth): bool
    {
        foreach ($children as $child) {
            if ($this->evaluateNode($child, $payload, $depth)) {
                return true; // lazy: a single true short-circuits the OR
            }
        }

        return false;
    }

    /**
     * Dispatch a child node by its kind. A nested group descends one depth level; a condition is a
     * leaf. An unknown/malformed node fails closed.
     *
     * @param  array<string, mixed>  $payload
     */
    private function evaluateNode(mixed $node, array $payload, int $parentDepth): bool
    {
        if (!is_array($node)) {
            return false;
        }

        return match ($node['kind'] ?? 'group') {
            'condition' => $this->evaluateCondition($node, $payload),
            'group' => $this->evaluateGroup($node, $payload, $parentDepth + 1),
            default => false,
        };
    }

    /**
     * A leaf condition: read the source (missing → false, unless the node opts in to a `default`),
     * then flow it through the shared operation executor as its declared type. The pipeline's terminal
     * value must be boolean true.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $payload
     */
    private function evaluateCondition(array $node, array $payload): bool
    {
        $source = $node['source'] ?? null;
        $type = VariableType::tryFrom((string) ($node['source_type'] ?? ''));

        if (!is_string($source) || $type === null) {
            return false;
        }

        $pipeline = $node['pipeline'] ?? null;

        if (!is_array($pipeline)) {
            return false;
        }

        try {
            [$raw, $pipeline, $functions] = $this->readCondition($source, $pipeline, $payload);
        } catch (Throwable) {
            // The context build (a DB read) and the argument pre-resolution (which may raise the run's
            // standard assert_present step-failure) are the only steps here that CAN throw; the gate is
            // total, so either is the module's ordinary fail-closed false — never a 500 on a submit.
            // An unreadable globals tier is the one failure worth SEEING, and readGlobals() has already
            // logged it (once per call) before re-raising into here.
            return false;
        }

        $raw = $this->applyDefault($raw, $node);

        if ($raw === self::MISSING) {
            return false; // a missing path is simply false in the new model (no `default` opted in)
        }

        // Thread the workspace functions into the executor context so a `fn:<uuid>` op EXECUTES (by
        // expansion) — a boolean-returning function is how a condition can call a custom transform. Empty
        // for the common gate, so the executor call there is byte-identical (payload as context, no scope).
        // A function that cannot be evaluated (absent/deleted, over-depth, a corrupted cycle, a failed body,
        // a wrong return type) fails the op CLOSED inside the executor, so the condition simply reads false.
        $execContext = $functions === [] ? $payload : FunctionScope::forFunctions($functions)->writeInto($payload);

        $result = $this->executor->execute($raw, $type, $pipeline, $execContext);

        return !$result->failed
            && $result->type === VariableType::BOOLEAN
            && $result->value === true;
    }

    /**
     * The condition's BASE value + the pipeline the executor will run.
     *
     * THE COMMON PATH IS UNCHANGED: a `fields.<id>` source whose pipeline carries only literal
     * arguments reads straight off the payload and hands the pipeline over byte-identically — no run
     * context, no resolver, no globals query. Only the two B6 capabilities cost anything:
     *   - a `globals.*` SOURCE reads off the lazily built context's globals map instead;
     *   - a pipeline carrying variable-shaped ARGUMENTS has them pre-resolved to literals first.
     * The globals QUERY is gated once more (inside runContext) on a global actually being referenced.
     *
     * A THIRD B7-era capability rides here too: a pipeline that references a custom FUNCTION (`fn:<uuid>`
     * op) needs the workspace functions to execute — fetched (once per call, memoized) ONLY when a `fn:`
     * op is actually present, so the common gate never asks the catalog for them (the cost guard). When the
     * pipeline ALSO carries argument variables they are pre-resolved against a context CARRYING the
     * functions (so a fn op's own args resolve). The functions are returned for the caller to thread into
     * the executor.
     *
     * @param  array<int, mixed>  $pipeline
     * @param  array<string, mixed>  $payload
     * @return array{0: mixed, 1: array<int, mixed>, 2: array<int, \App\Modules\Variables\Support\CustomFunctionOperation>}
     */
    private function readCondition(string $source, array $pipeline, array $payload): array
    {
        $globalSource = $this->isGlobalSource($source);
        $argRoots = $this->argVariableRoots($pipeline);
        $usesFunctions = $this->referencesFunction($pipeline);

        if (!$globalSource && $argRoots === [] && !$usesFunctions) {
            return [Arr::get($payload, $source, self::MISSING), $pipeline, []];
        }

        $functions = $usesFunctions ? $this->functionOperations() : [];
        $context = $this->contextFor($payload, $globalSource || in_array(self::GLOBALS_ROOT, $argRoots, true));

        // Argument pre-resolution runs against a context CARRYING the functions, so a fn op's own
        // variable-shaped args resolve (the resolver resolves a fn op through the SAME OperationResolver).
        $resolveContext = $functions === [] ? $context : FunctionScope::forFunctions($functions)->writeInto($context);

        return [
            Arr::get($globalSource ? $context : $payload, $source, self::MISSING),
            $argRoots === [] ? $pipeline : $this->resolver->resolveArgsForPipeline($pipeline, $resolveContext),
            $functions,
        ];
    }

    /**
     * Whether $pipeline references any custom FUNCTION — a `fn:<uuid>` op at ANY nesting depth (element
     * pipelines, arg-variable sub-pipelines, choice-rule `when`s, reducers). Reuses the SAME precise edge
     * extraction the function cycle graph + delete guards use, so the cost-guard scan can never drift from
     * what actually resolves a function. The common gate has none → the catalog is never queried.
     *
     * @param  array<int, mixed>  $pipeline
     */
    private function referencesFunction(array $pipeline): bool
    {
        return FunctionDefinitionValidator::referencedFunctionIds($pipeline) !== [];
    }

    /**
     * The workspace's custom functions for the call in flight, fetched AT MOST ONCE (memoized, like the
     * globals). A fetch failure propagates to evaluateCondition's fail-closed catch (the condition reads
     * false), and a function that is absent at execute time fails the op closed anyway — so no function
     * condition can OPEN a gate on missing data.
     *
     * @return array<int, \App\Modules\Variables\Support\CustomFunctionOperation>
     */
    private function functionOperations(): array
    {
        return $this->functions ??= $this->catalog->customFunctionOperations();
    }

    /**
     * The lazily built run context an argument variable resolves against — the SAME `{trigger, steps,
     * globals}` shape WorkflowStepRunner builds, so a ref means exactly what it means in a step config
     * (`trigger.fields.<id>`, `globals.<key>`). `steps` is EMPTY and always will be: no step has run
     * when the gate is evaluated, which is why the write-side reference index is built with no prior
     * steps and a `steps.*` ref is rejected at write time.
     *
     * Memoized for the passes() call in flight (see $runContext) and TIERED: the workspace globals are
     * a DB read, so they are fetched only when $withGlobals — i.e. when the condition's SOURCE is a
     * global or one of its argument refs is rooted at `globals`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function contextFor(array $payload, bool $withGlobals): array
    {
        $this->runContext ??= ['trigger' => $payload, 'steps' => []];

        if ($withGlobals && !array_key_exists(self::GLOBALS_ROOT, $this->runContext)) {
            $this->runContext[self::GLOBALS_ROOT] = $this->readGlobals();
        }

        return $this->runContext;
    }

    /**
     * The workspace globals for the call in flight, attempted AT MOST ONCE — and LOGGED once when the
     * read blows up (a broken tenant connection, a lost connection mid-dispatch).
     *
     * The gate is total, so evaluateCondition() catches this into its ordinary fail-closed false. That
     * made a genuine INFRASTRUCTURE failure indistinguishable from "the gate simply closed" — nothing
     * was recorded, while WorkflowDispatchService logs even its ordinary skips. Hence ONE Log::warning
     * carrying the workflow id and the exception CLASS ONLY: never the message, the payload, or any
     * global value, because all three are user data (a global is a user-authored constant, and the
     * payload is a form submission).
     *
     * The failure is MEMOIZED ($globalsFailed) rather than retried: the context memo keys on the
     * `globals` entry EXISTING, which a throw never creates, so every globals-needing condition in the
     * same tree used to re-enter this read — turning a DB outage into N failing queries per evaluation.
     * A memoized failure re-raises (fail-closed) so the second, third, … condition answers exactly what
     * the first did, and — critically — is never handed an EMPTY globals map, which a condition carrying
     * an opt-in `default` could otherwise satisfy and OPEN the gate on an infrastructure failure.
     *
     * @return array<string, mixed>
     */
    private function readGlobals(): array
    {
        if ($this->globalsFailed) {
            throw new RuntimeException(self::GLOBALS_UNAVAILABLE);
        }

        try {
            return $this->catalog->globalValues();
        } catch (Throwable $e) {
            $this->globalsFailed = true;

            Log::warning(self::GLOBALS_UNAVAILABLE, [
                'workflow_id' => $this->workflowId,
                'exception' => $e::class,
            ]);

            throw $e;
        }
    }

    /** Whether a condition SOURCE addresses a workspace global rather than a form field. */
    private function isGlobalSource(string $source): bool
    {
        return str_starts_with($source, self::GLOBALS_ROOT . '.');
    }

    /**
     * The reference ROOTS of every VARIABLE-shaped operation argument in $pipeline (a step's own
     * nested sub-pipeline included), or an empty list when the pipeline carries none — the gate's COST
     * GUARD, answering both "is any pre-resolution needed at all?" (non-empty) and "must the workspace
     * globals be read?" (contains `globals`) from ONE cheap structural scan.
     *
     * A variable may be the WHOLE arg (VALUE/OPTION/OPTIONS controls) OR a per-entry union inside a
     * STRUCTURAL container (a sourceMap map value / a choiceRules rule `then` — Defect-3): unionRootsIn
     * descends a plain map/list to find those entries, so a per-entry variable is pre-resolved too and the
     * executor never sees a raw union.
     *
     * Bounded by the same MAX_ARG_VARIABLE_DEPTH the resolver and the write-validator enforce: past it
     * an argument resolves fail-soft anyway, so a root missed here can only degrade to the null the op
     * fail-closes on — never to a wrong value.
     *
     * @param  array<int, mixed>  $pipeline
     * @return array<int, string>
     */
    private function argVariableRoots(array $pipeline, int $depth = 0): array
    {
        if ($depth > PipelineLimits::MAX_ARG_VARIABLE_DEPTH) {
            return [];
        }

        $roots = [];

        foreach ($pipeline as $step) {
            if (!is_array($step) || !is_array($step['args'] ?? null)) {
                continue;
            }

            foreach ($step['args'] as $arg) {
                $roots = array_merge($roots, $this->unionRootsIn($arg, $depth));
            }
        }

        return $roots;
    }

    /**
     * The reference roots of every variable union reachable in ONE argument value: the WHOLE arg when it
     * is a union (recording its root + recursing into its sub-pipeline), OR — for a STRUCTURAL container
     * (a sourceMap map / a choiceRules list) — the per-entry unions found by descending the plain map/list
     * (Defect-3). Descending a plain array does not consume an arg-variable nesting level (the container
     * is not itself a variable); only a union's own sub-pipeline increments the depth.
     *
     * @return array<int, string>
     */
    private function unionRootsIn(mixed $value, int $depth): array
    {
        if ($depth > PipelineLimits::MAX_ARG_VARIABLE_DEPTH || !is_array($value)) {
            return [];
        }

        if (ValueOrVariable::isVariable($value)) {
            $roots = [$this->refRoot($value)];

            if (is_array($value['pipeline'] ?? null)) {
                $roots = array_merge($roots, $this->argVariableRoots($value['pipeline'], $depth + 1));
            }

            return $roots;
        }

        $roots = [];

        foreach ($value as $child) {
            $roots = array_merge($roots, $this->unionRootsIn($child, $depth));
        }

        return $roots;
    }

    /**
     * The context ROOT one variable-union argument reads from — its explicit `ref.source` when it has
     * one, else the first segment of `ref.path` (both shapes the resolver's refPath accepts). '' for a
     * malformed ref, which needs no context at all (the resolver soft-resolves it to null).
     *
     * @param  array<string, mixed>  $union
     */
    private function refRoot(array $union): string
    {
        $ref = is_array($union['ref'] ?? null) ? $union['ref'] : [];
        $source = $ref['source'] ?? null;

        if (is_string($source) && $source !== '') {
            return $source;
        }

        $path = $ref['path'] ?? null;

        return is_string($path) ? explode('.', $path, 2)[0] : '';
    }

    /**
     * The OPT-IN per-condition `default` (B6): when the node CARRIES the key and the looked-up value is
     * missing / null / '', the default literal is substituted and the condition evaluates over it.
     * Without the key the value passes through untouched, so a missing path stays the plain false the
     * module has always answered — the substitution can only ever be asked for, never inherited.
     *
     * Mirrors VariableResolver::applyDefault (a scalar literal, empty-or-missing trigger) so a
     * reference's default and a condition's default mean the same thing; a non-scalar default is
     * ignored rather than trusted (the write-validator rejects one, so it can only be a legacy row).
     *
     * @param  array<string, mixed>  $node
     */
    private function applyDefault(mixed $raw, array $node): mixed
    {
        if (!array_key_exists('default', $node)) {
            return $raw;
        }

        $default = $node['default'];

        if (!is_scalar($default)) {
            return $raw;
        }

        return $raw === self::MISSING || $raw === null || $raw === '' ? $default : $raw;
    }
}
