<?php

namespace App\Modules\Workflows\Enums;

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
     * The WorkflowVariableType this arg coerces to when its value is supplied by a VARIABLE rather than
     * a constant literal (phase-4a) — the single source both the write-validator and the runtime
     * resolver read so a variable argument is type-gated exactly like a literal one, and never drifts.
     *
     * Only the plain VALUE controls are variable-able: text → text, number → number, boolean → boolean,
     * date → date. The remaining controls return null (LITERAL-ONLY): sourceOption/sourceOptions/
     * sourceMap/choiceRules/choiceFallback are option-set-constrained (their membership in the source /
     * destination option set is unverifiable for a runtime variable), and `select` carries the arg's own
     * fixed options — so this iteration keeps them constant-only. A variable in such a slot is rejected
     * at write time and fails closed at runtime.
     */
    public function variableValueType(): ?WorkflowVariableType
    {
        return match ($this) {
            self::TEXT => WorkflowVariableType::TEXT,
            self::NUMBER => WorkflowVariableType::NUMBER,
            self::BOOLEAN => WorkflowVariableType::BOOLEAN,
            self::DATE => WorkflowVariableType::DATE,
            self::SELECT, self::SOURCE_OPTION, self::SOURCE_OPTIONS,
            self::SOURCE_MAP, self::CHOICE_RULES, self::CHOICE_FALLBACK => null,
        };
    }
}
