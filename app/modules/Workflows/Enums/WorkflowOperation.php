<?php

namespace App\Modules\Workflows\Enums;

use App\Modules\Workflows\DTOs\WorkflowOperationArg;

/**
 * The canonical variable-operations vocabulary — the backend 1:1 mirror of the FE
 * `standardOperationsCatalog()` (resources/js/next/ui/editor/extensions/standardOperations.ts).
 * The 68 ids ARE the stable wire contract of a pipeline: an id may only be added, never renamed.
 * Each op takes ONE input type and yields ONE output type, so a CONDITION pipeline is a type-flow
 * that MUST terminate in boolean (any variable can become a condition). A VALUE pipeline targeting a
 * choice field (e.g. a task priority) instead terminates in one of the two choice-producing ops
 * (enum_to_choice / match_to_choice, producesChoice() = true), which map the value into the
 * destination field's own option set (the target options are injected per-field, not part of the op).
 *
 * This enum owns three things the validator and engine both read (so they can never drift):
 *   - inputType()      the WorkflowVariableType the op consumes.
 *   - outputType()     the WorkflowVariableType the op produces.
 *   - argDescriptors() the ordered argument descriptors (id + control type + sourceMap mapType).
 *
 * RUNTIME SEMANTICS are documented on WorkflowConditionEngine (the fail-closed conversions,
 * bool_to_* when_true/when_false branches, enum_to_* per-option mapping, divide-by-zero, 1-based
 * substring, strict Y-m-d dates, weekday 0=Sunday, multi_to_text joining option VALUES). This enum
 * is metadata only; it evaluates nothing.
 */
enum WorkflowOperation: string
{
    // ── TEXT (input: text) ────────────────────────────────────────────────────
    case TEXT_UPPERCASE = 'text_uppercase';
    case TEXT_LOWERCASE = 'text_lowercase';
    case TEXT_TRIM = 'text_trim';
    case TEXT_SUBSTRING = 'text_substring';
    case TEXT_REPLACE = 'text_replace';
    case TEXT_APPEND = 'text_append';
    case TEXT_PREPEND = 'text_prepend';
    case TEXT_LENGTH = 'text_length';
    case TEXT_TO_NUMBER = 'text_to_number';
    case TEXT_EQUALS = 'text_equals';
    case TEXT_NOT_EQUALS = 'text_not_equals';
    case TEXT_CONTAINS = 'text_contains';
    case TEXT_STARTS_WITH = 'text_starts_with';
    case TEXT_ENDS_WITH = 'text_ends_with';
    case TEXT_IS_EMPTY = 'text_is_empty';
    case TEXT_IS_NOT_EMPTY = 'text_is_not_empty';
    case MATCH_TO_CHOICE = 'match_to_choice'; // text -> enum (choice): first matching rule, else fallback

    // ── NUMBER (input: number) ────────────────────────────────────────────────
    case NUM_ADD = 'num_add';
    case NUM_SUBTRACT = 'num_subtract';
    case NUM_MULTIPLY = 'num_multiply';
    case NUM_DIVIDE = 'num_divide';
    case NUM_ABS = 'num_abs';
    case NUM_ROUND = 'num_round';
    case NUM_FLOOR = 'num_floor';
    case NUM_CEIL = 'num_ceil';
    case NUM_TO_TEXT = 'num_to_text';
    case NUM_EQ = 'num_eq';
    case NUM_NEQ = 'num_neq';
    case NUM_GT = 'num_gt';
    case NUM_GTE = 'num_gte';
    case NUM_LT = 'num_lt';
    case NUM_LTE = 'num_lte';
    case NUM_BETWEEN = 'num_between';

    // ── BOOLEAN (input: boolean) ──────────────────────────────────────────────
    case BOOL_NOT = 'bool_not';
    case BOOL_TO_NUMBER = 'bool_to_number';
    case BOOL_TO_TEXT = 'bool_to_text';

    // ── DATE (input: date) ────────────────────────────────────────────────────
    case DATE_ADD_DAYS = 'date_add_days';
    case DATE_SUBTRACT_DAYS = 'date_subtract_days';
    case DATE_ADD_MONTHS = 'date_add_months';
    case DATE_ADD_YEARS = 'date_add_years';
    case DATE_START_OF_MONTH = 'date_start_of_month';
    case DATE_END_OF_MONTH = 'date_end_of_month';
    case DATE_DAY = 'date_day';
    case DATE_MONTH = 'date_month';
    case DATE_YEAR = 'date_year';
    case DATE_WEEKDAY = 'date_weekday';
    case DATE_TO_TEXT = 'date_to_text';
    case DATE_BEFORE = 'date_before';
    case DATE_AFTER = 'date_after';
    case DATE_ON = 'date_on';
    case DATE_BETWEEN = 'date_between';
    case DATE_IS_WEEKEND = 'date_is_weekend';
    case DATE_IS_PAST = 'date_is_past';
    case DATE_IS_FUTURE = 'date_is_future';

    // ── ENUM (input: enum) ────────────────────────────────────────────────────
    case ENUM_IS = 'enum_is';
    case ENUM_IS_NOT = 'enum_is_not';
    case ENUM_IN = 'enum_in';
    case ENUM_TO_TEXT = 'enum_to_text';
    case ENUM_TO_NUMBER = 'enum_to_number';
    case ENUM_TO_DATE = 'enum_to_date';
    case ENUM_TO_CHOICE = 'enum_to_choice'; // enum -> enum (choice): per-option map into the target option set

    // ── MULTI (input: multi) ──────────────────────────────────────────────────
    case MULTI_INCLUDES = 'multi_includes';
    case MULTI_EXCLUDES = 'multi_excludes';
    case MULTI_INCLUDES_ANY = 'multi_includes_any';
    case MULTI_INCLUDES_ALL = 'multi_includes_all';
    case MULTI_COUNT = 'multi_count';
    case MULTI_IS_EMPTY = 'multi_is_empty';
    case MULTI_TO_TEXT = 'multi_to_text';

    /** The value type this op CONSUMES (its single input). */
    public function inputType(): WorkflowVariableType
    {
        return match ($this) {
            self::TEXT_UPPERCASE, self::TEXT_LOWERCASE, self::TEXT_TRIM, self::TEXT_SUBSTRING,
            self::TEXT_REPLACE, self::TEXT_APPEND, self::TEXT_PREPEND, self::TEXT_LENGTH,
            self::TEXT_TO_NUMBER, self::TEXT_EQUALS, self::TEXT_NOT_EQUALS, self::TEXT_CONTAINS,
            self::TEXT_STARTS_WITH, self::TEXT_ENDS_WITH, self::TEXT_IS_EMPTY, self::TEXT_IS_NOT_EMPTY,
            self::MATCH_TO_CHOICE => WorkflowVariableType::TEXT,

            self::NUM_ADD, self::NUM_SUBTRACT, self::NUM_MULTIPLY, self::NUM_DIVIDE, self::NUM_ABS,
            self::NUM_ROUND, self::NUM_FLOOR, self::NUM_CEIL, self::NUM_TO_TEXT, self::NUM_EQ,
            self::NUM_NEQ, self::NUM_GT, self::NUM_GTE, self::NUM_LT, self::NUM_LTE, self::NUM_BETWEEN => WorkflowVariableType::NUMBER,

            self::BOOL_NOT, self::BOOL_TO_NUMBER, self::BOOL_TO_TEXT => WorkflowVariableType::BOOLEAN,

            self::DATE_ADD_DAYS, self::DATE_SUBTRACT_DAYS, self::DATE_ADD_MONTHS, self::DATE_ADD_YEARS,
            self::DATE_START_OF_MONTH, self::DATE_END_OF_MONTH, self::DATE_DAY, self::DATE_MONTH,
            self::DATE_YEAR, self::DATE_WEEKDAY, self::DATE_TO_TEXT, self::DATE_BEFORE, self::DATE_AFTER,
            self::DATE_ON, self::DATE_BETWEEN, self::DATE_IS_WEEKEND, self::DATE_IS_PAST, self::DATE_IS_FUTURE => WorkflowVariableType::DATE,

            self::ENUM_IS, self::ENUM_IS_NOT, self::ENUM_IN, self::ENUM_TO_TEXT, self::ENUM_TO_NUMBER,
            self::ENUM_TO_DATE, self::ENUM_TO_CHOICE => WorkflowVariableType::ENUM,

            self::MULTI_INCLUDES, self::MULTI_EXCLUDES, self::MULTI_INCLUDES_ANY, self::MULTI_INCLUDES_ALL,
            self::MULTI_COUNT, self::MULTI_IS_EMPTY, self::MULTI_TO_TEXT => WorkflowVariableType::MULTI,
        };
    }

    /** The value type this op PRODUCES (its single output). */
    public function outputType(): WorkflowVariableType
    {
        return match ($this) {
            // text -> text
            self::TEXT_UPPERCASE, self::TEXT_LOWERCASE, self::TEXT_TRIM, self::TEXT_SUBSTRING,
            self::TEXT_REPLACE, self::TEXT_APPEND, self::TEXT_PREPEND,
            // number -> text / boolean -> text / date -> text / multi -> text / enum -> text
            self::NUM_TO_TEXT, self::BOOL_TO_TEXT, self::DATE_TO_TEXT, self::MULTI_TO_TEXT, self::ENUM_TO_TEXT => WorkflowVariableType::TEXT,

            // text -> number / number -> number / bool -> number / date -> number / multi -> number / enum -> number
            self::TEXT_LENGTH, self::TEXT_TO_NUMBER,
            self::NUM_ADD, self::NUM_SUBTRACT, self::NUM_MULTIPLY, self::NUM_DIVIDE, self::NUM_ABS,
            self::NUM_ROUND, self::NUM_FLOOR, self::NUM_CEIL,
            self::BOOL_TO_NUMBER,
            self::DATE_DAY, self::DATE_MONTH, self::DATE_YEAR, self::DATE_WEEKDAY,
            self::MULTI_COUNT, self::ENUM_TO_NUMBER => WorkflowVariableType::NUMBER,

            // date -> date / enum -> date
            self::DATE_ADD_DAYS, self::DATE_SUBTRACT_DAYS, self::DATE_ADD_MONTHS, self::DATE_ADD_YEARS,
            self::DATE_START_OF_MONTH, self::DATE_END_OF_MONTH, self::ENUM_TO_DATE => WorkflowVariableType::DATE,

            // enum -> enum / text -> enum (the CHOICE terminals: map a value into the target option set)
            self::ENUM_TO_CHOICE, self::MATCH_TO_CHOICE => WorkflowVariableType::ENUM,

            // everything else terminates in boolean (the condition predicates)
            self::TEXT_EQUALS, self::TEXT_NOT_EQUALS, self::TEXT_CONTAINS, self::TEXT_STARTS_WITH,
            self::TEXT_ENDS_WITH, self::TEXT_IS_EMPTY, self::TEXT_IS_NOT_EMPTY,
            self::NUM_EQ, self::NUM_NEQ, self::NUM_GT, self::NUM_GTE, self::NUM_LT, self::NUM_LTE, self::NUM_BETWEEN,
            self::BOOL_NOT,
            self::DATE_BEFORE, self::DATE_AFTER, self::DATE_ON, self::DATE_BETWEEN,
            self::DATE_IS_WEEKEND, self::DATE_IS_PAST, self::DATE_IS_FUTURE,
            self::ENUM_IS, self::ENUM_IS_NOT, self::ENUM_IN,
            self::MULTI_INCLUDES, self::MULTI_EXCLUDES, self::MULTI_INCLUDES_ANY, self::MULTI_INCLUDES_ALL,
            self::MULTI_IS_EMPTY => WorkflowVariableType::BOOLEAN,
        };
    }

    /**
     * The ordered argument descriptors for this op ([] for a nullary op).
     *
     * @return array<int, WorkflowOperationArg>
     */
    public function argDescriptors(): array
    {
        return match ($this) {
            self::TEXT_SUBSTRING => [
                WorkflowOperationArg::literal('start', WorkflowOperationArgType::NUMBER),
                WorkflowOperationArg::literal('length', WorkflowOperationArgType::NUMBER),
            ],
            self::TEXT_REPLACE => [
                WorkflowOperationArg::literal('search', WorkflowOperationArgType::TEXT),
                WorkflowOperationArg::literal('replace', WorkflowOperationArgType::TEXT),
            ],
            self::TEXT_APPEND, self::TEXT_PREPEND => [
                WorkflowOperationArg::literal('value', WorkflowOperationArgType::TEXT),
            ],
            self::TEXT_EQUALS, self::TEXT_NOT_EQUALS, self::TEXT_CONTAINS,
            self::TEXT_STARTS_WITH, self::TEXT_ENDS_WITH => [
                WorkflowOperationArg::literal('value', WorkflowOperationArgType::TEXT),
            ],

            self::NUM_ADD, self::NUM_SUBTRACT, self::NUM_MULTIPLY, self::NUM_DIVIDE,
            self::NUM_EQ, self::NUM_NEQ, self::NUM_GT, self::NUM_GTE, self::NUM_LT, self::NUM_LTE => [
                WorkflowOperationArg::literal('value', WorkflowOperationArgType::NUMBER),
            ],
            self::NUM_ROUND => [
                WorkflowOperationArg::literal('precision', WorkflowOperationArgType::NUMBER),
            ],
            self::NUM_BETWEEN => [
                WorkflowOperationArg::literal('from', WorkflowOperationArgType::NUMBER),
                WorkflowOperationArg::literal('to', WorkflowOperationArgType::NUMBER),
            ],

            self::BOOL_TO_NUMBER => [
                WorkflowOperationArg::literal('when_true', WorkflowOperationArgType::NUMBER),
                WorkflowOperationArg::literal('when_false', WorkflowOperationArgType::NUMBER),
            ],
            self::BOOL_TO_TEXT => [
                WorkflowOperationArg::literal('when_true', WorkflowOperationArgType::TEXT),
                WorkflowOperationArg::literal('when_false', WorkflowOperationArgType::TEXT),
            ],

            self::DATE_ADD_DAYS, self::DATE_SUBTRACT_DAYS, self::DATE_ADD_MONTHS, self::DATE_ADD_YEARS => [
                WorkflowOperationArg::literal('value', WorkflowOperationArgType::NUMBER),
            ],
            self::DATE_BEFORE, self::DATE_AFTER, self::DATE_ON => [
                WorkflowOperationArg::literal('value', WorkflowOperationArgType::DATE),
            ],
            self::DATE_BETWEEN => [
                WorkflowOperationArg::literal('from', WorkflowOperationArgType::DATE),
                WorkflowOperationArg::literal('to', WorkflowOperationArgType::DATE),
            ],

            self::ENUM_IS, self::ENUM_IS_NOT => [
                WorkflowOperationArg::literal('value', WorkflowOperationArgType::SOURCE_OPTION),
            ],
            self::ENUM_IN => [
                WorkflowOperationArg::literal('values', WorkflowOperationArgType::SOURCE_OPTIONS),
            ],
            self::ENUM_TO_TEXT => [WorkflowOperationArg::map('mapping', WorkflowVariableType::TEXT)],
            self::ENUM_TO_NUMBER => [WorkflowOperationArg::map('mapping', WorkflowVariableType::NUMBER)],
            self::ENUM_TO_DATE => [WorkflowOperationArg::map('mapping', WorkflowVariableType::DATE)],
            // The mapType is ENUM so the write-validator applies the per-field TARGET option set to the
            // mapped VALUES (the keys stay ⊆ the source options). The target options are injected per field.
            self::ENUM_TO_CHOICE => [WorkflowOperationArg::map('mapping', WorkflowVariableType::ENUM)],
            self::MATCH_TO_CHOICE => [
                WorkflowOperationArg::choiceRules('rules'),
                WorkflowOperationArg::choiceFallback('fallback'),
            ],

            self::MULTI_INCLUDES, self::MULTI_EXCLUDES => [
                WorkflowOperationArg::literal('value', WorkflowOperationArgType::SOURCE_OPTION),
            ],
            self::MULTI_INCLUDES_ANY, self::MULTI_INCLUDES_ALL => [
                WorkflowOperationArg::literal('values', WorkflowOperationArgType::SOURCE_OPTIONS),
            ],

            default => [],
        };
    }

    /**
     * Whether this op is a CHOICE-producing terminal — it maps a value into a destination field's own
     * option set (e.g. a task priority). A value pipeline that targets a choice field must END in such
     * an op; call this at the terminal-op check instead of hardcoding ids, so a future choice op is
     * picked up automatically.
     */
    public function producesChoice(): bool
    {
        return match ($this) {
            self::ENUM_TO_CHOICE, self::MATCH_TO_CHOICE => true,
            default => false,
        };
    }

    /**
     * The label-less catalog of every op the workflow-catalog endpoint exposes: a list of
     * {id, input, output, args: [{id, type, mapType?}]}. Mirrors the "descriptors without labels"
     * pattern (the FE resolves labels via i18n).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalog(): array
    {
        return array_map(fn (self $op): array => [
            'id' => $op->value,
            'input' => $op->inputType()->value,
            'output' => $op->outputType()->value,
            'args' => array_map(fn (WorkflowOperationArg $arg): array => $arg->toArray(), $op->argDescriptors()),
        ], self::cases());
    }
}
