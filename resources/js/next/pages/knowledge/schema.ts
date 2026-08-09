// knowledge/schema — the PURE, testable authoring model for a base's METADATA SCHEMA.
//
// A schema is an ordered list of `{key, label, descriptor}` (the same shape a constant's type
// uses; see KnowledgeMetadataValidator, which delegates the type judgement to the shared
// ConstantTypeValidator). This module owns the drift-critical mapping between the builder's row
// DRAFT and that stored shape, plus the CLIENT mirror of the backend's rules — the server stays
// authoritative; this only blocks obviously-wrong input early and drives friendly copy.
//
// OFFERED bases: text | number | boolean | date | enum (+ orthogonal nullable / array).
// The backend would also accept `object`; the UX spec (§7.3) deliberately does NOT offer it —
// entry metadata is meant to stay FLAT so it can be filtered and shown in a table column, and a
// container would kill both. `file` / `time` are not authorable at all.
//
// A field the builder cannot edit (an `object` written through the API, or any future base) is
// NEVER dropped: its row is carried through as `unsupported` and re-emitted byte-for-byte on
// save, so opening a base in this editor can never silently destroy part of its schema.
import type { VariableDescriptor } from '../../ui/variables/types';
import type { KnowledgeSchemaField } from './types';

// --- Vocabulary --------------------------------------------------------------

/** The bases the builder offers, in picker order. */
export type KnowledgeFieldBase = 'text' | 'number' | 'boolean' | 'date' | 'enum';

export const SCHEMA_FIELD_BASES: KnowledgeFieldBase[] = ['text', 'number', 'boolean', 'date', 'enum'];

/** A safe field key — mirrors KnowledgeMetadataValidator::SAFE_KEY 1:1. */
export const SAFE_KEY_RE = /^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/;

/** Bound on declared fields — mirrors KnowledgeMetadataValidator::MAX_FIELDS. */
export const MAX_SCHEMA_FIELDS = 50;

/** Whether a base is one the builder can render controls for. */
export function isOfferedBase(base: string | undefined): base is KnowledgeFieldBase {
  return SCHEMA_FIELD_BASES.includes(base as KnowledgeFieldBase);
}

// --- Draft model -------------------------------------------------------------

/** One enum choice row (`id` is a stable v-for key only, never sent). */
export interface SchemaOptionDraft {
  id: string;
  key: string;
  label: string;
}

/**
 * One schema field row.
 *
 * `keyTouched` implements the per-row `keyModel` pattern: the key tracks the label's slug until
 * the author edits it, then it is independent. `unsupported` holds the ORIGINAL descriptor of a
 * field this builder cannot edit — when set, the row is read-only and re-emits that descriptor.
 */
export interface SchemaFieldDraft {
  id: string;
  key: string;
  keyTouched: boolean;
  label: string;
  base: KnowledgeFieldBase;
  nullable: boolean;
  array: boolean;
  options: SchemaOptionDraft[];
  unsupported?: VariableDescriptor;
}

let seq = 0;
/** A stable local row id (never sent to the server). */
export function draftRowId(): string {
  seq += 1;
  return `k_${seq}_${Math.random().toString(36).slice(2, 7)}`;
}

/** An empty enum choice row. */
export function emptyOptionDraft(): SchemaOptionDraft {
  return { id: draftRowId(), key: '', label: '' };
}

/** A fresh field row (a plain text field with one starter choice row). */
export function emptyFieldDraft(): SchemaFieldDraft {
  return {
    id: draftRowId(),
    key: '',
    keyTouched: false,
    label: '',
    base: 'text',
    nullable: false,
    array: false,
    options: [emptyOptionDraft()],
  };
}

// --- key slug ----------------------------------------------------------------

/**
 * Derive a field key from a label — the same transform `pages/variables/consts.ts` uses for a
 * const key (strip diacritics, lowercase, collapse non-alphanumerics to `_`, trim `_`). The
 * result may still be unsafe (e.g. a leading digit); `SAFE_KEY_RE` is what gates that.
 */
export function slugifyFieldKey(label: string): string {
  return label
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '');
}

/** The key a row will actually emit: the pinned one, else the label slug. */
export function resolvedFieldKey(row: SchemaFieldDraft): string {
  return (row.keyTouched ? row.key : slugifyFieldKey(row.label)).trim();
}

// --- draft <-> schema --------------------------------------------------------

/** Hydrate the builder rows from a stored schema (edit path). Unknown bases are preserved. */
export function schemaToDraft(schema: KnowledgeSchemaField[] | null | undefined): SchemaFieldDraft[] {
  return (schema ?? []).map((field) => {
    const descriptor = field.descriptor ?? ({ base: 'text', nullable: false, array: false } as VariableDescriptor);
    const offered = isOfferedBase(descriptor.base);
    return {
      id: draftRowId(),
      key: field.key ?? '',
      // A stored key is authoritative — never re-derive it from the label, which would silently
      // rename a field every entry's metadata is keyed by.
      keyTouched: true,
      label: field.label ?? '',
      base: offered ? (descriptor.base as KnowledgeFieldBase) : 'text',
      nullable: !!descriptor.nullable,
      array: !!descriptor.array,
      options:
        descriptor.options && descriptor.options.length > 0
          ? descriptor.options.map((option) => ({
              id: draftRowId(),
              key: option.key,
              label: option.label ?? '',
            }))
          : [emptyOptionDraft()],
      ...(offered ? {} : { unsupported: descriptor }),
    };
  });
}

/**
 * Build the stored schema from the builder rows. An enum carries its `options` (an empty choice
 * label falls back to its key); no other base carries a sub-key. A row the builder could not
 * edit re-emits its original descriptor untouched.
 */
export function draftToSchema(rows: SchemaFieldDraft[]): KnowledgeSchemaField[] {
  return rows.map((row) => {
    const key = resolvedFieldKey(row);
    const label = row.label.trim() || key;

    if (row.unsupported) {
      return { key, label, descriptor: row.unsupported };
    }

    const descriptor: VariableDescriptor = {
      base: row.base,
      nullable: row.nullable,
      array: row.array,
    };

    if (row.base === 'enum') {
      descriptor.options = row.options.map((option) => {
        const optionKey = option.key.trim();
        return { key: optionKey, label: option.label.trim() || optionKey };
      });
    }

    return { key, label, descriptor };
  });
}

// --- validation (mirrors the backend) ----------------------------------------

/** A per-row failure, as an i18n key the UI renders directly. */
export type SchemaFieldErrorKey =
  | 'knowledge.schema.keyInvalid'
  | 'knowledge.schema.keyDuplicate'
  | 'knowledge.schema.labelRequired'
  | 'knowledge.schema.optionKeyRequired'
  | 'knowledge.schema.optionKeyDuplicate';

export interface SchemaValidation {
  /** Row id → the first failing i18n key for that row. */
  fieldErrors: Record<string, SchemaFieldErrorKey>;
  /** A whole-schema failure (currently only the field-count bound). */
  formError: 'knowledge.schema.tooManyFields' | null;
  valid: boolean;
}

/**
 * Validate the builder rows against the backend's rules: safe + DISTINCT keys, a human label, a
 * non-empty set of distinct choice keys for an enum, and at most MAX_SCHEMA_FIELDS fields.
 *
 * Duplicate keys mark EVERY row that shares the key (not just the later one) — the author has to
 * see both halves of a collision to decide which one to rename.
 */
export function validateSchemaDraft(rows: SchemaFieldDraft[]): SchemaValidation {
  const fieldErrors: Record<string, SchemaFieldErrorKey> = {};
  const byKey = new Map<string, SchemaFieldDraft[]>();

  for (const row of rows) {
    const key = resolvedFieldKey(row);
    if (!SAFE_KEY_RE.test(key)) {
      fieldErrors[row.id] = 'knowledge.schema.keyInvalid';
    } else {
      byKey.set(key, [...(byKey.get(key) ?? []), row]);
    }
  }

  for (const shared of byKey.values()) {
    if (shared.length > 1) {
      for (const row of shared) fieldErrors[row.id] = 'knowledge.schema.keyDuplicate';
    }
  }

  for (const row of rows) {
    if (fieldErrors[row.id]) continue;

    if (row.label.trim() === '') {
      fieldErrors[row.id] = 'knowledge.schema.labelRequired';
      continue;
    }

    // An unsupported (read-only) row was validated when it was written; re-checking a shape this
    // builder cannot express would only fail it for being what it already is.
    if (row.unsupported || row.base !== 'enum') continue;

    const optionKeys = row.options.map((option) => option.key.trim());
    if (optionKeys.length === 0 || optionKeys.some((key) => key === '')) {
      fieldErrors[row.id] = 'knowledge.schema.optionKeyRequired';
    } else if (new Set(optionKeys).size !== optionKeys.length) {
      fieldErrors[row.id] = 'knowledge.schema.optionKeyDuplicate';
    }
  }

  const formError = rows.length > MAX_SCHEMA_FIELDS ? ('knowledge.schema.tooManyFields' as const) : null;

  return {
    fieldErrors,
    formError,
    valid: Object.keys(fieldErrors).length === 0 && formError === null,
  };
}

// --- change detection --------------------------------------------------------

/**
 * Whether two schemas are the SAME schema, compared semantically (key order inside an object is
 * irrelevant, an absent `options` reads as none).
 *
 * This decides whether a PATCH carries `metadata_schema` at all — which matters twice over:
 * an absent key means UNCHANGED on the server, and TOUCHING the schema is what escalates the
 * request from the `update` ability to the `manage` one. A member renaming a base must not be
 * refused because the form re-serialized an untouched schema in a different key order.
 */
export function schemasEqual(
  a: KnowledgeSchemaField[] | null | undefined,
  b: KnowledgeSchemaField[] | null | undefined,
): boolean {
  const left = a ?? [];
  const right = b ?? [];
  if (left.length !== right.length) return false;
  return left.every((field, i) => fieldsEqual(field, right[i]));
}

function fieldsEqual(a: KnowledgeSchemaField, b: KnowledgeSchemaField): boolean {
  return (
    a.key === b.key && (a.label ?? '') === (b.label ?? '') && descriptorsEqual(a.descriptor, b.descriptor)
  );
}

function descriptorsEqual(a?: VariableDescriptor, b?: VariableDescriptor): boolean {
  if (!a || !b) return a === b;
  if (a.base !== b.base || !!a.nullable !== !!b.nullable || !!a.array !== !!b.array) return false;

  const aOptions = a.options ?? [];
  const bOptions = b.options ?? [];
  if (aOptions.length !== bOptions.length) return false;
  if (!aOptions.every((option, i) => option.key === bOptions[i].key && (option.label ?? '') === (bOptions[i].label ?? ''))) {
    return false;
  }

  const aFields = a.fields ?? [];
  const bFields = b.fields ?? [];
  if (aFields.length !== bFields.length) return false;
  return aFields.every((field, i) => fieldsEqual(field, bFields[i]));
}
