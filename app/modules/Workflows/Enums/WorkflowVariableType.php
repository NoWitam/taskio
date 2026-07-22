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
 *   - file     uploaded/picked disk file(s), carried as a snapshot list {id,name,mime_type,size,url};
 *              a COMPOSITE whose subfields (id/name/type/size/url) are individually referenceable —
 *              its descriptor carries them as `fields`, but the wire `type` stays `file` so every
 *              existing file semantic (text→name, structural→id, copy-on-attach) is preserved
 *
 * Two DESCRIPTOR-ONLY bases are APPENDED past this closed core (append-only): `time` (phase-1a) and
 * `object` (phase-2a — a SECTION is an `object`, a REPEATER an `array<object>`). They surface ONLY in
 * the structured descriptor(); their flat wire `type` degrades to text and their operatorCases() are
 * empty, so the resolver/evaluator/executor's defaultless match sites — and the closed FE type-union —
 * never receive them (see WorkflowVariableCatalogService::flatType, and ADR-0022 for the tripwire).
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
    // Appended (phase-2a, append-only). A STRUCTURAL container: a form SECTION is an `object`, a
    // REPEATER an `array<object>` (descriptor `array:true`). It exists so the editor can see the whole
    // form structure (form-coverage completeness) — REPRESENTATION ONLY; per-element LOOP execution is
    // out of scope (deferred to R2-Generator). Like TIME it is DESCRIPTOR-ONLY: its flat wire `type`
    // degrades to text (flatType) and operatorCases() is [] (non-conditionable), so the defaultless
    // match sites in the resolver/evaluator/executor + the closed FE type-union never receive `object`.
    case OBJECT = 'object';

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The type IDENTITY of a stored DESCRIPTOR — the inverse of descriptor(). Recovers the
     * WorkflowVariableType a `{ base, array, … }` descriptor represents, so a form-INDEPENDENT
     * descriptor (a user-authored global's stored type) maps back onto the same closed vocabulary
     * the catalog/resolver speak:
     *   - base 'object'                 → OBJECT (a structural container; its flat wire type later
     *                                     degrades to text via the catalog's flatType).
     *   - base 'enum', array true       → MULTI  (a multi is an array<enum>).
     *   - base 'enum', array false      → ENUM.
     *   - base scalar (text/number/…), array true  → MULTI (the one array-carrying flat type — an
     *                                     array<scalar> rides it on the wire so it survives coercion
     *                                     as an array; the descriptor keeps the true element base).
     *   - base scalar, array false      → that scalar type.
     * An unknown base falls back to TEXT (defensive — mirrors mapSchemaToVariableType's default).
     *
     * @param  array<string, mixed>  $descriptor
     */
    public static function fromDescriptor(array $descriptor): self
    {
        $base = is_string($descriptor['base'] ?? null) ? $descriptor['base'] : self::TEXT->value;
        $array = ($descriptor['array'] ?? false) === true;

        if ($base === self::OBJECT->value) {
            return self::OBJECT;
        }

        if ($base === self::ENUM->value) {
            return $array ? self::MULTI : self::ENUM;
        }

        $scalar = self::tryFrom($base) ?? self::TEXT;

        return $array ? self::MULTI : $scalar;
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
            // OBJECT is a STRUCTURAL container (section / repeater), never a comparable scalar — it is
            // NOT a condition source. Same fail-closed reasoning as TIME: an empty set keeps it
            // non-conditionable at write time, and its flat wire `type` degrades to text so the
            // evaluator's exhaustive match never sees `object`.
            self::OBJECT => [],
        };
    }

    /**
     * The NORMALIZED structured type DESCRIPTOR for this type (phase-1a) — the legacy-flat → descriptor
     * shim later phases build on. Shape: `{ base, nullable, array, options?, fields? }`:
     *   - MULTI   → `{ base:'enum', array:true }`   (a multi is an array<enum>)
     *   - ENUM    → `{ base:'enum', array:false, options }`
     *   - OBJECT  → `{ base:'object', array:<false=section|true=repeater>, fields }` (phase-2a) — a
     *               STRUCTURAL container; `fields` is an ordered `{key,label,descriptor}` list, each
     *               child descriptor a full (recursive) descriptor. Built by the CALLER (the catalog),
     *               which owns the child labels/structure the JSON schema drops.
     *   - FILE    → `{ base:'file', array:<multi-file?>, fields }` (phase-2b) — a COMPOSITE value; its
     *               `fields` are the FIXED, form-INDEPENDENT subfields {id,name,type,size,url} the type
     *               owns itself (see fileFields), so no caller has to supply them — the wire `type`
     *               still degrades to nothing (it stays `file`), only the descriptor is enriched.
     *   - text|number|boolean|date|time → `{ base:<self>, array:false }`
     * `options` (a list of `{key,label}`) is carried ONLY for an enum base (ENUM/MULTI); `fields` for an
     * object base (caller-supplied) OR a file base (self-supplied when the caller passes none). `$array`
     * OVERRIDES the derived array flag (null = derived: true only for MULTI)
     * — an OBJECT caller passes it explicitly (section=false, repeater=true). The CALLER resolves the
     * human labels — the JSON schema drops them, they live in the form element config.
     *
     * @param  array<int, array{key: string, label: string}>  $options
     * @param  array<int, array{key: string, label: string, descriptor: array<string, mixed>}>  $fields
     * @return array{base: string, nullable: bool, array: bool, options?: array<int, array{key: string, label: string}>, fields?: array<int, array{key: string, label: string, descriptor: array<string, mixed>}>}
     */
    public function descriptor(array $options = [], bool $nullable = false, array $fields = [], ?bool $array = null): array
    {
        $base = $this->descriptorBase();

        $descriptor = [
            'base' => $base->value,
            'nullable' => $nullable,
            'array' => $array ?? ($this === self::MULTI),
        ];

        if ($base === self::ENUM) {
            $descriptor['options'] = array_values($options);
        }

        if ($base === self::OBJECT) {
            $descriptor['fields'] = array_values($fields);
        }

        // A FILE is a COMPOSITE (phase-2b): it carries the fixed {id,name,type,size,url} subfields so
        // the editor can reference `<file>.name` / `.url` etc. Unlike an object container (form-derived
        // fields), a file's shape is invariant, so the type self-describes it when the caller — as every
        // caller does — supplies none. The wire `type` stays `file`; only the descriptor grows.
        if ($base === self::FILE) {
            $descriptor['fields'] = $fields !== [] ? array_values($fields) : self::fileFields();
        }

        return $descriptor;
    }

    /**
     * The canonical composite file SUBFIELDS mapped to their scalar TYPE — the invariant
     * {id,name,type,size,url} set a `<file>.<subfield>` reference resolves against, and the SINGLE
     * source of that set + typing. All are plain scalars (text, except size = number), so a subfield
     * reference needs NO new type and never reaches the resolver's/evaluator's defaultless `match`
     * (the `file`/`object` tripwire). `type` is the human name for the snapshot's `mime_type`.
     *
     * BOTH the file descriptor (fileFields, below) and the write-validation reference index / runtime
     * type map (WorkflowVariableCatalogService) enumerate from here, so the three can never disagree on
     * the subfield set or its types. WorkflowVariableResolver::FILE_SUBFIELDS owns the ORTHOGONAL runtime
     * snapshot-KEY mapping (`type`→`mime_type`) over this SAME key set — a value-access concern, not a
     * type, so it stays a separate map (cross-referenced there).
     *
     * @return array<string, self>
     */
    public static function fileSubfieldTypes(): array
    {
        return [
            'id' => self::TEXT,
            'name' => self::TEXT,
            'type' => self::TEXT,
            'size' => self::NUMBER,
            'url' => self::TEXT,
        ];
    }

    /**
     * The file descriptor's composite subfield entries {key,label,descriptor}, built from the single
     * source (fileSubfieldTypes). Labels fall back to the key (system subfields have no form-config
     * label, like a system enum option).
     *
     * @return array<int, array{key: string, label: string, descriptor: array{base: string, nullable: bool, array: bool}}>
     */
    private static function fileFields(): array
    {
        $fields = [];

        foreach (self::fileSubfieldTypes() as $key => $type) {
            $fields[] = self::fileField($key, $type);
        }

        return $fields;
    }

    /**
     * One file subfield descriptor entry `{key, label, descriptor}`. The child descriptor is a plain
     * scalar (text/number) — never a `file`/`object` base — so it can carry no further fields.
     *
     * @return array{key: string, label: string, descriptor: array{base: string, nullable: bool, array: bool}}
     */
    private static function fileField(string $key, self $type): array
    {
        return ['key' => $key, 'label' => $key, 'descriptor' => $type->descriptor()];
    }

    /**
     * The BASE scalar type of this type's descriptor: MULTI is an array<enum> so its base is ENUM;
     * every other type (ENUM included) is its own base — including OBJECT, whose base is `object`. The
     * default arm means appending a new case (e.g. TIME, OBJECT) never breaks it.
     */
    private function descriptorBase(): self
    {
        return match ($this) {
            self::MULTI => self::ENUM,
            default => $this,
        };
    }
}
