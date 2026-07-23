<?php

namespace App\Modules\Workflows\DTOs;

use App\Modules\Workflows\Enums\WorkflowVariableType;

/**
 * The per-arg-control policy for accepting a VARIABLE-supplied operation argument (phase-4b) — the
 * SINGLE source both the write-validator (WorkflowConditionTreeValidator::validateArgVariable) and the
 * runtime resolver (WorkflowVariableResolver::resolveArgVariable) read, so a variable argument is gated
 * at write time and coerced at run time identically and can never drift. Built by the ONE match in
 * WorkflowOperationArgType::argVariablePolicy(); EVERY arg control now accepts a variable (phase-4b
 * widened the option/map/rules controls, which were literal-only in phase-4a).
 *
 * Two orthogonal facets, one per side of the contract:
 *   - $refTypes  the WorkflowVariableTypes a variable ref (and, when it carries one, its sub-pipeline
 *                TERMINAL) may declare at WRITE time. null = STRUCTURAL: the flat variable type cannot
 *                express a {option: target} map / a {when, then} rule list, so the ref is gated LOOSELY
 *                (whitelisted ROOTS + present in the reference index) and the exact SHAPE is deferred to
 *                runtime fail-soft.
 *   - $coerceTo  the WorkflowVariableType a RESOLVED value is coerced to before the PURE executor reads
 *                it. null = STRUCTURAL pass-through: the raw context array is handed to the executor's
 *                map/rules reader untouched (a scalar coercion would destroy the map's keys), and that
 *                reader fail-softs on a malformed value.
 *
 * The two nulls always coincide (a structural policy), enforced by the named constructors — there is no
 * inconsistent "loose gate but scalar coercion" state.
 */
final class ArgVariablePolicy
{
    /** @param array<int, WorkflowVariableType>|null $refTypes */
    private function __construct(
        public readonly ?array $refTypes,
        public readonly ?WorkflowVariableType $coerceTo,
    ) {}

    /**
     * A strict single-VALUE control (text/number/boolean/date): the ref, its sub-pipeline terminal, and
     * the runtime coercion are all exactly $type — unchanged from phase-4a.
     */
    public static function value(WorkflowVariableType $type): self
    {
        return new self([$type], $type);
    }

    /**
     * A single-OPTION control (select / sourceOption / choiceFallback): a variable whose type is `enum`
     * OR `text` (a value that stringifies to an option key). Coerced to a string (ENUM). Option-set
     * MEMBERSHIP is unverifiable at write time, so it is deferred to runtime fail-soft (an out-of-set
     * value falls to the op's existing not-found / fallback behaviour, never a crash).
     */
    public static function option(): self
    {
        return new self([WorkflowVariableType::ENUM, WorkflowVariableType::TEXT], WorkflowVariableType::ENUM);
    }

    /**
     * A multi-OPTION control (sourceOptions): a variable whose type is `multi` (an array of option
     * values). Coerced to an array (MULTI); per-element membership is deferred to runtime fail-soft.
     */
    public static function options(): self
    {
        return new self([WorkflowVariableType::MULTI], WorkflowVariableType::MULTI);
    }

    /**
     * A STRUCTURAL control (sourceMap = a {option: target} map; choiceRules = a {when, then} rule list):
     * a variable ref for the WHOLE structure. Gated loosely at write time (no flat-type equality) and
     * passed through raw at runtime — the exact shape is a runtime fail-soft concern.
     */
    public static function structural(): self
    {
        return new self(null, null);
    }

    /** Whether this control is gated loosely (structural): the loose write gate + runtime pass-through. */
    public function isStructural(): bool
    {
        return $this->refTypes === null;
    }
}
