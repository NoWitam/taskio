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
}
