<?php

namespace App\Modules\Variables\Enums;

/**
 * The ONE place a variable-operation PIPELINE's HARD bounds live — split out of Workflows'
 * ConditionTreeLimits (which keeps the condition-TREE structural caps MAX_DEPTH/MAX_CHILDREN) when the
 * type system + engine moved to the Variables module. The write-validator (PipelineValidator) enforces
 * these and the runtime engine (OperationExecutor) re-checks them DEFENSIVELY (fail-closed), so a bound
 * can never drift between validation and evaluation and a persisted-then-corrupted pipeline can never
 * blow the stack. The engine consumers (the shared VariableResolver, and Workflows' WorkflowConditionEngine)
 * read them too — one source, both sides.
 */
final class PipelineLimits
{
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
     * (PipelineValidator) and the runtime resolver (VariableResolver) read it, so the accepted
     * depth can never drift between validation and evaluation.
     */
    public const MAX_ARG_VARIABLE_DEPTH = 3;

    /**
     * Maximum elements an ARRAY transform (count/at now; map/filter/sort/reduce in a later wave) will
     * iterate. An array longer than this fails the op CLOSED — a safety belt against a hostile/huge
     * stored value, mirroring MAX_PIPELINE_STEPS for pipeline length. count/at are O(1)/O(1) so they do
     * not consume it this wave; it is defined now so the later per-element executor and write-validator
     * read the SAME bound and it can never drift.
     */
    public const MAX_ARRAY_ITERATIONS = 1000;

    /**
     * Maximum nesting depth of ELEMENT pipelines (a map/filter/sort/reduce whose element pipeline itself
     * contains another array transform … and so on). Beyond it a config is REJECTED at write time and an
     * element pipeline fails CLOSED at runtime. Defined now (unused by count/at, which take no element
     * pipeline) so the later waves and both sides — write-validator and runtime executor — share one
     * bound, exactly as MAX_ARG_VARIABLE_DEPTH does for argument variables.
     */
    public const MAX_ELEMENT_PIPELINE_DEPTH = 3;

    /**
     * Maximum EXPANSION depth of a custom FUNCTION (Phase 3b). A function op is executed by EXPANSION —
     * the engine binds {input + args} into a scope frame and RE-ENTERS the executor on the function's
     * body, one level deeper. Functions may NEST (a body may call another function), so this caps that
     * re-entry chain: beyond it a function op FAILS CLOSED (OperationResult::failure — never loops).
     *
     * Unlike the arg-variable / element caps this guards a REAL loop risk: a cyclic reference graph is
     * rejected at write time (FunctionDefinitionValidator's DFS), but a corrupted / raced / hand-written
     * row can carry a cycle the write path never saw. This depth cap is the FIRST of two fail-closed
     * backstops (the second is the executor's active-function VISITED-SET), so a persisted-then-corrupted
     * cycle can never blow the stack — it fails the gate/step closed instead. Threaded like $elementDepth.
     */
    public const MAX_FUNCTION_EXPANSION_DEPTH = 5;
}
