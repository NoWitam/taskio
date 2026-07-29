<?php

namespace App\Modules\Variables\DTOs;

use App\Modules\Variables\Enums\VariableType;

/**
 * The per-arg-control policy for accepting a VARIABLE-supplied operation argument (phase-4b) — the
 * SINGLE source both the write-validator (WorkflowConditionTreeValidator::validateArgVariable) and the
 * runtime resolver (VariableResolver::resolveArgVariable) read, so a variable argument is gated
 * at write time and coerced at run time identically and can never drift. Built by the ONE match in
 * OperationArgType::argVariablePolicy(); EVERY arg control now accepts a variable (phase-4b
 * widened the option/map/rules controls, which were literal-only in phase-4a).
 *
 * Two orthogonal facets, one per side of the contract:
 *   - $refTypes  the VariableTypes a variable ref (and, when it carries one, its sub-pipeline
 *                TERMINAL) may declare at WRITE time. null = STRUCTURAL CONTAINER: the control is not a
 *                single whole-arg variable at all (see below), so it declares no whole-arg ref types.
 *   - $coerceTo  the VariableType a RESOLVED value is coerced to before the PURE executor reads
 *                it. null = STRUCTURAL CONTAINER: there is no whole-arg value to coerce — each ENTRY is
 *                resolved individually to its own target type.
 *
 * STRUCTURAL CONTAINER (sourceMap/choiceRules, Defect-3): the flat variable type cannot express a whole
 * {option: target} map / {when, then} rule list, and the whole-structure-as-one-variable design was
 * removed. These controls are per-entry CONTAINERS: the arg is a plain map / rule list whose ENTRIES may
 * each be a value-or-variable union, resolved and validated ONE ENTRY at a time (never as one variable).
 * isStructural() is the marker that routes a control to that per-entry handling; both null facets simply
 * mean "this control has no whole-arg variable form". The two nulls always coincide, enforced by the named
 * constructors.
 */
final class ArgVariablePolicy
{
    /** @param array<int, VariableType>|null $refTypes */
    private function __construct(
        public readonly ?array $refTypes,
        public readonly ?VariableType $coerceTo,
    ) {}

    /**
     * A strict single-VALUE control (text/number/boolean/date): the ref, its sub-pipeline terminal, and
     * the runtime coercion are all exactly $type — unchanged from phase-4a.
     */
    public static function value(VariableType $type): self
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
        return new self([VariableType::ENUM, VariableType::TEXT], VariableType::ENUM);
    }

    /**
     * A multi-OPTION control (sourceOptions): a variable whose type is `multi` (an array of option
     * values). Coerced to an array (MULTI); per-element membership is deferred to runtime fail-soft.
     */
    public static function options(): self
    {
        return new self([VariableType::MULTI], VariableType::MULTI);
    }

    /**
     * A STRUCTURAL CONTAINER control (sourceMap = a {option: target} map; choiceRules = a {when, then} rule
     * list): NOT a whole-arg variable. The container is a plain map / rule list whose ENTRIES may each be a
     * value-or-variable union, resolved/validated per entry (Defect-3) — so it declares no whole-arg ref
     * type and no whole-arg coercion (both null).
     */
    public static function structural(): self
    {
        return new self(null, null);
    }

    /**
     * A PER-ELEMENT-PIPELINE control (elementPipeline / reduceSeed, array-ops wave 2): also NOT a whole-arg
     * variable (both facets null), but distinct in intent from a structural CONTAINER — it carries a nested
     * pipeline / typed literal the write-validator + resolver handle through a DEDICATED branch keyed on the
     * arg CASE, not per-entry union resolution. isStructural() is true for it too (refTypes null), which is
     * exactly what routes it away from the generic whole-arg-variable handling on both sides.
     */
    public static function elementPipeline(): self
    {
        return new self(null, null);
    }

    /**
     * A LITERAL-ONLY typed element DEFAULT control (elementDefault, array-ops F4): also NOT a whole-arg
     * variable (both facets null), like reduceSeed. A `{type, value}` literal whose type is locked to the
     * array's element base — a variable-shaped default is rejected. isStructural() is true (refTypes null),
     * which routes it away from the generic value-or-variable handling on both sides (the write-validator
     * type-checks the literal in the pipeline walk; the resolver leaves it untouched for the executor).
     */
    public static function elementDefault(): self
    {
        return new self(null, null);
    }

    /**
     * Whether this control has NO whole-arg variable form (sourceMap/choiceRules per-entry containers AND the
     * elementPipeline/reduceSeed nested controls). The two nulls always coincide; both route away from the
     * generic value-or-variable handling to a dedicated per-case branch — see structural() / elementPipeline().
     */
    public function isStructural(): bool
    {
        return $this->refTypes === null;
    }
}
