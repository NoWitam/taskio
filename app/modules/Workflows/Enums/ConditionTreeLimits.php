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
}
