<?php

namespace App\Modules\Workflows\Enums;

/**
 * A TYPED condition operator. Every operator belongs to exactly one field type's allow-list
 * (see VariableType::operators, which now returns these ids as strings) and the
 * WorkflowConditionEvaluator implements each.
 *
 * Conditions gate whether a form_submitted workflow runs: a flat AND-combined list of
 * {field, field_type, operator, value} clauses evaluated over the trigger payload's `fields`
 * map. The prior FLAT model (equals|not_equals|contains|in for every field) is replaced by
 * this per-type set so the FE builder, the validator, and the evaluator share one vocabulary.
 *
 * VALUE SHAPE per operator (validated by StoreWorkflowRequest, consumed by the evaluator):
 *   - between            requires a 2-element [from, to] array of date strings.
 *   - in                 requires an array of strings.
 *   - is_true / is_false take NO value (value is absent/ignored).
 *   - everything else    a single scalar (string/number/date-string).
 *
 * MISSING-PATH negatives: operators that assert an ABSENCE (not_equals, is_not, excludes)
 * PASS when the field is absent from the payload; every other operator FAILS on a missing
 * path. This mirrors the intuition "status is_not done" holds when there is no status at all.
 */
enum WorkflowConditionOperator: string
{
    // text
    case EQUALS = 'equals';
    case NOT_EQUALS = 'not_equals';
    case CONTAINS = 'contains';

    // number
    case EQ = 'eq';
    case NEQ = 'neq';
    case GT = 'gt';
    case GTE = 'gte';
    case LT = 'lt';
    case LTE = 'lte';

    // date
    case BEFORE = 'before';
    case AFTER = 'after';
    case ON = 'on';
    case BETWEEN = 'between';

    // enum
    case IS = 'is';
    case IS_NOT = 'is_not';
    case IN = 'in';

    // multi
    case INCLUDES = 'includes';
    case EXCLUDES = 'excludes';

    // boolean (value-less)
    case IS_TRUE = 'is_true';
    case IS_FALSE = 'is_false';

    // file (value-less): a file field is either answered or not. There is nothing meaningful
    // to compare a file to with a flat scalar operator — richer questions (its type, its name)
    // live in the pipeline operations of the condition tree.
    case FILLED = 'filled';
    case EMPTY = 'empty';

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether this operator ASSERTS ABSENCE — so it PASSES when the field is missing from the
     * payload. The evaluator short-circuits missing paths through this predicate.
     */
    public function passesOnMissingPath(): bool
    {
        return match ($this) {
            // `empty` belongs here for the same reason: a file field that never made it into
            // the payload is genuinely un-answered.
            self::NOT_EQUALS, self::NEQ, self::IS_NOT, self::EXCLUDES, self::EMPTY => true,
            default => false,
        };
    }

    /** Whether the operator ignores/omits its value (the boolean and file predicates). */
    public function isValueless(): bool
    {
        return match ($this) {
            self::IS_TRUE, self::IS_FALSE, self::FILLED, self::EMPTY => true,
            default => false,
        };
    }

    /** Whether the operator's value MUST be an array ([from,to] for between, options for in). */
    public function expectsArrayValue(): bool
    {
        return $this === self::BETWEEN || $this === self::IN;
    }
}
