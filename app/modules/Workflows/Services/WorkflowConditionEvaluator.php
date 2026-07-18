<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Enums\WorkflowConditionOperator;
use App\Modules\Workflows\Enums\WorkflowVariableType;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Evaluates a workflow's optional gate conditions against a form_submitted trigger payload.
 * Conditions are a TYPED, FLAT list of {field, field_type, operator, value} clauses,
 * AND-combined: every clause must pass for the workflow to run. An empty/absent list passes.
 *
 * Each clause reads `field` as a DOTTED path into the payload via Arr::get. In practice the FE
 * emits `fields.<id>` (the whitelisted answer map), but the evaluator does not hard-code that
 * prefix — any dotted path the payload carries resolves. Comparison is dispatched by
 * `field_type` (text|number|date|enum|multi|boolean) then `operator`:
 *
 *   text    equals / not_equals (string-normalized) · contains (substring)
 *   number  eq/neq/gt/gte/lt/lte (numeric; a non-numeric side fails)
 *   date    before/after/on (Carbon day/instant compare) · between ([from,to])
 *   enum    is / is_not (string equality) · in (membership in the value array)
 *   multi   includes / excludes (membership over the array payload value)
 *   boolean is_true / is_false (value-less; truthiness of the payload value)
 *
 * MISSING PATH: the clause FAILS for every operator EXCEPT the ABSENCE operators
 * (not_equals, neq, is_not, excludes) which PASS — an absent field trivially "is not X" and a
 * multi that isn't present trivially "excludes X". This is defined by
 * WorkflowConditionOperator::passesOnMissingPath so the rule lives in one place.
 *
 * SAFETY: an invalid stored date value (payload OR condition) makes the clause FAIL — it never
 * throws. An unknown operator/type is a definition-integrity bug (the FormRequest type-checks
 * both) so it fails CLOSED, never silently opening the gate.
 */
class WorkflowConditionEvaluator
{
    /** A sentinel distinct from null so a genuine null payload value is not read as "missing". */
    private const MISSING = "\0__workflow_condition_missing__\0";

    /**
     * @param  array<int, array{field?: string, field_type?: string, operator?: string, value?: mixed}>|null  $conditions
     * @param  array<string, mixed>  $payload
     */
    public function passes(?array $conditions, array $payload): bool
    {
        foreach ($conditions ?? [] as $condition) {
            if (!$this->clausePasses($condition, $payload)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{field?: string, field_type?: string, operator?: string, value?: mixed}  $condition
     * @param  array<string, mixed>  $payload
     */
    private function clausePasses(array $condition, array $payload): bool
    {
        $type = WorkflowVariableType::tryFrom((string) ($condition['field_type'] ?? ''));
        $operator = WorkflowConditionOperator::tryFrom((string) ($condition['operator'] ?? ''));

        // An unknown type or operator, or an operator that does not belong to the type, is a
        // definition-integrity bug the FormRequest rejects — fail closed.
        if ($type === null || $operator === null || !in_array($operator, $type->operatorCases(), true)) {
            return false;
        }

        $actual = Arr::get($payload, (string) ($condition['field'] ?? ''), self::MISSING);
        $value = $condition['value'] ?? null;

        if ($actual === self::MISSING) {
            return $operator->passesOnMissingPath();
        }

        return match ($type) {
            WorkflowVariableType::TEXT => $this->text($operator, $actual, $value),
            WorkflowVariableType::NUMBER => $this->number($operator, $actual, $value),
            WorkflowVariableType::DATE => $this->date($operator, $actual, $value),
            WorkflowVariableType::ENUM => $this->enum($operator, $actual, $value),
            WorkflowVariableType::MULTI => $this->multi($operator, $actual, $value),
            WorkflowVariableType::BOOLEAN => $this->boolean($operator, $actual),
            WorkflowVariableType::FILE => $this->file($operator, $actual),
        };
    }

    /**
     * A file field answers one question: is there a file or not. `filled`/`empty` take no
     * value (an absent field already short-circuits to empty via passesOnMissingPath).
     *
     * The payload carries a snapshot LIST, but tolerate the shapes a legacy or hand-written
     * payload can hold — a bare id string, a single snapshot — so a condition degrades to a
     * sane answer instead of misreading a non-empty value as empty.
     */
    private function file(WorkflowConditionOperator $op, mixed $actual): bool
    {
        $hasFile = match (true) {
            $actual === null => false,
            is_string($actual) => $actual !== '',
            is_array($actual) => $actual !== [],
            default => false,
        };

        return match ($op) {
            WorkflowConditionOperator::FILLED => $hasFile,
            WorkflowConditionOperator::EMPTY => !$hasFile,
            default => false,
        };
    }

    private function text(WorkflowConditionOperator $op, mixed $actual, mixed $value): bool
    {
        return match ($op) {
            WorkflowConditionOperator::EQUALS => $this->stringEquals($actual, $value),
            WorkflowConditionOperator::NOT_EQUALS => !$this->stringEquals($actual, $value),
            WorkflowConditionOperator::CONTAINS => is_scalar($actual) && is_scalar($value)
                && $value !== '' && str_contains((string) $actual, (string) $value),
            default => false,
        };
    }

    private function number(WorkflowConditionOperator $op, mixed $actual, mixed $value): bool
    {
        if (!is_numeric($actual) || !is_numeric($value)) {
            return false;
        }

        $a = (float) $actual;
        $b = (float) $value;

        return match ($op) {
            WorkflowConditionOperator::EQ => $a === $b,
            WorkflowConditionOperator::NEQ => $a !== $b,
            WorkflowConditionOperator::GT => $a > $b,
            WorkflowConditionOperator::GTE => $a >= $b,
            WorkflowConditionOperator::LT => $a < $b,
            WorkflowConditionOperator::LTE => $a <= $b,
            default => false,
        };
    }

    private function date(WorkflowConditionOperator $op, mixed $actual, mixed $value): bool
    {
        $left = $this->parseDate($actual);

        if ($left === null) {
            return false;
        }

        if ($op === WorkflowConditionOperator::BETWEEN) {
            if (!is_array($value) || count($value) !== 2) {
                return false;
            }

            $from = $this->parseDate($value[0] ?? null);
            $to = $this->parseDate($value[1] ?? null);

            return $from !== null && $to !== null
                && $left->greaterThanOrEqualTo($from) && $left->lessThanOrEqualTo($to);
        }

        $right = $this->parseDate($value);

        if ($right === null) {
            return false;
        }

        return match ($op) {
            WorkflowConditionOperator::BEFORE => $left->lessThan($right),
            WorkflowConditionOperator::AFTER => $left->greaterThan($right),
            WorkflowConditionOperator::ON => $left->isSameDay($right),
            default => false,
        };
    }

    private function enum(WorkflowConditionOperator $op, mixed $actual, mixed $value): bool
    {
        return match ($op) {
            WorkflowConditionOperator::IS => $this->stringEquals($actual, $value),
            WorkflowConditionOperator::IS_NOT => !$this->stringEquals($actual, $value),
            WorkflowConditionOperator::IN => is_array($value) && $this->memberOf($actual, $value),
            default => false,
        };
    }

    private function multi(WorkflowConditionOperator $op, mixed $actual, mixed $value): bool
    {
        $set = is_array($actual) ? $actual : [$actual];

        return match ($op) {
            WorkflowConditionOperator::INCLUDES => $this->memberOf($value, $set),
            WorkflowConditionOperator::EXCLUDES => !$this->memberOf($value, $set),
            default => false,
        };
    }

    private function boolean(WorkflowConditionOperator $op, mixed $actual): bool
    {
        $truthy = filter_var($actual, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $truthy ??= (bool) $actual;

        return match ($op) {
            WorkflowConditionOperator::IS_TRUE => $truthy === true,
            WorkflowConditionOperator::IS_FALSE => $truthy === false,
            default => false,
        };
    }

    /** Scalar equality after string-normalizing both sides (a uuid/int matches its string form). */
    private function stringEquals(mixed $actual, mixed $expected): bool
    {
        if (is_scalar($actual) && is_scalar($expected)) {
            return (string) $actual === (string) $expected;
        }

        return $actual === $expected;
    }

    /** Whether $needle is a string-normalized member of $haystack. */
    private function memberOf(mixed $needle, array $haystack): bool
    {
        foreach ($haystack as $candidate) {
            if ($this->stringEquals($needle, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** Parse a date value, returning null on any invalid/unparseable input — NEVER throwing. */
    private function parseDate(mixed $value): ?CarbonInterface
    {
        if (!is_string($value) && !is_numeric($value) && !$value instanceof CarbonInterface) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
