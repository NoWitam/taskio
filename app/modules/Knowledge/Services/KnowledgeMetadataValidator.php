<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Variables\Services\ConstantTypeValidator;
use Illuminate\Contracts\Validation\Validator;

/**
 * The write-side authority for a base's METADATA SCHEMA and for an entry's metadata VALUES.
 *
 * It owns almost nothing: the actual type judgement is delegated to the shared
 * {@see ConstantTypeValidator}, the same class that decides whether a constant's value matches its
 * declared type. That reuse is the design, not a shortcut — a metadata field the type system cannot
 * describe is a field nothing else in the product can read back, so the knowledge module gets to
 * declare no types of its own.
 *
 * What IS decided here is the surface: a schema field may use the AUTHORABLE bases only (text,
 * number, boolean, date, enum, object, plus the nullable/array flags), which is exactly the
 * constant surface. `file` is excluded — the Generator's template slots widen to it because a slot
 * holds a REFERENCE to a real file row, whereas knowledge metadata is a plain literal describing the
 * entry; admitting `file` would make this module own file lifecycles it has no business owning, and
 * would name a module it must not name. `multi` is not a base at all (a multi-select is
 * base=enum + array=true).
 *
 * Errors are appended to the caller's Validator under granular keys, mirroring how the Workflows
 * second-pass validators report, so a bad field points at itself instead of failing the whole
 * request with one opaque message.
 */
class KnowledgeMetadataValidator
{
    /** A safe field key: letters, digits, underscores; not leading-digit. Mirrors the constant key rule. */
    private const SAFE_KEY = '/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/';

    /** Bound on how many fields one base may declare — a schema, not a spreadsheet. */
    public const MAX_FIELDS = 50;

    public function __construct(
        private ConstantTypeValidator $types,
    ) {}

    /**
     * Validate a base's `metadata_schema`: an ordered list of `{key, label, descriptor}` with safe,
     * distinct keys and a well-formed authorable descriptor each. Returns whether it is well-formed.
     */
    public function validateSchema(Validator $validator, mixed $schema, string $key = 'metadata_schema'): bool
    {
        if (!is_array($schema) || !array_is_list($schema)) {
            $validator->errors()->add($key, __('knowledge.validation.schema_must_be_list'));

            return false;
        }

        if (count($schema) > self::MAX_FIELDS) {
            $validator->errors()->add($key, __('knowledge.validation.schema_too_many_fields', ['max' => self::MAX_FIELDS]));

            return false;
        }

        $ok = true;
        $seen = [];

        foreach ($schema as $i => $field) {
            $fieldKey = is_array($field) ? ($field['key'] ?? null) : null;

            if (!is_string($fieldKey) || preg_match(self::SAFE_KEY, $fieldKey) !== 1) {
                $validator->errors()->add($key . '.' . $i . '.key', __('knowledge.validation.field_key_invalid'));
                $ok = false;
            } elseif (in_array($fieldKey, $seen, true)) {
                $validator->errors()->add($key . '.' . $i . '.key', __('knowledge.validation.field_key_duplicate'));
                $ok = false;
            } else {
                $seen[] = $fieldKey;
            }

            $label = is_array($field) ? ($field['label'] ?? null) : null;

            if ($label !== null && (!is_string($label) || mb_strlen($label) > 255)) {
                $validator->errors()->add($key . '.' . $i . '.label', __('knowledge.validation.field_label_invalid'));
                $ok = false;
            }

            // The type judgement itself — the shared authority, constant surface (no `file`).
            $ok = $this->types->validateDescriptorShape(
                $validator,
                is_array($field) ? ($field['descriptor'] ?? null) : null,
                $key . '.' . $i . '.descriptor',
            ) && $ok;
        }

        return $ok;
    }

    /**
     * Validate an entry's `metadata` map against the base's declared descriptors.
     *
     * A DECLARED field that is absent reads as null, so its own `nullable` flag decides whether that
     * is allowed — the same rule the type system already applies to an object's fields. An UNDECLARED
     * key is rejected rather than ignored: metadata that silently disappears on save is worse than a
     * refusal, because the writer believes it was stored and only finds out when a filter never
     * matches.
     *
     * @param  array<string, array<string, mixed>>  $descriptors  key => descriptor (the base's schema)
     */
    public function validateValues(Validator $validator, array $descriptors, mixed $metadata, string $key = 'metadata'): void
    {
        if ($metadata === null) {
            $metadata = [];
        }

        // An empty array is both a list and a map — accept it as "no metadata".
        if (!is_array($metadata) || (array_is_list($metadata) && $metadata !== [])) {
            $validator->errors()->add($key, __('knowledge.validation.metadata_must_be_object'));

            return;
        }

        foreach ($descriptors as $fieldKey => $descriptor) {
            $this->types->validate(
                $validator,
                $descriptor,
                $metadata[$fieldKey] ?? null,
                // The stored schema was validated on write, so this key can only light up on corrupt
                // data — pointing it at the field keeps that report next to the value it concerns.
                $key . '.' . $fieldKey,
                $key . '.' . $fieldKey,
            );
        }

        foreach (array_keys($metadata) as $presentKey) {
            if (!array_key_exists((string) $presentKey, $descriptors)) {
                $validator->errors()->add(
                    $key . '.' . $presentKey,
                    __('knowledge.validation.metadata_field_unknown', ['field' => (string) $presentKey]),
                );
            }
        }
    }
}
