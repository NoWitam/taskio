<?php

namespace App\Modules\Workflows\Enums;

/**
 * The canonical TYPE of a workflow variable / condition field. ONE identity type shared by
 * both serializations of a variable reference (the editor directive in text fields, and the
 * structured literal|variable union in non-text fields) and by the condition field_type.
 *
 * The set is intentionally small and closed:
 *   - text     an opaque string (short_text, long_text, url, time, unknown element types)
 *   - number   a numeric value (integer or float)
 *   - boolean  a checkbox / toggle
 *   - date     an ISO date string (compared via Carbon)
 *   - enum     a single-choice value drawn from a known option set (single-select)
 *   - multi    a set of values (multi-select, checklist)
 *   - file     uploaded/picked disk file(s), carried as a snapshot list {id,name,mime_type,size}
 *
 * The EDITOR primitive vocabulary is narrower (text|number|boolean); date/enum/multi/file
 * DEGRADE to text inside a markdown directive (`data.type`). The directive carries NO other
 * type field — it is IDENTITY-ONLY (`data.id` = path); the REAL workflow type is recovered
 * from the catalog by path. See WorkflowVariableCatalogService for the mapping from a form
 * element type.
 */
enum WorkflowVariableType: string
{
    case TEXT = 'text';
    case NUMBER = 'number';
    case BOOLEAN = 'boolean';
    case DATE = 'date';
    case ENUM = 'enum';
    case MULTI = 'multi';
    case FILE = 'file';
    // Appended (phase-1a, append-only). A time-of-day string — the form builder's TIME element
    // (format:'time'), historically degraded to TEXT. Its own base surfaces ONLY in the structured
    // descriptor(); the flat wire `type` still degrades to text (WorkflowVariableCatalogService::flatType)
    // until the resolver/evaluator/executor + FE learn it (the deferred runtime-semantics slice).
    case TIME = 'time';

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The editor PRIMITIVE (text|number|boolean) this type serializes to inside a markdown
     * directive's `data.type`. date/enum/multi have no primitive of their own so they degrade
     * to text; the real type is NOT in the directive — consumers recover it from the catalog
     * by the directive's `data.id` path.
     */
    public function editorPrimitive(): string
    {
        return match ($this) {
            self::NUMBER => 'number',
            self::BOOLEAN => 'boolean',
            default => 'text',
        };
    }

    /**
     * The condition operators valid for this field type. The evaluator and the FormRequest
     * both read this so the accepted operator set can never drift from what the engine can
     * evaluate. Boolean carries value-less operators (is_true / is_false).
     *
     * @return array<int, string>
     */
    public function operators(): array
    {
        return array_map(
            fn (WorkflowConditionOperator $op) => $op->value,
            $this->operatorCases(),
        );
    }

    /** @return array<int, WorkflowConditionOperator> */
    public function operatorCases(): array
    {
        return match ($this) {
            self::TEXT => [
                WorkflowConditionOperator::EQUALS,
                WorkflowConditionOperator::NOT_EQUALS,
                WorkflowConditionOperator::CONTAINS,
            ],
            self::NUMBER => [
                WorkflowConditionOperator::EQ,
                WorkflowConditionOperator::NEQ,
                WorkflowConditionOperator::GT,
                WorkflowConditionOperator::GTE,
                WorkflowConditionOperator::LT,
                WorkflowConditionOperator::LTE,
            ],
            self::DATE => [
                WorkflowConditionOperator::BEFORE,
                WorkflowConditionOperator::AFTER,
                WorkflowConditionOperator::ON,
                WorkflowConditionOperator::BETWEEN,
            ],
            self::ENUM => [
                WorkflowConditionOperator::IS,
                WorkflowConditionOperator::IS_NOT,
                WorkflowConditionOperator::IN,
            ],
            self::MULTI => [
                WorkflowConditionOperator::INCLUDES,
                WorkflowConditionOperator::EXCLUDES,
            ],
            self::BOOLEAN => [
                WorkflowConditionOperator::IS_TRUE,
                WorkflowConditionOperator::IS_FALSE,
            ],
            // A file is either attached or not. Comparing one with a flat scalar operator is
            // meaningless; questions about its type or name are pipeline operations in the
            // condition tree (see WorkflowOperation's file_* cases).
            self::FILE => [
                WorkflowConditionOperator::FILLED,
                WorkflowConditionOperator::EMPTY,
            ],
            // TIME carries NO operators THIS slice. The condition evaluator + operation executor
            // dispatch on an EXHAUSTIVE match over the legacy 7 types (no default), so a stored
            // `time` condition would 500 at run time rather than fail closed. An empty set keeps a
            // time field NON-conditionable (rejected at write, exactly as today), until the deferred
            // runtime-semantics slice adds the evaluator/executor arms + real operators.
            self::TIME => [],
        };
    }

    /**
     * The NORMALIZED structured type DESCRIPTOR for this type (phase-1a) — the legacy-flat → descriptor
     * shim later phases build on. Shape: `{ base, nullable, array, options? }`:
     *   - MULTI  → `{ base:'enum', array:true }`   (a multi is an array<enum>)
     *   - ENUM   → `{ base:'enum', array:false, options }`
     *   - text|number|boolean|date|time|file → `{ base:<self>, array:false }`
     * `options` (a list of `{key,label}`) is carried ONLY for an enum base (ENUM/MULTI); the CALLER
     * resolves the human labels — the JSON schema drops them, they live in the form element config.
     *
     * @param  array<int, array{key: string, label: string}>  $options
     * @return array{base: string, nullable: bool, array: bool, options?: array<int, array{key: string, label: string}>}
     */
    public function descriptor(array $options = [], bool $nullable = false): array
    {
        $base = $this->descriptorBase();

        $descriptor = [
            'base' => $base->value,
            'nullable' => $nullable,
            'array' => $this === self::MULTI,
        ];

        if ($base === self::ENUM) {
            $descriptor['options'] = array_values($options);
        }

        return $descriptor;
    }

    /**
     * The BASE scalar type of this type's descriptor: MULTI is an array<enum> so its base is ENUM;
     * every other type (ENUM included) is its own base. The default arm means appending a new case
     * (e.g. TIME) never breaks it.
     */
    private function descriptorBase(): self
    {
        return match ($this) {
            self::MULTI => self::ENUM,
            default => $this,
        };
    }
}
