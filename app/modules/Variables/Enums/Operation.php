<?php

namespace App\Modules\Variables\Enums;

use App\Modules\Variables\Contracts\OperationDefinition;
use App\Modules\Variables\DTOs\OperationArg;

/**
 * The canonical variable-operations vocabulary — the backend 1:1 mirror of the FE
 * `standardOperationsCatalog()` (resources/js/next/ui/editor/extensions/standardOperations.ts).
 * The 79 ids ARE the stable wire contract of a pipeline: an id may only be added, never renamed.
 * Each op takes ONE input type and yields ONE output type, so a CONDITION pipeline is a type-flow
 * that MUST terminate in boolean (any variable can become a condition). A VALUE pipeline targeting a
 * choice field (e.g. a task priority) instead terminates in one of the two choice-producing ops
 * (enum_to_choice / match_to_choice, producesChoice() = true), which map the value into the
 * destination field's own option set (the target options are injected per-field, not part of the op).
 *
 * This enum owns three things the validator and engine both read (so they can never drift):
 *   - inputType()      the VariableType the op consumes.
 *   - outputType()     the VariableType the op produces.
 *   - argDescriptors() the ordered argument descriptors (id + control type + sourceMap mapType).
 *
 * RUNTIME SEMANTICS are documented on WorkflowConditionEngine (the fail-closed conversions,
 * bool_to_* when_true/when_false branches, enum_to_* per-option mapping, divide-by-zero, 1-based
 * substring, strict Y-m-d dates, weekday 0=Sunday, multi_to_text joining option VALUES). This enum
 * is metadata only; it evaluates nothing.
 */
enum Operation: string implements OperationDefinition
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

    // ── FILE (input: file) ────────────────────────────────────────────────────
    // A file variable carries a snapshot list. Two boolean terminals make a file field usable
    // in the condition tree at all (every condition pipeline must terminate in boolean), and
    // the two CONVERTERS let the existing vocabulary answer everything else by chaining:
    // "is it a PDF" is file_name -> text_ends_with '.pdf' (sharper than a coarse type check),
    // "more than one file" is file_count -> num_gt 1.
    case FILE_IS_EMPTY = 'file_is_empty';
    case FILE_IS_NOT_EMPTY = 'file_is_not_empty';
    case FILE_COUNT = 'file_count';
    case FILE_NAME = 'file_name';

    // ── GENERIC / NULL-HANDLING (append-only, phase-1b) ───────────────────────
    // Presence helpers + a safe date formatter. COALESCE/IS_PRESENT/IS_NULL/ASSERT_PRESENT accept ANY
    // running value (they inspect PRESENCE, not shape): the executor special-cases them BEFORE the
    // per-step type gate (isPresenceOp) — COALESCE/ASSERT_PRESENT preserve the running type, IS_PRESENT/
    // IS_NULL yield boolean, ASSERT_PRESENT is the one opt-in HARD failure. Their declared input/output
    // below are the NOMINAL text/boolean the write-validator + catalog advertise; the real runtime
    // type-flow is the executor's. DATE_FORMAT is an ordinary date→text op (safe-token pattern only).
    case COALESCE = 'coalesce';
    case IS_PRESENT = 'is_present';
    case IS_NULL = 'is_null';
    case ASSERT_PRESENT = 'assert_present';
    case DATE_FORMAT = 'date_format';

    // ── ARRAY TRANSFORMS (append-only, array-ops wave 1) ──────────────────────
    // These are the FIRST ops gated on the running value being an ARRAY rather than on a flat input
    // type (isArrayOp) — they accept ANY array<T> regardless of its element base, which flat-type
    // gating cannot express. Their real terminal type is a function of the INPUT descriptor's element
    // (outputDescriptor), so the static input/output below are ADVISORY (the catalog/first-op-input
    // fallback the FE and resolver read): input `multi` (the only array on the wire this wave), output
    // NUMBER for count and TEXT for at (a sensible flat element fallback — the descriptor carries the
    // true element type). Wave 1 runs them over MULTI (array<enum>/array<scalar>) values only.
    case ARRAY_COUNT = 'array_count'; // array<T> -> number (length)
    case ARRAY_AT = 'array_at';       // array<T> -> T (nullable): 1-based signed, clamped to nearest end

    // ── ARRAY TRANSFORMS (append-only, array-ops wave 2) ──────────────────────
    // The four HIGHER-ORDER array ops — each carries a PER-ELEMENT PIPELINE (rooted at the array's
    // element descriptor, with synthetic `element`/`index` scope variables) whose TERMINAL type is
    // enforced BY CONSTRUCTION at write time (isCollectionOp marks them so the walker/executor route
    // them through the per-element re-entry rather than the flat apply()):
    //   - map    array<T> -> array<U>  (U = the element pipeline's terminal base; no array<array>).
    //   - filter array<T> -> array<T>  (keep iff the element pipeline terminates boolean true).
    //   - sort   array<T> -> array<T>  (ascending by the element pipeline's numeric terminal, stable).
    //   - reduce array<T> -> U         (fold a typed SEED across the elements via a reducer pipeline that
    //                                   terminates in the seed base U).
    case ARRAY_MAP = 'array_map';
    case ARRAY_FILTER = 'array_filter';
    case ARRAY_SORT = 'array_sort';
    case ARRAY_REDUCE = 'array_reduce';

    /** The stable wire id of this op — its backing value (a custom function's id is `fn:<uuid>`). */
    public function id(): string
    {
        return $this->value;
    }

    /** A built-in op is never a custom function (only CustomFunctionOperation is). */
    public function isCustom(): bool
    {
        return false;
    }

    /** The value type this op CONSUMES (its single input). */
    public function inputType(): VariableType
    {
        return match ($this) {
            self::TEXT_UPPERCASE, self::TEXT_LOWERCASE, self::TEXT_TRIM, self::TEXT_SUBSTRING,
            self::TEXT_REPLACE, self::TEXT_APPEND, self::TEXT_PREPEND, self::TEXT_LENGTH,
            self::TEXT_TO_NUMBER, self::TEXT_EQUALS, self::TEXT_NOT_EQUALS, self::TEXT_CONTAINS,
            self::TEXT_STARTS_WITH, self::TEXT_ENDS_WITH, self::TEXT_IS_EMPTY, self::TEXT_IS_NOT_EMPTY,
            self::MATCH_TO_CHOICE => VariableType::TEXT,

            self::NUM_ADD, self::NUM_SUBTRACT, self::NUM_MULTIPLY, self::NUM_DIVIDE, self::NUM_ABS,
            self::NUM_ROUND, self::NUM_FLOOR, self::NUM_CEIL, self::NUM_TO_TEXT, self::NUM_EQ,
            self::NUM_NEQ, self::NUM_GT, self::NUM_GTE, self::NUM_LT, self::NUM_LTE, self::NUM_BETWEEN => VariableType::NUMBER,

            self::BOOL_NOT, self::BOOL_TO_NUMBER, self::BOOL_TO_TEXT => VariableType::BOOLEAN,

            self::DATE_ADD_DAYS, self::DATE_SUBTRACT_DAYS, self::DATE_ADD_MONTHS, self::DATE_ADD_YEARS,
            self::DATE_START_OF_MONTH, self::DATE_END_OF_MONTH, self::DATE_DAY, self::DATE_MONTH,
            self::DATE_YEAR, self::DATE_WEEKDAY, self::DATE_TO_TEXT, self::DATE_BEFORE, self::DATE_AFTER,
            self::DATE_ON, self::DATE_BETWEEN, self::DATE_IS_WEEKEND, self::DATE_IS_PAST, self::DATE_IS_FUTURE => VariableType::DATE,

            self::ENUM_IS, self::ENUM_IS_NOT, self::ENUM_IN, self::ENUM_TO_TEXT, self::ENUM_TO_NUMBER,
            self::ENUM_TO_DATE, self::ENUM_TO_CHOICE => VariableType::ENUM,

            self::MULTI_INCLUDES, self::MULTI_EXCLUDES, self::MULTI_INCLUDES_ANY, self::MULTI_INCLUDES_ALL,
            self::MULTI_COUNT, self::MULTI_IS_EMPTY, self::MULTI_TO_TEXT => VariableType::MULTI,

            self::FILE_IS_EMPTY, self::FILE_IS_NOT_EMPTY, self::FILE_COUNT,
            self::FILE_NAME => VariableType::FILE,

            // Presence helpers accept ANY value; TEXT is the NOMINAL input the validator/catalog show
            // (the executor bypasses the type gate for them at runtime). date_format consumes a date.
            self::COALESCE, self::IS_PRESENT, self::IS_NULL, self::ASSERT_PRESENT => VariableType::TEXT,
            self::DATE_FORMAT => VariableType::DATE,

            // ADVISORY only — the REAL gate is isArrayOp() ($currentDescriptor['array'] === true), which
            // accepts ANY array. MULTI is the one array on the wire this wave, so it doubles as the
            // executor's dispatch key (normalizeInput(MULTI) → string[]).
            self::ARRAY_COUNT, self::ARRAY_AT => VariableType::MULTI,

            // ADVISORY, like count/at — the REAL gate is isArrayOp() (an array of ANY element base). MULTI
            // is the one array on the wire this wave, doubling as the executor's dispatch key.
            self::ARRAY_MAP, self::ARRAY_FILTER, self::ARRAY_SORT, self::ARRAY_REDUCE => VariableType::MULTI,
        };
    }

    /** The value type this op PRODUCES (its single output). */
    public function outputType(): VariableType
    {
        return match ($this) {
            // text -> text
            self::TEXT_UPPERCASE, self::TEXT_LOWERCASE, self::TEXT_TRIM, self::TEXT_SUBSTRING,
            self::TEXT_REPLACE, self::TEXT_APPEND, self::TEXT_PREPEND,
            // number -> text / boolean -> text / date -> text / multi -> text / enum -> text / file -> text
            self::NUM_TO_TEXT, self::BOOL_TO_TEXT, self::DATE_TO_TEXT, self::MULTI_TO_TEXT, self::ENUM_TO_TEXT,
            self::FILE_NAME,
            // array<T> -> T; the STATIC fallback is the flat element base (text). The DESCRIPTOR-tracking
            // walker computes the precise element type (e.g. enum for a MULTI) via outputDescriptor.
            self::ARRAY_AT => VariableType::TEXT,
            // reduce -> U (a base): the STATIC fallback is TEXT; outputDescriptor returns the true seed base.
            self::ARRAY_REDUCE => VariableType::TEXT,

            // text -> number / number -> number / bool -> number / date -> number / multi -> number / enum -> number
            self::TEXT_LENGTH, self::TEXT_TO_NUMBER,
            self::NUM_ADD, self::NUM_SUBTRACT, self::NUM_MULTIPLY, self::NUM_DIVIDE, self::NUM_ABS,
            self::NUM_ROUND, self::NUM_FLOOR, self::NUM_CEIL,
            self::BOOL_TO_NUMBER,
            self::DATE_DAY, self::DATE_MONTH, self::DATE_YEAR, self::DATE_WEEKDAY,
            self::MULTI_COUNT, self::ENUM_TO_NUMBER, self::FILE_COUNT,
            self::ARRAY_COUNT => VariableType::NUMBER,

            // array<T> -> array<U|T>: the STATIC fallback is MULTI (the flat array wire). The descriptor-
            // tracking walker computes the precise element typing via outputDescriptor (map's terminal
            // element base, filter/sort's unchanged input). reduce collapses to a base — advisory TEXT.
            self::ARRAY_MAP, self::ARRAY_FILTER, self::ARRAY_SORT => VariableType::MULTI,

            // date -> date / enum -> date
            self::DATE_ADD_DAYS, self::DATE_SUBTRACT_DAYS, self::DATE_ADD_MONTHS, self::DATE_ADD_YEARS,
            self::DATE_START_OF_MONTH, self::DATE_END_OF_MONTH, self::ENUM_TO_DATE => VariableType::DATE,

            // enum -> enum / text -> enum (the CHOICE terminals: map a value into the target option set)
            self::ENUM_TO_CHOICE, self::MATCH_TO_CHOICE => VariableType::ENUM,

            // everything else terminates in boolean (the condition predicates)
            self::TEXT_EQUALS, self::TEXT_NOT_EQUALS, self::TEXT_CONTAINS, self::TEXT_STARTS_WITH,
            self::TEXT_ENDS_WITH, self::TEXT_IS_EMPTY, self::TEXT_IS_NOT_EMPTY,
            self::NUM_EQ, self::NUM_NEQ, self::NUM_GT, self::NUM_GTE, self::NUM_LT, self::NUM_LTE, self::NUM_BETWEEN,
            self::BOOL_NOT,
            self::DATE_BEFORE, self::DATE_AFTER, self::DATE_ON, self::DATE_BETWEEN,
            self::DATE_IS_WEEKEND, self::DATE_IS_PAST, self::DATE_IS_FUTURE,
            self::ENUM_IS, self::ENUM_IS_NOT, self::ENUM_IN,
            self::MULTI_INCLUDES, self::MULTI_EXCLUDES, self::MULTI_INCLUDES_ANY, self::MULTI_INCLUDES_ALL,
            self::MULTI_IS_EMPTY,
            self::FILE_IS_EMPTY, self::FILE_IS_NOT_EMPTY,
            // Presence predicates (append-only): boolean.
            self::IS_PRESENT, self::IS_NULL => VariableType::BOOLEAN,

            // coalesce/assert_present NOMINALLY yield text (they preserve the running type at runtime);
            // date_format renders a date to text.
            self::COALESCE, self::ASSERT_PRESENT, self::DATE_FORMAT => VariableType::TEXT,
        };
    }

    /**
     * The ordered argument descriptors for this op ([] for a nullary op).
     *
     * @return array<int, OperationArg>
     */
    public function argDescriptors(): array
    {
        return match ($this) {
            self::TEXT_SUBSTRING => [
                OperationArg::literal('start', OperationArgType::NUMBER),
                OperationArg::literal('length', OperationArgType::NUMBER),
            ],
            self::TEXT_REPLACE => [
                OperationArg::literal('search', OperationArgType::TEXT),
                OperationArg::literal('replace', OperationArgType::TEXT),
            ],
            self::TEXT_APPEND, self::TEXT_PREPEND => [
                OperationArg::literal('value', OperationArgType::TEXT),
            ],
            self::TEXT_EQUALS, self::TEXT_NOT_EQUALS, self::TEXT_CONTAINS,
            self::TEXT_STARTS_WITH, self::TEXT_ENDS_WITH => [
                OperationArg::literal('value', OperationArgType::TEXT),
            ],

            self::NUM_ADD, self::NUM_SUBTRACT, self::NUM_MULTIPLY, self::NUM_DIVIDE,
            self::NUM_EQ, self::NUM_NEQ, self::NUM_GT, self::NUM_GTE, self::NUM_LT, self::NUM_LTE => [
                OperationArg::literal('value', OperationArgType::NUMBER),
            ],
            self::NUM_ROUND => [
                OperationArg::literal('precision', OperationArgType::NUMBER),
            ],
            self::NUM_BETWEEN => [
                OperationArg::literal('from', OperationArgType::NUMBER),
                OperationArg::literal('to', OperationArgType::NUMBER),
            ],

            self::BOOL_TO_NUMBER => [
                OperationArg::literal('when_true', OperationArgType::NUMBER),
                OperationArg::literal('when_false', OperationArgType::NUMBER),
            ],
            self::BOOL_TO_TEXT => [
                OperationArg::literal('when_true', OperationArgType::TEXT),
                OperationArg::literal('when_false', OperationArgType::TEXT),
            ],

            self::DATE_ADD_DAYS, self::DATE_SUBTRACT_DAYS, self::DATE_ADD_MONTHS, self::DATE_ADD_YEARS => [
                OperationArg::literal('value', OperationArgType::NUMBER),
            ],
            self::DATE_BEFORE, self::DATE_AFTER, self::DATE_ON => [
                OperationArg::literal('value', OperationArgType::DATE),
            ],
            self::DATE_BETWEEN => [
                OperationArg::literal('from', OperationArgType::DATE),
                OperationArg::literal('to', OperationArgType::DATE),
            ],

            self::ENUM_IS, self::ENUM_IS_NOT => [
                OperationArg::literal('value', OperationArgType::SOURCE_OPTION),
            ],
            self::ENUM_IN => [
                OperationArg::literal('values', OperationArgType::SOURCE_OPTIONS),
            ],
            self::ENUM_TO_TEXT => [OperationArg::map('mapping', VariableType::TEXT)],
            self::ENUM_TO_NUMBER => [OperationArg::map('mapping', VariableType::NUMBER)],
            self::ENUM_TO_DATE => [OperationArg::map('mapping', VariableType::DATE)],
            // The mapType is ENUM so the write-validator applies the per-field TARGET option set to the
            // mapped VALUES (the keys stay ⊆ the source options). The target options are injected per field.
            self::ENUM_TO_CHOICE => [OperationArg::map('mapping', VariableType::ENUM)],
            self::MATCH_TO_CHOICE => [
                OperationArg::choiceRules('rules'),
                OperationArg::choiceFallback('fallback'),
            ],

            self::MULTI_INCLUDES, self::MULTI_EXCLUDES => [
                OperationArg::literal('value', OperationArgType::SOURCE_OPTION),
            ],
            self::MULTI_INCLUDES_ANY, self::MULTI_INCLUDES_ALL => [
                OperationArg::literal('values', OperationArgType::SOURCE_OPTIONS),
            ],

            // Presence/format ops (append-only): coalesce's fallback + date_format's safe-token pattern.
            self::COALESCE => [
                OperationArg::literal('fallback', OperationArgType::TEXT),
            ],
            self::DATE_FORMAT => [
                OperationArg::literal('pattern', OperationArgType::TEXT),
            ],

            // `at` takes ONE signed-integer index (1-based, clamped). count is nullary. The index is a
            // plain NUMBER literal OR an arg-variable — validated/pre-resolved through the SAME number-arg
            // path as every other numeric argument. It ALSO carries a typed element DEFAULT (F4): a
            // `{type, value}` literal substituted for the nullable element when array_at is not the
            // pipeline terminal (REQUIRED there — the walk enforces position-aware; declared here so the
            // foreign-key reject does not 422 it).
            self::ARRAY_AT => [
                OperationArg::literal('index', OperationArgType::NUMBER),
                OperationArg::elementDefault('default'),
            ],

            // The higher-order array ops (wave 2). map/filter/sort take ONE per-element pipeline; reduce
            // takes a typed SEED literal + a reducer pipeline (the accumulator's initial value + the fold).
            self::ARRAY_MAP, self::ARRAY_FILTER, self::ARRAY_SORT => [
                OperationArg::elementPipeline('pipeline'),
            ],
            self::ARRAY_REDUCE => [
                OperationArg::reduceSeed('seed'),
                OperationArg::elementPipeline('reducer'),
            ],

            default => [],
        };
    }

    /**
     * Whether this op is an ARRAY transform — it consumes a WHOLE array<T> (any element base) and is
     * therefore gated on the running value being an ARRAY (`$currentDescriptor['array'] === true`)
     * rather than on a flat input type. This is why flat-type gating is insufficient for these ops (a
     * MULTI, an array<scalar>, an array<object>… all satisfy it). count/at ship in wave 1; map/filter/
     * sort/reduce are the forward-looking members (added in a later wave) that will also return true.
     * The descriptor-tracking walker (WorkflowConditionTreeValidator::walkPipeline) reads this.
     */
    public function isArrayOp(): bool
    {
        return match ($this) {
            self::ARRAY_COUNT, self::ARRAY_AT,
            self::ARRAY_MAP, self::ARRAY_FILTER, self::ARRAY_SORT, self::ARRAY_REDUCE => true,
            default => false,
        };
    }

    /**
     * Whether this op is a HIGHER-ORDER array transform — an isArrayOp that additionally carries a
     * PER-ELEMENT PIPELINE run once per element (map/filter/sort/reduce), as opposed to the O(1)
     * whole-array ops (count/at). The walker validates its element pipeline (terminal-gated) and the
     * executor re-enters per element with a scoped `{element, index}` overlay; count/at do neither.
     */
    public function isCollectionOp(): bool
    {
        return match ($this) {
            self::ARRAY_MAP, self::ARRAY_FILTER, self::ARRAY_SORT, self::ARRAY_REDUCE => true,
            default => false,
        };
    }

    /**
     * The TERMINAL descriptor this op produces given its INPUT descriptor (and its literal $args) — the
     * descriptor-tracking analogue of outputType(), read by walkPipeline to thread precise element
     * typing through an array pipeline. The default arm (EVERY legacy op) returns
     * `$this->outputType()->descriptor()`, which fromDescriptor() inverts back to exactly outputType()
     * — so the walker's per-op terminal is byte-for-byte the legacy `$currentType = $op->outputType()`
     * for every non-array op (a provable no-op). The array ops override:
     *   - count → NUMBER descriptor.
     *   - at    → the input's ELEMENT descriptor with `array:false`, `nullable:true` — UNLESS a valid typed
     *             DEFAULT arg is present (F4), which guarantees a value, so `nullable:false` then. An element
     *             is otherwise absent when the array is empty / the index clamps to nothing at runtime.
     *
     * @param  array<string, mixed>  $inputDescriptor  the running value's descriptor (VariableType::descriptor() shape)
     * @param  array<string, mixed>  $args  the op's (already type-checked) literal args
     * @return array<string, mixed>
     */
    public function outputDescriptor(array $inputDescriptor, array $args = [], ?array $terminalDescriptor = null): array
    {
        return match ($this) {
            self::ARRAY_COUNT => VariableType::NUMBER->descriptor(),
            self::ARRAY_AT => $this->elementDescriptorOf($inputDescriptor, $args),
            // map -> array<U> where U = the element pipeline's TERMINAL base (computed by the caller by
            // recursively walking the element pipeline). filter/sort preserve the input array. reduce
            // collapses to the reducer's terminal base (= the seed base U), non-array, non-null.
            self::ARRAY_MAP => array_merge(
                $terminalDescriptor ?? VariableType::TEXT->descriptor(),
                ['array' => true, 'nullable' => false],
            ),
            self::ARRAY_FILTER, self::ARRAY_SORT => $inputDescriptor,
            self::ARRAY_REDUCE => array_merge(
                $terminalDescriptor ?? VariableType::TEXT->descriptor(),
                ['array' => false, 'nullable' => false],
            ),
            default => $this->outputType()->descriptor(),
        };
    }

    /**
     * The ELEMENT descriptor of an array descriptor, marked non-array. Prefers an explicit
     * `elementDescriptor` (carried by array<object>/array<file>/typed array<scalar> in later waves);
     * otherwise the element is this same descriptor collapsed to a single item — for a MULTI wire value
     * `{base:'enum', array:true, options}` that is `{base:'enum', array:false, options}` (an enum
     * element), carrying the options along.
     *
     * NULLABILITY (F4): the element is `nullable:true` by default (an empty array / a clamped-to-nothing
     * index has no element), but `nullable:false` when array_at carries a valid typed `default` — the
     * default guarantees a value, so a FOLLOWING op sees a non-null element.
     *
     * @param  array<string, mixed>  $inputDescriptor
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function elementDescriptorOf(array $inputDescriptor, array $args = []): array
    {
        $element = is_array($inputDescriptor['elementDescriptor'] ?? null)
            ? $inputDescriptor['elementDescriptor']
            : $inputDescriptor;

        $element['array'] = false;
        $element['nullable'] = !$this->hasValidElementDefault($args);
        unset($element['elementDescriptor']);

        return $element;
    }

    /**
     * Whether $args carries a VALID typed element DEFAULT (F4) — a `{type, value}` literal with a known base
     * type and an ENTERED value. Used to mark array_at's output non-nullable; a byte-blind shape check (the
     * pipeline walk owns the element-base type-check + requirement), so an absent/malformed default keeps
     * the nullable element.
     *
     * A BLANK value (null or the empty string) counts as NOT ENTERED (owner directive: require ENTERING a
     * default — an empty string is not a value), so it does NOT flip the element non-nullable; the write
     * validator + the executor apply the SAME rule. Number 0 / boolean false ARE values.
     *
     * @param  array<string, mixed>  $args
     */
    private function hasValidElementDefault(array $args): bool
    {
        $default = $args['default'] ?? null;
        $value = is_array($default) ? ($default['value'] ?? null) : null;

        return is_array($default)
            && $value !== null
            && $value !== ''
            && VariableType::tryFrom((string) ($default['type'] ?? '')) !== null;
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
     * Whether this op is a PRESENCE-family helper (append-only, phase-1b): coalesce / is_present /
     * is_null / assert_present. These inspect a value's EMPTINESS rather than its shape, so the
     * executor handles them BEFORE the per-step type gate — they accept ANY running type (see
     * OperationExecutor::applyPresence). date_format is NOT one (it is an ordinary date op).
     */
    public function isPresenceOp(): bool
    {
        return match ($this) {
            self::COALESCE, self::IS_PRESENT, self::IS_NULL, self::ASSERT_PRESENT => true,
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
            'args' => array_map(fn (OperationArg $arg): array => $arg->toArray(), $op->argDescriptors()),
        ], self::cases());
    }
}
