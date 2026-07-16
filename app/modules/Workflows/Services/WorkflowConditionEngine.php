<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Enums\ConditionTreeLimits;
use App\Modules\Workflows\Enums\WorkflowVariableType;
use Illuminate\Support\Arr;

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
 *   - a condition reads its `source` (a dotted path) off the payload; a MISSING path → false (no
 *     "absence" operators in the new model). The value + pipeline are handed to the shared
 *     WorkflowOperationExecutor; ANY failure it reports (a wrong input type, an unknown op, an
 *     unparseable number/date, divide-by-zero, an unmapped enum option, too many steps) collapses
 *     the whole condition to false. A pipeline that does not terminate in a boolean true is false.
 *
 * The operation SEMANTICS live on WorkflowOperationExecutor (the single 66-op engine this and the
 * variable resolver both call), so they can never drift between the gate and the step runtime.
 */
class WorkflowConditionEngine
{
    /** Distinct from null so a genuine null payload value is not read as "missing". */
    private const MISSING = "\0__workflow_condition_missing__\0";

    public function __construct(
        private WorkflowOperationExecutor $executor,
        private WorkflowConditionEvaluator $legacy,
    ) {}

    /**
     * Whether the conditions gate is open for this payload.
     *
     * @param  array<int|string, mixed>|null  $conditions  a legacy clause list OR a logic tree
     * @param  array<string, mixed>  $payload
     */
    public function passes(?array $conditions, array $payload): bool
    {
        if ($conditions === null || $conditions === []) {
            return true;
        }

        // A list is the legacy flat model; an object (associative) is the new logic tree.
        if (array_is_list($conditions)) {
            return $this->legacy->passes($conditions, $payload);
        }

        return $this->evaluateGroup($conditions, $payload, 1);
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
     * A leaf condition: read the source off the payload (missing → false), then flow it through the
     * shared operation executor as its declared type. The pipeline's terminal value must be boolean true.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $payload
     */
    private function evaluateCondition(array $node, array $payload): bool
    {
        $source = $node['source'] ?? null;
        $type = WorkflowVariableType::tryFrom((string) ($node['source_type'] ?? ''));

        if (!is_string($source) || $type === null) {
            return false;
        }

        $raw = Arr::get($payload, $source, self::MISSING);

        if ($raw === self::MISSING) {
            return false; // a missing path is simply false in the new model
        }

        $pipeline = $node['pipeline'] ?? null;

        if (!is_array($pipeline)) {
            return false;
        }

        $result = $this->executor->execute($raw, $type, $pipeline, $payload);

        return !$result->failed
            && $result->type === WorkflowVariableType::BOOLEAN
            && $result->value === true;
    }
}
