<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\DTOs\OperationResult;
use App\Modules\Workflows\Enums\ConditionTreeLimits;
use App\Modules\Workflows\Enums\WorkflowOperation;
use App\Modules\Workflows\Enums\WorkflowVariableType;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The ONE runtime engine for a variable-operation PIPELINE — the single source of truth for the 68
 * operations (mirroring standardOperations.ts). It was extracted from WorkflowConditionEngine so
 * every consumer shares identical semantics and can never drift:
 *
 *   - WorkflowConditionEngine feeds a condition's pipeline and reads the boolean terminal.
 *   - WorkflowVariableResolver feeds a directive / if-block / value-or-variable pipeline and reads
 *     the transformed value (typed for structured fields, stringified for text fields).
 *
 * INPUT SHAPE: a pipeline is a list of steps `{op, args}`. For portability with the next editor's
 * serialization the op id is read from `op` OR `operationId` (the editor's VariablePipelineStep
 * carries `operationId` + `stepId` + `outputType`; the extra keys are ignored — the enum owns the
 * real input/output types, so a degraded/advisory `outputType` on the wire never overrides them).
 *
 * FAIL-CLOSED DOCTRINE (never throws): an unknown op, an op whose input type ≠ the running type, an
 * unparseable number/date, a divide-by-zero, an unmapped enum option, a non-array step, or a
 * pipeline over the hard cap → an OperationResult::failure(). A base value that cannot be normalized
 * to its declared type is likewise a failure.
 *
 * OPERATION SEMANTICS (the 68 ops):
 *   - text_to_number: non-numeric fails (never coerces to 0). text_substring: `start` 1-based,
 *     `length` 0 = to the end. contains/starts_with/ends_with with an empty needle are false.
 *   - num_divide by zero fails. num ops use float compare (=== on floats).
 *   - bool_to_number / bool_to_text yield the when_true/when_false arg.
 *   - enum_to_* map the option value to the entered target; an unmapped option (or an unparseable
 *     mapped number/date) fails. multi_to_text joins the SELECTED option VALUES with ", ".
 *   - CHOICE terminals (map a value into a destination field's option set, e.g. task priority):
 *     enum_to_choice is enum_to_* with a string (option) target — an unmapped source option fails
 *     closed. match_to_choice maps a TEXT value by the first {when, then} rule whose `when` equals
 *     it, else a REQUIRED `fallback` option; a missing/blank fallback fails closed (the op is total).
 *   - dates are STRICT ISO Y-m-d wall-clock (anything else fails); date_weekday is 0=Sunday..6.
 *     date_is_past/future/weekend compare against "today" in config('app.timezone').
 */
class WorkflowOperationExecutor
{
    /** An operation/argument failure marker — collapses to an OperationResult::failure(), never an exception. */
    private const FAIL = "\0__workflow_operation_failed__\0";

    /**
     * Run $pipeline over $baseValue (declared as $baseType), returning the transformed value + its
     * terminal type, or a fail-closed failure. $context is reserved for the evaluation environment
     * (e.g. a pinned "now"); the relative date predicates currently read Carbon's clock in the app
     * timezone directly, so it is accepted for forward-compat and passed through untouched.
     *
     * @param  array<int, mixed>  $pipeline  list of {op|operationId, args} steps
     * @param  array<string, mixed>  $context
     */
    public function execute(mixed $baseValue, WorkflowVariableType $baseType, array $pipeline, array $context = []): OperationResult
    {
        if (count($pipeline) > ConditionTreeLimits::MAX_PIPELINE_STEPS) {
            return OperationResult::failure();
        }

        $value = $this->normalizeInput($baseValue, $baseType);

        if ($value === self::FAIL) {
            return OperationResult::failure();
        }

        $currentType = $baseType;

        foreach ($pipeline as $step) {
            if (!is_array($step)) {
                return OperationResult::failure();
            }

            $op = WorkflowOperation::tryFrom((string) ($step['op'] ?? $step['operationId'] ?? ''));

            if ($op === null || $op->inputType() !== $currentType) {
                return OperationResult::failure();
            }

            $args = is_array($step['args'] ?? null) ? $step['args'] : [];
            $value = $this->apply($op, $value, $args);

            if ($value === self::FAIL) {
                return OperationResult::failure();
            }

            $currentType = $op->outputType();
        }

        return OperationResult::success($value, $currentType);
    }

    // ---- input normalization --------------------------------------------------

    /**
     * Coerce a raw value into the canonical PHP shape for its declared type
     * (text/enum → string, number → float, boolean → bool, date → CarbonImmutable, multi → string[]),
     * or FAIL when it cannot be represented as that type.
     */
    private function normalizeInput(mixed $value, WorkflowVariableType $type): mixed
    {
        return match ($type) {
            WorkflowVariableType::TEXT, WorkflowVariableType::ENUM => is_scalar($value) ? (string) $value : self::FAIL,
            WorkflowVariableType::NUMBER => is_numeric($value) ? (float) $value : self::FAIL,
            WorkflowVariableType::BOOLEAN => $this->toBool($value),
            WorkflowVariableType::DATE => $this->parseDate($value) ?? self::FAIL,
            WorkflowVariableType::MULTI => $this->toStringList($value),
            WorkflowVariableType::FILE => $this->toFileList($value),
        };
    }

    /**
     * A file value as a canonical list of snapshots ({id,name,mime_type,size}). Tolerates the
     * shapes a legacy or hand-written payload can hold — a bare id, a single snapshot — so a
     * pipeline degrades to a sane answer rather than failing the whole condition.
     *
     * @return array<int, array<string, mixed>>
     */
    private function toFileList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $isSnapshot = fn (mixed $item): bool => is_array($item) && array_key_exists('id', $item);

        $items = is_array($value) && !$isSnapshot($value) ? $value : [$value];

        return array_values(array_filter(array_map(
            fn (mixed $item) => match (true) {
                $isSnapshot($item) => $item,
                // A bare id still counts as "a file is here", it just has no name to read.
                is_string($item) && $item !== '' => ['id' => $item],
                default => null,
            },
            $items,
        )));
    }

    /** Lenient truthiness (mirrors the legacy evaluator so 'true'/'1'/1 all read true). */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    /**
     * A multi value as a list of string option values. A non-array present value is wrapped as a
     * single-element set; a null/object becomes the empty set.
     *
     * @return array<int, string>
     */
    private function toStringList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value));
        }

        return is_scalar($value) ? [(string) $value] : [];
    }

    // ---- operation dispatch ---------------------------------------------------

    /**
     * Apply one operation to the current canonical value, returning the next canonical value or FAIL.
     *
     * @param  array<string, mixed>  $args
     */
    private function apply(WorkflowOperation $op, mixed $value, array $args): mixed
    {
        return match ($op->inputType()) {
            WorkflowVariableType::TEXT => $this->applyText($op, (string) $value, $args),
            WorkflowVariableType::NUMBER => $this->applyNumber($op, (float) $value, $args),
            WorkflowVariableType::BOOLEAN => $this->applyBoolean($op, (bool) $value, $args),
            WorkflowVariableType::DATE => $this->applyDate($op, $value, $args),
            WorkflowVariableType::ENUM => $this->applyEnum($op, (string) $value, $args),
            WorkflowVariableType::MULTI => $this->applyMulti($op, is_array($value) ? $value : [], $args),
            WorkflowVariableType::FILE => $this->applyFile($op, is_array($value) ? $value : [], $args),
        };
    }

    /**
     * File ops. Two boolean terminals plus two converters that hand the value to the existing
     * text/number vocabulary — so "is it a PDF" is file_name -> text_ends_with, and "more than
     * one" is file_count -> num_gt, with no file-specific comparison ops to maintain.
     *
     * @param  array<int, array<string, mixed>>  $files  canonical snapshot list
     * @param  array<string, mixed>  $args
     */
    private function applyFile(WorkflowOperation $op, array $files, array $args): mixed
    {
        return match ($op) {
            WorkflowOperation::FILE_IS_EMPTY => $files === [],
            WorkflowOperation::FILE_IS_NOT_EMPTY => $files !== [],
            WorkflowOperation::FILE_COUNT => (float) count($files),
            // The names, comma-joined — mirrors multi_to_text's shape for a set-valued source.
            WorkflowOperation::FILE_NAME => implode(', ', array_values(array_filter(array_map(
                fn (array $file) => (string) ($file['name'] ?? ''),
                $files,
            ), fn (string $name) => $name !== ''))),
            default => self::FAIL,
        };
    }

    /** @param array<string, mixed> $args */
    private function applyText(WorkflowOperation $op, string $v, array $args): mixed
    {
        return match ($op) {
            WorkflowOperation::TEXT_UPPERCASE => mb_strtoupper($v),
            WorkflowOperation::TEXT_LOWERCASE => mb_strtolower($v),
            WorkflowOperation::TEXT_TRIM => trim($v),
            WorkflowOperation::TEXT_LENGTH => (float) mb_strlen($v),
            WorkflowOperation::TEXT_TO_NUMBER => is_numeric($v) ? (float) $v : self::FAIL,
            WorkflowOperation::TEXT_IS_EMPTY => $v === '',
            WorkflowOperation::TEXT_IS_NOT_EMPTY => $v !== '',
            WorkflowOperation::TEXT_SUBSTRING => $this->textSubstring($v, $args),
            WorkflowOperation::TEXT_REPLACE => $this->textReplace($v, $args),
            WorkflowOperation::TEXT_APPEND => $this->withStringArg($args, 'value', fn (string $s) => $v . $s),
            WorkflowOperation::TEXT_PREPEND => $this->withStringArg($args, 'value', fn (string $s) => $s . $v),
            WorkflowOperation::TEXT_EQUALS => $this->withStringArg($args, 'value', fn (string $s) => $v === $s),
            WorkflowOperation::TEXT_NOT_EQUALS => $this->withStringArg($args, 'value', fn (string $s) => $v !== $s),
            WorkflowOperation::TEXT_CONTAINS => $this->withStringArg($args, 'value', fn (string $s) => $s !== '' && str_contains($v, $s)),
            WorkflowOperation::TEXT_STARTS_WITH => $this->withStringArg($args, 'value', fn (string $s) => $s !== '' && str_starts_with($v, $s)),
            WorkflowOperation::TEXT_ENDS_WITH => $this->withStringArg($args, 'value', fn (string $s) => $s !== '' && str_ends_with($v, $s)),
            WorkflowOperation::MATCH_TO_CHOICE => $this->matchToChoice($v, $args),
            default => self::FAIL,
        };
    }

    /**
     * Map a text value to a target CHOICE by the FIRST {when, then} rule whose `when` equals it, else
     * the REQUIRED `fallback` option. A missing/blank fallback fails closed (the op must be total over
     * its input); a non-array `rules` fails; malformed rule entries are skipped. Output is a choice
     * string (an option value of the destination field).
     *
     * @param  array<string, mixed>  $args
     */
    private function matchToChoice(string $v, array $args): mixed
    {
        $fallback = $args['fallback'] ?? null;

        if (!is_string($fallback) || $fallback === '') {
            return self::FAIL;
        }

        $rules = $args['rules'] ?? [];

        if (!is_array($rules)) {
            return self::FAIL;
        }

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $when = $rule['when'] ?? null;
            $then = $rule['then'] ?? null;

            if (is_scalar($when) && is_scalar($then) && (string) $when === $v) {
                return (string) $then; // first match wins
            }
        }

        return $fallback;
    }

    /** @param array<string, mixed> $args */
    private function textSubstring(string $v, array $args): mixed
    {
        $start = $args['start'] ?? null;
        $length = $args['length'] ?? null;

        if (!is_numeric($start) || !is_numeric($length)) {
            return self::FAIL;
        }

        $offset = max(0, (int) $start - 1); // `start` is 1-based
        $len = (int) $length;

        return $len > 0 ? mb_substr($v, $offset, $len) : mb_substr($v, $offset); // 0 = to the end
    }

    /** @param array<string, mixed> $args */
    private function textReplace(string $v, array $args): mixed
    {
        $search = $args['search'] ?? null;
        $replace = $args['replace'] ?? null;

        if (!is_scalar($search) || !is_scalar($replace)) {
            return self::FAIL;
        }

        return str_replace((string) $search, (string) $replace, $v);
    }

    /** @param array<string, mixed> $args */
    private function applyNumber(WorkflowOperation $op, float $v, array $args): mixed
    {
        return match ($op) {
            WorkflowOperation::NUM_ABS => abs($v),
            WorkflowOperation::NUM_FLOOR => floor($v),
            WorkflowOperation::NUM_CEIL => ceil($v),
            WorkflowOperation::NUM_TO_TEXT => $this->floatToText($v),
            WorkflowOperation::NUM_ROUND => $this->withNumberArg($args, 'precision', fn (float $p) => round($v, (int) $p)),
            WorkflowOperation::NUM_ADD => $this->withNumberArg($args, 'value', fn (float $n) => $v + $n),
            WorkflowOperation::NUM_SUBTRACT => $this->withNumberArg($args, 'value', fn (float $n) => $v - $n),
            WorkflowOperation::NUM_MULTIPLY => $this->withNumberArg($args, 'value', fn (float $n) => $v * $n),
            WorkflowOperation::NUM_DIVIDE => $this->withNumberArg($args, 'value', fn (float $n) => $n === 0.0 ? self::FAIL : $v / $n),
            WorkflowOperation::NUM_EQ => $this->withNumberArg($args, 'value', fn (float $n) => $v === $n),
            WorkflowOperation::NUM_NEQ => $this->withNumberArg($args, 'value', fn (float $n) => $v !== $n),
            WorkflowOperation::NUM_GT => $this->withNumberArg($args, 'value', fn (float $n) => $v > $n),
            WorkflowOperation::NUM_GTE => $this->withNumberArg($args, 'value', fn (float $n) => $v >= $n),
            WorkflowOperation::NUM_LT => $this->withNumberArg($args, 'value', fn (float $n) => $v < $n),
            WorkflowOperation::NUM_LTE => $this->withNumberArg($args, 'value', fn (float $n) => $v <= $n),
            WorkflowOperation::NUM_BETWEEN => $this->withNumberArg(
                $args,
                'from',
                fn (float $from) => $this->withNumberArg($args, 'to', fn (float $to) => $v >= $from && $v <= $to),
            ),
            default => self::FAIL,
        };
    }

    /** Render a float as text without a trailing `.0` for whole numbers (e.g. 42.0 → "42"). */
    private function floatToText(float $v): string
    {
        return $v == (int) $v && is_finite($v) ? (string) (int) $v : (string) $v;
    }

    /** @param array<string, mixed> $args */
    private function applyBoolean(WorkflowOperation $op, bool $v, array $args): mixed
    {
        return match ($op) {
            WorkflowOperation::BOOL_NOT => !$v,
            WorkflowOperation::BOOL_TO_NUMBER => $this->withNumberArg($args, $v ? 'when_true' : 'when_false', fn (float $n) => $n),
            WorkflowOperation::BOOL_TO_TEXT => $this->withStringArg($args, $v ? 'when_true' : 'when_false', fn (string $s) => $s),
            default => self::FAIL,
        };
    }

    /** @param array<string, mixed> $args */
    private function applyDate(WorkflowOperation $op, mixed $v, array $args): mixed
    {
        if (!$v instanceof CarbonImmutable) {
            return self::FAIL;
        }

        return match ($op) {
            WorkflowOperation::DATE_ADD_DAYS => $this->withNumberArg($args, 'value', fn (float $n) => $v->addDays((int) $n)),
            WorkflowOperation::DATE_SUBTRACT_DAYS => $this->withNumberArg($args, 'value', fn (float $n) => $v->subDays((int) $n)),
            WorkflowOperation::DATE_ADD_MONTHS => $this->withNumberArg($args, 'value', fn (float $n) => $v->addMonths((int) $n)),
            WorkflowOperation::DATE_ADD_YEARS => $this->withNumberArg($args, 'value', fn (float $n) => $v->addYears((int) $n)),
            WorkflowOperation::DATE_START_OF_MONTH => $v->startOfMonth(),
            WorkflowOperation::DATE_END_OF_MONTH => $v->endOfMonth()->startOfDay(),
            WorkflowOperation::DATE_DAY => (float) $v->day,
            WorkflowOperation::DATE_MONTH => (float) $v->month,
            WorkflowOperation::DATE_YEAR => (float) $v->year,
            WorkflowOperation::DATE_WEEKDAY => (float) $v->dayOfWeek, // 0=Sunday..6=Saturday
            WorkflowOperation::DATE_TO_TEXT => $v->format('Y-m-d'),
            WorkflowOperation::DATE_BEFORE => $this->withDateArg($args, 'value', fn (CarbonImmutable $d) => $v->lessThan($d)),
            WorkflowOperation::DATE_AFTER => $this->withDateArg($args, 'value', fn (CarbonImmutable $d) => $v->greaterThan($d)),
            WorkflowOperation::DATE_ON => $this->withDateArg($args, 'value', fn (CarbonImmutable $d) => $v->isSameDay($d)),
            WorkflowOperation::DATE_BETWEEN => $this->withDateArg(
                $args,
                'from',
                fn (CarbonImmutable $from) => $this->withDateArg(
                    $args,
                    'to',
                    fn (CarbonImmutable $to) => $v->greaterThanOrEqualTo($from) && $v->lessThanOrEqualTo($to),
                ),
            ),
            WorkflowOperation::DATE_IS_WEEKEND => $v->isWeekend(),
            WorkflowOperation::DATE_IS_PAST => $v->lessThan($this->today()),
            WorkflowOperation::DATE_IS_FUTURE => $v->greaterThan($this->today()),
            default => self::FAIL,
        };
    }

    /** @param array<string, mixed> $args */
    private function applyEnum(WorkflowOperation $op, string $v, array $args): mixed
    {
        return match ($op) {
            WorkflowOperation::ENUM_IS => $this->withStringArg($args, 'value', fn (string $s) => $v === $s),
            WorkflowOperation::ENUM_IS_NOT => $this->withStringArg($args, 'value', fn (string $s) => $v !== $s),
            WorkflowOperation::ENUM_IN => $this->memberOfArg($v, $args),
            WorkflowOperation::ENUM_TO_TEXT => $this->enumMap($v, $args, WorkflowVariableType::TEXT),
            WorkflowOperation::ENUM_TO_NUMBER => $this->enumMap($v, $args, WorkflowVariableType::NUMBER),
            WorkflowOperation::ENUM_TO_DATE => $this->enumMap($v, $args, WorkflowVariableType::DATE),
            // The choice output is an option string, so it reuses enumMap's default (stringify) branch;
            // an unmapped source option fails closed exactly like the other enum_to_* ops.
            WorkflowOperation::ENUM_TO_CHOICE => $this->enumMap($v, $args, WorkflowVariableType::ENUM),
            default => self::FAIL,
        };
    }

    /** Whether the enum value is one of the `values` option list. @param array<string, mixed> $args */
    private function memberOfArg(string $v, array $args): mixed
    {
        $values = $args['values'] ?? null;

        if (!is_array($values)) {
            return self::FAIL;
        }

        foreach ($values as $candidate) {
            if (is_scalar($candidate) && (string) $candidate === $v) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map an enum option value to its per-option target (text/number/date). An unmapped option or an
     * unparseable target fails.
     *
     * @param  array<string, mixed>  $args
     */
    private function enumMap(string $v, array $args, WorkflowVariableType $target): mixed
    {
        $mapping = $args['mapping'] ?? null;

        if (!is_array($mapping) || !array_key_exists($v, $mapping)) {
            return self::FAIL;
        }

        $mapped = $mapping[$v];

        return match ($target) {
            WorkflowVariableType::NUMBER => is_numeric($mapped) ? (float) $mapped : self::FAIL,
            WorkflowVariableType::DATE => $this->parseDate($mapped) ?? self::FAIL,
            default => is_scalar($mapped) ? (string) $mapped : self::FAIL,
        };
    }

    /**
     * @param  array<int, string>  $v
     * @param  array<string, mixed>  $args
     */
    private function applyMulti(WorkflowOperation $op, array $v, array $args): mixed
    {
        return match ($op) {
            WorkflowOperation::MULTI_INCLUDES => $this->withStringArg($args, 'value', fn (string $s) => in_array($s, $v, true)),
            WorkflowOperation::MULTI_EXCLUDES => $this->withStringArg($args, 'value', fn (string $s) => !in_array($s, $v, true)),
            WorkflowOperation::MULTI_INCLUDES_ANY => $this->multiOverlap($v, $args, requireAll: false),
            WorkflowOperation::MULTI_INCLUDES_ALL => $this->multiOverlap($v, $args, requireAll: true),
            WorkflowOperation::MULTI_COUNT => (float) count($v),
            WorkflowOperation::MULTI_IS_EMPTY => count($v) === 0,
            WorkflowOperation::MULTI_TO_TEXT => implode(', ', array_map(fn ($x) => (string) $x, $v)),
            default => self::FAIL,
        };
    }

    /**
     * Whether the selection overlaps the `values` arg — ANY match (requireAll:false) or EVERY value
     * present (requireAll:true). A non-array/empty `values` fails.
     *
     * @param  array<int, string>  $v
     * @param  array<string, mixed>  $args
     */
    private function multiOverlap(array $v, array $args, bool $requireAll): mixed
    {
        $values = $args['values'] ?? null;

        if (!is_array($values) || $values === []) {
            return self::FAIL;
        }

        foreach ($values as $candidate) {
            $present = is_scalar($candidate) && in_array((string) $candidate, $v, true);

            if ($requireAll && !$present) {
                return false;
            }

            if (!$requireAll && $present) {
                return true;
            }
        }

        return $requireAll;
    }

    // ---- arg readers ----------------------------------------------------------

    /**
     * Run $fn with a required scalar arg cast to string, or FAIL when the arg is absent/non-scalar.
     *
     * @param  array<string, mixed>  $args
     * @param  callable(string): mixed  $fn
     */
    private function withStringArg(array $args, string $key, callable $fn): mixed
    {
        $value = $args[$key] ?? null;

        return is_scalar($value) ? $fn((string) $value) : self::FAIL;
    }

    /**
     * Run $fn with a required numeric arg cast to float, or FAIL when the arg is absent/non-numeric.
     *
     * @param  array<string, mixed>  $args
     * @param  callable(float): mixed  $fn
     */
    private function withNumberArg(array $args, string $key, callable $fn): mixed
    {
        $value = $args[$key] ?? null;

        return is_numeric($value) ? $fn((float) $value) : self::FAIL;
    }

    /**
     * Run $fn with a required strict-Y-m-d arg parsed to a date, or FAIL when it is absent/unparseable.
     *
     * @param  array<string, mixed>  $args
     * @param  callable(CarbonImmutable): mixed  $fn
     */
    private function withDateArg(array $args, string $key, callable $fn): mixed
    {
        $date = $this->parseDate($args[$key] ?? null);

        return $date !== null ? $fn($date) : self::FAIL;
    }

    /** "Today" (start of day) in the evaluation timezone, for the relative date predicates. */
    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfDay();
    }

    /**
     * Parse a STRICT ISO Y-m-d wall-clock date in the evaluation timezone — returns null for any
     * non-string, wrong format, zero-padding drift, or calendar roll-over (e.g. 2026-02-30). Never throws.
     */
    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        // Reject anything that did not round-trip exactly (roll-over / missing zero-padding).
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
