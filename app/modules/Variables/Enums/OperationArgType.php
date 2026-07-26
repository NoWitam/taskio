<?php

namespace App\Modules\Variables\Enums;

use App\Modules\Variables\DTOs\ArgVariablePolicy;

/**
 * The CONTROL type of a single operation argument — the backend mirror of the FE
 * `VariableOperationArgumentType` union (extensions/types.ts). It is NOT a value type
 * (VariableType): beyond the value primitives it carries the SOURCE-driven kinds whose
 * choices come from the picked source variable's options, not from the operation:
 *
 *   - text / number / boolean / date  a literal typed by the arg.
 *   - select                          one of the arg's OWN fixed options (none of the standard
 *                                     66 ops use it today; kept for parity with the FE union).
 *   - sourceOption                    ONE option value of the source enum/multi.
 *   - sourceOptions                   MANY option values (the arg value is a string[]).
 *   - sourceMap                       one TARGET value PER option (the arg value is a
 *                                     {optionValue: target} map; the target kind is the arg's mapType).
 *   - choiceRules                     an ordered list of {when, then} match rules whose `then` is a
 *                                     value of the DESTINATION field's option set (match_to_choice).
 *   - choiceFallback                  one value of the DESTINATION field's option set, used when no
 *                                     rule matched (match_to_choice; required for totality).
 *
 * The choice* kinds are TARGET-driven, not source-driven: their allowed option set is the destination
 * step field's options (e.g. task priority), injected per-field at validation/render time — it is NOT
 * part of the static descriptor (there is no mapType for them).
 *
 * The engine validates + reads each arg by this kind, so the wire contract is enforced 1:1.
 */
enum OperationArgType: string
{
    case TEXT = 'text';
    case NUMBER = 'number';
    case BOOLEAN = 'boolean';
    case DATE = 'date';
    case SELECT = 'select';
    case SOURCE_OPTION = 'sourceOption';
    case SOURCE_OPTIONS = 'sourceOptions';
    case SOURCE_MAP = 'sourceMap';
    case CHOICE_RULES = 'choiceRules';
    case CHOICE_FALLBACK = 'choiceFallback';
    // ── ARRAY TRANSFORMS (array-ops wave 2) ───────────────────────────────────
    // A PER-ELEMENT PIPELINE (`elementPipeline`) and a typed reduce SEED (`reduceSeed`) — the two new
    // controls the higher-order array ops (map/filter/sort/reduce) carry. Neither is a whole-arg
    // VARIABLE union (their ArgVariablePolicy is elementPipeline() — refTypes/coerceTo null, like a
    // structural container), so the write-validator + resolver route them through DEDICATED branches
    // keyed on the case, not the generic value-or-variable machinery:
    //   - elementPipeline  a list of pipeline steps `{op, args}` (same wire shape as ChoiceRule.when /
    //                      ArgVariableValue.pipeline) rooted at the array's ELEMENT descriptor. The
    //                      synthetic `element` / `index` scope variables resolve inside it and NOWHERE
    //                      else. Its terminal is gated per op (filter→boolean, sort→number, map→any
    //                      base, reduce→seed base) at write time (enforced BY CONSTRUCTION).
    //   - reduceSeed       a self-describing typed literal `{type, value}` (type ∈ text|number|boolean|
    //                      date) — the reduce accumulator's initial value AND the type the reducer
    //                      pipeline must terminate in. Reuses the existing literal type-check machinery.
    case ELEMENT_PIPELINE = 'elementPipeline';
    case REDUCE_SEED = 'reduceSeed';
    // ── ARRAY ELEMENT DEFAULT (array-ops F4) ───────────────────────────────────
    // A REQUIRED-when-non-terminal typed DEFAULT for array_at — a self-describing `{type, value}` literal
    // (like reduceSeed) whose type is LOCKED to the array's ELEMENT base (no free type select). array_at's
    // element is NULLABLE (an empty array / a clamped-to-nothing index), so a FOLLOWING op would consume
    // the null as data; a valid default is substituted for the null BEFORE the next op runs. LITERAL-ONLY:
    // never a whole-arg variable (its ArgVariablePolicy is elementDefault() — both facets null, like
    // reduceSeed), so the write-validator + resolver route it through DEDICATED handling, not the generic
    // value-or-variable machinery. The REQUIREMENT + element-base type-check are position-aware, so they
    // live in the pipeline walk (WorkflowConditionTreeValidator::validateElementDefault), not this control.
    case ELEMENT_DEFAULT = 'elementDefault';

    /**
     * The policy for accepting a VARIABLE-supplied value in this arg — the SINGLE source both the
     * write-validator and the runtime resolver read, so a variable argument is gated at write time and
     * coerced at run time identically and can never drift (mirrors how this enum owns the arg contract).
     *
     * EVERY control accepts a variable, but a STRUCTURAL container does so PER ENTRY, not as a whole arg.
     * Per category:
     *   - VALUE (text/number/boolean/date)          strict single-type match — unchanged from phase-4a.
     *   - single-OPTION (select/sourceOption/         a variable whose type is enum OR text (a value that
     *     choiceFallback)                            stringifies to an option key); coerced to a string.
     *                                                Option-set membership is deferred to runtime fail-soft.
     *   - multi-OPTION (sourceOptions)               a variable whose type is multi; per-element membership
     *                                                deferred to runtime fail-soft.
     *   - STRUCTURAL container (sourceMap/           NOT a whole-arg variable (Defect-3): the arg is a plain
     *     choiceRules)                               map / rule list whose ENTRIES may EACH be a value-or-
     *                                                variable union, resolved/validated one entry at a time
     *                                                against the entry's TARGET type. isStructural() routes
     *                                                to that per-entry handling.
     *
     * See ArgVariablePolicy for how each side consumes the two facets (refTypes + coerceTo). The FE
     * mirror (operationHelpers.ts `argVariablePolicy`) must track this per-arg gate.
     */
    public function argVariablePolicy(): ArgVariablePolicy
    {
        return match ($this) {
            self::TEXT => ArgVariablePolicy::value(VariableType::TEXT),
            self::NUMBER => ArgVariablePolicy::value(VariableType::NUMBER),
            self::BOOLEAN => ArgVariablePolicy::value(VariableType::BOOLEAN),
            self::DATE => ArgVariablePolicy::value(VariableType::DATE),
            self::SELECT, self::SOURCE_OPTION, self::CHOICE_FALLBACK => ArgVariablePolicy::option(),
            self::SOURCE_OPTIONS => ArgVariablePolicy::options(),
            self::SOURCE_MAP, self::CHOICE_RULES => ArgVariablePolicy::structural(),
            // A per-element pipeline / typed reduce seed is NEVER a whole-arg variable — it is handled by
            // a dedicated write-validator + resolver branch (like a structural container, both facets null).
            self::ELEMENT_PIPELINE, self::REDUCE_SEED => ArgVariablePolicy::elementPipeline(),
            // A typed element DEFAULT (array_at, F4) is LITERAL-ONLY: like reduceSeed it is a `{type, value}`
            // self-describing literal, never a whole-arg variable (both facets null), so a variable-shaped
            // default is rejected and the walk validates the literal against the element base.
            self::ELEMENT_DEFAULT => ArgVariablePolicy::elementDefault(),
        };
    }
}
