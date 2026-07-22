// workflowGlobals — the PURE, testable authoring model for workflow GLOBALS
// (Phase 3, frontend). A global is a user-authored, workspace-scoped, typed LITERAL
// constant surfaced as a `globals.<key>` catalog variable. This module owns the
// drift-critical mapping between the editor's form DRAFT and the backend's stored
// `descriptor` + `value`, and the CLIENT mirror of `WorkflowGlobalTypeValidator`
// (the server stays authoritative — this only blocks obviously-wrong input early
// and surfaces friendly copy).
//
// AUTHORABLE bases (mirrors WorkflowGlobalTypeValidator::AUTHORABLE_BASES):
//   text | number | boolean | date | enum | object   (+ orthogonal array / nullable)
// `enum` carries `options:[{key,label}]`; `object` carries `fields:[{key,label,
// descriptor}]`. `file` / `time` are NOT authorable and never offered. Object
// children are kept MINIMAL — scalar bases only (text/number/boolean/date); nested
// object / array / enum children are deferred (see OBJECT_FIELD_BASES).
import type {
  CatalogDescriptorOption,
  WorkflowGlobalBase,
  WorkflowGlobalDescriptor,
  WorkflowGlobalScalarBase,
} from './types';

// --- Authorable vocabularies ------------------------------------------------

/** The six authorable descriptor bases, in the order the type picker offers them. */
export const AUTHORABLE_BASES: WorkflowGlobalBase[] = [
  'text',
  'number',
  'boolean',
  'date',
  'enum',
  'object',
];

/** The scalar bases an OBJECT field child may take (minimal — no nested containers). */
export const OBJECT_FIELD_BASES: WorkflowGlobalScalarBase[] = ['text', 'number', 'boolean', 'date'];

/** A safe `globals.<key>` identifier — mirrors the backend SAFE_KEY regex 1:1. */
export const SAFE_KEY_RE = /^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/;

// --- Draft model (the editor's form state) ----------------------------------

/** One enum option row in the type builder (`id` is a stable v-for key only). */
export interface EnumOptionDraft {
  id: string;
  key: string;
  label: string;
}

/** One object field row in the type builder (scalar child base only). */
export interface ObjectFieldDraft {
  id: string;
  key: string;
  label: string;
  base: WorkflowGlobalScalarBase;
}

/** The full type-builder draft: base + modifiers + the enum / object sub-editors. */
export interface GlobalTypeDraft {
  base: WorkflowGlobalBase;
  nullable: boolean;
  array: boolean;
  options: EnumOptionDraft[];
  fields: ObjectFieldDraft[];
}

let seq = 0;
/** A stable local id for a draft row (never sent to the server). */
export function draftRowId(): string {
  seq += 1;
  return `g_${seq}_${Math.random().toString(36).slice(2, 7)}`;
}

/** An empty enum option row. */
export function emptyOptionDraft(): EnumOptionDraft {
  return { id: draftRowId(), key: '', label: '' };
}

/** An empty object field row (defaults to a text child). */
export function emptyFieldDraft(): ObjectFieldDraft {
  return { id: draftRowId(), key: '', label: '', base: 'text' };
}

/** A fresh draft for a NEW global: a plain text type with starter enum/object rows. */
export function newTypeDraft(): GlobalTypeDraft {
  return {
    base: 'text',
    nullable: false,
    array: false,
    options: [emptyOptionDraft()],
    fields: [emptyFieldDraft()],
  };
}

// --- key slug (mirrors Str::slug(name, '_')) --------------------------------

/**
 * Derive a `globals.<key>` slug from a name — the FE mirror of the backend's
 * `Str::slug($name, '_')`: strip diacritics, lowercase, collapse every run of
 * non-alphanumerics to a single `_`, and trim leading/trailing `_`. The result may
 * still be unsafe (e.g. a leading digit) — `isSafeKey` gates that; the server is
 * authoritative either way.
 */
export function slugifyKey(name: string): string {
  return name
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '');
}

/** Whether a resolved key is a safe `globals.<key>` identifier (backend SAFE_KEY). */
export function isSafeKey(key: string): boolean {
  return SAFE_KEY_RE.test(key);
}

// --- draft <-> descriptor ---------------------------------------------------

/**
 * Build the backend `descriptor` from a type draft. `nullable` / `array` are ALWAYS
 * emitted as explicit booleans (so the stored descriptor stays a well-formed
 * `CatalogVariableDescriptor` the catalog re-reads). Only the base-relevant sub-key
 * is attached (`options` for enum, `fields` for object); an enum option's label
 * falls back to its key, and every object child is a plain scalar descriptor.
 */
export function draftToDescriptor(draft: GlobalTypeDraft): WorkflowGlobalDescriptor {
  const descriptor: WorkflowGlobalDescriptor = {
    base: draft.base,
    nullable: draft.nullable,
    array: draft.array,
  };

  if (draft.base === 'enum') {
    descriptor.options = draft.options.map((option): CatalogDescriptorOption => {
      const key = option.key.trim();
      return { key, label: option.label.trim() || key };
    });
  }

  if (draft.base === 'object') {
    descriptor.fields = draft.fields.map((field) => {
      const key = field.key.trim();
      return {
        key,
        label: field.label.trim() || key,
        descriptor: { base: field.base, nullable: false, array: false },
      };
    });
  }

  return descriptor;
}

/** Hydrate a type draft from a stored descriptor (edit path). */
export function descriptorToDraft(descriptor: WorkflowGlobalDescriptor): GlobalTypeDraft {
  const base = AUTHORABLE_BASES.includes(descriptor.base as WorkflowGlobalBase)
    ? (descriptor.base as WorkflowGlobalBase)
    : 'text';
  return {
    base,
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
    fields:
      descriptor.fields && descriptor.fields.length > 0
        ? descriptor.fields.map((field) => ({
            id: draftRowId(),
            key: field.key,
            label: field.label ?? '',
            base: (OBJECT_FIELD_BASES.includes(field.descriptor?.base as WorkflowGlobalScalarBase)
              ? field.descriptor.base
              : 'text') as WorkflowGlobalScalarBase,
          }))
        : [emptyFieldDraft()],
  };
}

// --- default values ---------------------------------------------------------

/** The default LITERAL for a single (non-array) scalar/enum base — never null. */
export function defaultSingleValue(
  base: WorkflowGlobalBase,
  options: CatalogDescriptorOption[] = [],
): unknown {
  switch (base) {
    case 'number':
      return null; // an empty numeric field; the user must type a number
    case 'boolean':
      return false;
    case 'date':
      return '';
    case 'enum':
      return options[0]?.key ?? '';
    case 'object':
      return {};
    default:
      return '';
  }
}

/**
 * The default LITERAL for a whole descriptor: `[]` for an array, a `{}` populated
 * with per-field defaults for an object, else the single-base default. `nullable`
 * does NOT force null here — the editor exposes an explicit "no value" toggle.
 */
export function defaultValueForDescriptor(descriptor: WorkflowGlobalDescriptor): unknown {
  if (descriptor.array) return [];
  if (descriptor.base === 'object') return defaultObjectValue(descriptor);
  return defaultSingleValue(descriptor.base, descriptor.options ?? []);
}

/** A `{}` value with every declared object field defaulted to its base default. */
export function defaultObjectValue(descriptor: WorkflowGlobalDescriptor): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const field of descriptor.fields ?? []) {
    out[field.key] = defaultSingleValue(field.descriptor.base, field.descriptor.options ?? []);
  }
  return out;
}

// --- value validation (mirrors WorkflowGlobalTypeValidator) -----------------

/**
 * A value-validation failure: a short `code` the UI maps to
 * `workflows.globals.valueError.<code>`. Returns null when the value matches the
 * descriptor. Mirrors the backend's value-vs-descriptor rules (the server remains
 * authoritative; this only blocks the obvious cases + drives friendly copy).
 */
export type ValueErrorCode =
  | 'required'
  | 'notText'
  | 'notNumber'
  | 'notBoolean'
  | 'notDate'
  | 'notEnum'
  | 'notList'
  | 'notObject'
  | 'element'
  | 'field';

function isNumericValue(value: unknown): boolean {
  if (typeof value === 'number') return Number.isFinite(value);
  if (typeof value === 'string' && value.trim() !== '') return !Number.isNaN(Number(value));
  return false;
}

function isParsableDateValue(value: unknown): boolean {
  if (typeof value === 'number') return true;
  if (typeof value !== 'string' || value.trim() === '') return false;
  return !Number.isNaN(Date.parse(value));
}

function isEnumMember(value: unknown, descriptor: WorkflowGlobalDescriptor): boolean {
  if (value === null || typeof value === 'object') return false;
  const keys = (descriptor.options ?? []).map((option) => String(option.key));
  return keys.includes(String(value));
}

/** Type-check a single (non-array) value against its base. */
export function validateSingleValue(
  descriptor: WorkflowGlobalDescriptor,
  value: unknown,
): ValueErrorCode | null {
  switch (descriptor.base) {
    case 'text':
      return typeof value === 'string' ? null : 'notText';
    case 'number':
      return isNumericValue(value) ? null : 'notNumber';
    case 'boolean':
      return typeof value === 'boolean' ? null : 'notBoolean';
    case 'date':
      return isParsableDateValue(value) ? null : 'notDate';
    case 'enum':
      return isEnumMember(value, descriptor) ? null : 'notEnum';
    case 'object':
      return validateObjectValue(descriptor, value);
    default:
      return 'required';
  }
}

/** Type-check an object value against its declared fields. */
function validateObjectValue(
  descriptor: WorkflowGlobalDescriptor,
  value: unknown,
): ValueErrorCode | null {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return 'notObject';
  const declared = new Set<string>();
  for (const field of descriptor.fields ?? []) {
    declared.add(field.key);
    const err = validateValueAgainstDescriptor(
      field.descriptor,
      (value as Record<string, unknown>)[field.key] ?? null,
    );
    if (err) return 'field';
  }
  for (const presentKey of Object.keys(value as Record<string, unknown>)) {
    if (!declared.has(presentKey)) return 'field';
  }
  return null;
}

/**
 * Type-check a LITERAL value against a full descriptor (nullable + array aware).
 * Returns the first failing `ValueErrorCode` or null when valid.
 */
export function validateValueAgainstDescriptor(
  descriptor: WorkflowGlobalDescriptor,
  value: unknown,
): ValueErrorCode | null {
  if (value === null || value === undefined) {
    return descriptor.nullable ? null : 'required';
  }

  if (descriptor.array) {
    if (!Array.isArray(value)) return 'notList';
    const element: WorkflowGlobalDescriptor = { ...descriptor, array: false, nullable: false };
    for (const item of value) {
      if (validateSingleValue(element, item)) return 'element';
    }
    return null;
  }

  return validateSingleValue(descriptor, value);
}

// --- summaries (list rendering) ---------------------------------------------

type TranslateFn = (key: string, defaultValue?: string, params?: Record<string, string | number>) => string;

/** The localized base name (`workflows.globals.base.<base>`). */
export function baseLabel(base: WorkflowGlobalBase, t: TranslateFn): string {
  return t(`workflows.globals.base.${base}`, base);
}

/**
 * A short human TYPE summary for a global (list row): the base name, an "N options"
 * / "N fields" count for enum/object, wrapped in a "list of {type}" for an array and
 * suffixed with a "nullable" note. Localized via `workflows.globals.typeSummary.*`.
 */
export function typeSummary(descriptor: WorkflowGlobalDescriptor, t: TranslateFn): string {
  let core = baseLabel(descriptor.base, t);
  if (descriptor.base === 'enum') {
    core = t('workflows.globals.typeSummary.enum', '{type} ({count})', {
      type: core,
      count: descriptor.options?.length ?? 0,
    });
  } else if (descriptor.base === 'object') {
    core = t('workflows.globals.typeSummary.object', '{type} ({count})', {
      type: core,
      count: descriptor.fields?.length ?? 0,
    });
  }
  let summary = descriptor.array
    ? t('workflows.globals.typeSummary.array', 'List of {type}', { type: core })
    : core;
  if (descriptor.nullable) {
    summary = t('workflows.globals.typeSummary.nullable', '{type} · optional', { type: summary });
  }
  return summary;
}

/** The label for an enum value (its option label), falling back to the raw key. */
function enumValueLabel(value: unknown, descriptor: WorkflowGlobalDescriptor): string {
  const match = (descriptor.options ?? []).find((option) => String(option.key) === String(value));
  return match ? match.label || match.key : String(value);
}

/** A compact preview of a SINGLE (non-array) value. */
function singleValuePreview(
  descriptor: WorkflowGlobalDescriptor,
  value: unknown,
  t: TranslateFn,
): string {
  switch (descriptor.base) {
    case 'boolean':
      return value ? t('common.yes', 'Yes') : t('common.no', 'No');
    case 'enum':
      return enumValueLabel(value, descriptor);
    case 'object': {
      const count = value && typeof value === 'object' ? Object.keys(value as object).length : 0;
      return t('workflows.globals.typeSummary.object', '{type} ({count})', {
        type: baseLabel('object', t),
        count,
      });
    }
    default:
      return String(value ?? '');
  }
}

/**
 * A compact, human VALUE preview for a global (list row). Null → an em-dash; an array
 * → its element previews joined (truncated to a few); an object → an "N fields" count;
 * else the single-value preview. Never returns raw JSON.
 */
export function valuePreview(
  descriptor: WorkflowGlobalDescriptor,
  value: unknown,
  t: TranslateFn,
): string {
  if (value === null || value === undefined) {
    return t('workflows.globals.valuePreview.empty', '—');
  }

  if (descriptor.array) {
    if (!Array.isArray(value) || value.length === 0) {
      return t('workflows.globals.valuePreview.emptyList', 'Empty list');
    }
    const element: WorkflowGlobalDescriptor = { ...descriptor, array: false, nullable: false };
    const shown = value.slice(0, 4).map((item) => singleValuePreview(element, item, t));
    const preview = shown.join(', ');
    return value.length > 4
      ? t('workflows.globals.valuePreview.more', '{preview} +{count} more', {
          preview,
          count: value.length - 4,
        })
      : preview;
  }

  return singleValuePreview(descriptor, value, t);
}
