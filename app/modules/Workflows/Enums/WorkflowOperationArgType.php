<?php

namespace App\Modules\Workflows\Enums;

use App\Modules\Workflows\DTOs\ArgVariablePolicy;

/**
 * The CONTROL type of a single operation argument — the backend mirror of the FE
 * `VariableOperationArgumentType` union (extensions/types.ts). It is NOT a value type
 * (WorkflowVariableType): beyond the value primitives it carries the SOURCE-driven kinds whose
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
enum WorkflowOperationArgType: string
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

    /**
     * The policy for accepting a VARIABLE-supplied value in this arg — the SINGLE source both the
     * write-validator and the runtime resolver read, so a variable argument is gated at write time and
     * coerced at run time identically and can never drift (mirrors how this enum owns the arg contract).
     *
     * EVERY control now accepts a variable (phase-4b widened phase-4a's value-only rule). Per category:
     *   - VALUE (text/number/boolean/date)          strict single-type match — unchanged from phase-4a.
     *   - single-OPTION (select/sourceOption/         a variable whose type is enum OR text (a value that
     *     choiceFallback)                            stringifies to an option key); coerced to a string.
     *                                                Option-set membership is deferred to runtime fail-soft.
     *   - multi-OPTION (sourceOptions)               a variable whose type is multi; per-element membership
     *                                                deferred to runtime fail-soft.
     *   - STRUCTURAL (sourceMap/choiceRules)         a ref for the WHOLE structure — the flat variable type
     *                                                cannot express a map / rule list, so the ref is gated
     *                                                LOOSELY (whitelisted root + present in the reference
     *                                                index) and the exact shape is deferred to runtime
     *                                                fail-soft (the executor's map/rules reader fail-softs).
     *
     * See ArgVariablePolicy for how each side consumes the two facets (refTypes + coerceTo). The FE
     * mirror (operationHelpers.ts `argVariablePolicy`) must track this per-arg gate.
     */
    public function argVariablePolicy(): ArgVariablePolicy
    {
        return match ($this) {
            self::TEXT => ArgVariablePolicy::value(WorkflowVariableType::TEXT),
            self::NUMBER => ArgVariablePolicy::value(WorkflowVariableType::NUMBER),
            self::BOOLEAN => ArgVariablePolicy::value(WorkflowVariableType::BOOLEAN),
            self::DATE => ArgVariablePolicy::value(WorkflowVariableType::DATE),
            self::SELECT, self::SOURCE_OPTION, self::CHOICE_FALLBACK => ArgVariablePolicy::option(),
            self::SOURCE_OPTIONS => ArgVariablePolicy::options(),
            self::SOURCE_MAP, self::CHOICE_RULES => ArgVariablePolicy::structural(),
        };
    }
}
