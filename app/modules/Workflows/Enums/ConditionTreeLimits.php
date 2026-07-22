<?php

namespace App\Modules\Workflows\Enums;

/**
 * The ONE place the condition-tree's HARD structural bounds live — mirroring ScheduleLimits for
 * the schedule descriptor. The write-validator (WorkflowConditionTreeValidator) enforces them and
 * the runtime engine (WorkflowConditionEngine) re-checks them DEFENSIVELY (fail-closed to false),
 * so a bound can never drift between validation and evaluation and a persisted-then-corrupted tree
 * can never blow the stack.
 *
 * The tree is a logic group of children; a child is either a nested group or a leaf condition
 * carrying a pipeline of operations. Depth counts GROUP nesting only (the root group is depth 1;
 * leaf conditions do not add depth).
 */
final class ConditionTreeLimits
{
    /** Maximum group nesting depth (root group = 1). */
    public const MAX_DEPTH = 5;

    /** Maximum children a single group may carry. */
    public const MAX_CHILDREN = 10;

    /** Maximum operation steps a single condition's pipeline may carry. */
    public const MAX_PIPELINE_STEPS = 10;

    /**
     * Maximum ARG-VARIABLE nesting depth (phase-4a). An operation argument may itself be a variable
     * (a value-or-variable union) whose own pipeline may carry another variable argument … and so on;
     * this caps how deep that tree may go (level 1 = a top-level op's argument). Beyond it a config is
     * REJECTED at write time and an argument resolves FAIL-SOFT (null/empty) at runtime.
     *
     * There are NO cycles to guard against: an arg-variable references a whitelisted CONTEXT DATA path
     * (trigger/steps/globals — a plain Arr::get), NEVER another argument DEFINITION, so resolving one
     * can never re-enter its own definition. This bound is purely a safety belt against a hostile/huge
     * stored config's nesting depth (a finite tree), not a loop guard. Both the write-validator
     * (WorkflowConditionTreeValidator) and the runtime resolver (WorkflowVariableResolver) read it, so
     * the accepted depth can never drift between validation and evaluation.
     */
    public const MAX_ARG_VARIABLE_DEPTH = 3;
}
