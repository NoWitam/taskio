<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Enums\WorkflowVariableType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The single, reusable authority for a workflow GLOBAL's TYPE: it validates that a stored
 * `descriptor` is a well-formed type from the Phase 1-2 descriptor system, and that a `value`
 * is a LITERAL matching that descriptor. Centralized here (not hand-rolled in the FormRequest) so
 * the CRUD write path and any future importer share ONE definition of "a valid global type + value".
 *
 * It mirrors the module's second-pass validators (WorkflowScheduleRulesValidator,
 * WorkflowConditionTreeValidator): it appends granular errors to the request's Validator under the
 * `descriptor.*` / `value.*` keys, and only type-checks the value once the descriptor is well-formed
 * (so value errors are never noise from a malformed type).
 *
 * AUTHORABLE bases: text, number, boolean, date, enum, object (+ the `array` flag on any of them,
 * `nullable`, enum `options`, object `fields`). file (a copy-on-attach composite) and time (no
 * literal runtime semantics this slice) are intentionally NOT authorable — a global holds a plain
 * typed constant. `multi` is not a base (a multi-select is base=enum + array=true).
 */
class WorkflowGlobalTypeValidator
{
    /** @var array<int, string> */
    private const AUTHORABLE_BASES = ['text', 'number', 'boolean', 'date', 'enum', 'object'];

    /** A safe object-field / identifier key: letters, digits, underscores; not leading-digit. */
    private const SAFE_KEY = '/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/';

    /**
     * Validate a descriptor + its value, appending any errors to $validator. The value is only
     * type-checked when the descriptor is a well-formed authorable type.
     */
    public function validate(
        Validator $validator,
        mixed $descriptor,
        mixed $value,
        string $descriptorKey = 'descriptor',
        string $valueKey = 'value',
    ): void {
        // A global's value is user-authored and interpolated into templates. The resolver's
        // injection mask assumes resolved values are NUL-free (Postgres form answers cannot carry
        // NUL); a global's `value` column is `json`, which permits the NUL byte. Reject it on write so
        // that invariant holds for globals too — a masked value can never be forged.
        $valueOk = !$this->containsNulByte($value);

        if (!$valueOk) {
            $validator->errors()->add($valueKey, 'A value must not contain NUL bytes.');
        }

        if ($this->validateDescriptor($validator, $descriptor, $descriptorKey) && $valueOk) {
            $this->validateValue($validator, $value, $descriptor, $valueKey);
        }
    }

    /** Whether a literal (scalar/list/object) carries a NUL byte in any string key or value. */
    private function containsNulByte(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains($value, "\0");
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ((is_string($key) && str_contains($key, "\0")) || $this->containsNulByte($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    // ---- descriptor -----------------------------------------------------------

    /** Whether $descriptor is a well-formed authorable type (errors appended under $key). */
    private function validateDescriptor(Validator $validator, mixed $descriptor, string $key): bool
    {
        if (!is_array($descriptor)) {
            $validator->errors()->add($key, 'The type descriptor must be an object.');

            return false;
        }

        $base = $descriptor['base'] ?? null;

        if (!in_array($base, self::AUTHORABLE_BASES, true)) {
            $validator->errors()->add(
                $key . '.base',
                'The descriptor base must be one of: ' . implode(', ', self::AUTHORABLE_BASES) . '.',
            );

            return false;
        }

        $ok = true;

        foreach (['nullable', 'array'] as $flag) {
            if (array_key_exists($flag, $descriptor) && !is_bool($descriptor[$flag])) {
                $validator->errors()->add($key . '.' . $flag, 'The ' . $flag . ' flag must be a boolean.');
                $ok = false;
            }
        }

        if ($base === WorkflowVariableType::ENUM->value) {
            $ok = $this->validateEnumOptions($validator, $descriptor['options'] ?? null, $key . '.options') && $ok;
        }

        if ($base === WorkflowVariableType::OBJECT->value) {
            $ok = $this->validateObjectFields($validator, $descriptor['fields'] ?? null, $key . '.fields') && $ok;
        }

        return $ok;
    }

    /**
     * An enum descriptor's `options`: a non-empty list of `{key, label?}` with distinct, scalar keys.
     */
    private function validateEnumOptions(Validator $validator, mixed $options, string $key): bool
    {
        if (!is_array($options) || $options === [] || !array_is_list($options)) {
            $validator->errors()->add($key, 'An enum type requires a non-empty list of options.');

            return false;
        }

        $ok = true;
        $seen = [];

        foreach ($options as $i => $option) {
            $optionKey = is_array($option) ? ($option['key'] ?? null) : null;

            if (!is_scalar($optionKey) || (string) $optionKey === '') {
                $validator->errors()->add($key . '.' . $i, 'Each option requires a non-empty key.');
                $ok = false;

                continue;
            }

            if (in_array((string) $optionKey, $seen, true)) {
                $validator->errors()->add($key . '.' . $i, 'Option keys must be distinct.');
                $ok = false;
            }

            $seen[] = (string) $optionKey;
        }

        return $ok;
    }

    /**
     * An object descriptor's `fields`: a non-empty list of `{key, label?, descriptor}` with safe,
     * distinct keys and each child descriptor recursively well-formed.
     */
    private function validateObjectFields(Validator $validator, mixed $fields, string $key): bool
    {
        if (!is_array($fields) || $fields === [] || !array_is_list($fields)) {
            $validator->errors()->add($key, 'An object type requires a non-empty list of fields.');

            return false;
        }

        $ok = true;
        $seen = [];

        foreach ($fields as $i => $field) {
            $fieldKey = is_array($field) ? ($field['key'] ?? null) : null;

            if (!is_string($fieldKey) || preg_match(self::SAFE_KEY, $fieldKey) !== 1) {
                $validator->errors()->add($key . '.' . $i . '.key', 'Each field requires a safe identifier key.');
                $ok = false;
            } elseif (in_array($fieldKey, $seen, true)) {
                $validator->errors()->add($key . '.' . $i . '.key', 'Field keys must be distinct.');
                $ok = false;
            } else {
                $seen[] = $fieldKey;
            }

            $ok = $this->validateDescriptor($validator, $field['descriptor'] ?? null, $key . '.' . $i . '.descriptor') && $ok;
        }

        return $ok;
    }

    // ---- value ----------------------------------------------------------------

    /** Type-check a literal $value against a well-formed $descriptor (errors appended under $key). */
    private function validateValue(Validator $validator, mixed $value, array $descriptor, string $key): void
    {
        if ($value === null) {
            if (($descriptor['nullable'] ?? false) !== true) {
                $validator->errors()->add($key, 'A value is required for this type.');
            }

            return;
        }

        if (($descriptor['array'] ?? false) === true) {
            if (!is_array($value) || !array_is_list($value)) {
                $validator->errors()->add($key, 'The value must be a list for an array type.');

                return;
            }

            // Each ELEMENT is a non-null single of the same base (the array itself may be nullable,
            // its elements are not). Strip the array/nullable flags for the per-element check.
            $element = $descriptor;
            unset($element['array'], $element['nullable']);

            foreach ($value as $i => $item) {
                $this->validateSingle($validator, $item, $element, $key . '.' . $i);
            }

            return;
        }

        $this->validateSingle($validator, $value, $descriptor, $key);
    }

    /** Type-check a single (non-array) value against its base. */
    private function validateSingle(Validator $validator, mixed $value, array $descriptor, string $key): void
    {
        $base = $descriptor['base'] ?? null;

        $valid = match ($base) {
            WorkflowVariableType::TEXT->value => is_string($value),
            WorkflowVariableType::NUMBER->value => is_numeric($value),
            WorkflowVariableType::BOOLEAN->value => is_bool($value),
            WorkflowVariableType::DATE->value => $this->isParsableDate($value),
            WorkflowVariableType::ENUM->value => $this->isEnumMember($value, $descriptor),
            WorkflowVariableType::OBJECT->value => $this->isValidObject($validator, $value, $descriptor, $key),
            default => false,
        };

        if (!$valid) {
            $validator->errors()->add($key, 'The value does not match the ' . (is_string($base) ? $base : 'declared') . ' type.');
        }
    }

    /** Whether $value string-equals one of the enum descriptor's option keys. */
    private function isEnumMember(mixed $value, array $descriptor): bool
    {
        if (!is_scalar($value)) {
            return false;
        }

        $options = is_array($descriptor['options'] ?? null) ? $descriptor['options'] : [];

        foreach ($options as $option) {
            if (is_array($option) && is_scalar($option['key'] ?? null) && (string) $option['key'] === (string) $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $value is an object matching the descriptor's `fields` (each declared field validated
     * recursively; a missing field reads as null → its own nullable flag decides; an undeclared key
     * is rejected as foreign). Adds field-level errors under $key.<fieldKey>; returns true only when
     * the shape itself is an assoc array (leaf type errors are reported, not bubbled as one flag).
     */
    private function isValidObject(Validator $validator, mixed $value, array $descriptor, string $key): bool
    {
        if (!is_array($value) || array_is_list($value)) {
            return false;
        }

        $fields = is_array($descriptor['fields'] ?? null) ? $descriptor['fields'] : [];
        $declared = [];

        foreach ($fields as $field) {
            if (!is_array($field) || !is_string($field['key'] ?? null) || !is_array($field['descriptor'] ?? null)) {
                continue;
            }

            $declared[] = $field['key'];
            $this->validateValue($validator, $value[$field['key']] ?? null, $field['descriptor'], $key . '.' . $field['key']);
        }

        foreach (array_keys($value) as $presentKey) {
            if (!in_array((string) $presentKey, $declared, true)) {
                $validator->errors()->add($key . '.' . $presentKey, 'The ' . $presentKey . ' field is not part of this object type.');
            }
        }

        return true;
    }

    /** Whether a value parses as a date (a string/number Carbon accepts) — never throws. */
    private function isParsableDate(mixed $value): bool
    {
        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        try {
            Carbon::parse($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
