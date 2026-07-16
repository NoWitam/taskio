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
 *
 * The EDITOR primitive vocabulary is narrower (text|number|boolean); date/enum/multi
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
        };
    }
}
