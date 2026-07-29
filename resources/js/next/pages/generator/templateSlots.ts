// templateSlots — the PURE, testable core of a template's SLOTS panel.
//
// A slot is a DECLARED typed input ({name, description?, descriptor}) the prompt body references
// as `slots.<name>`. This module owns the drift-critical glue the panel + preview share, and the
// CLIENT mirror of the backend TemplateSlotValidator (the server stays authoritative — this only
// blocks the obvious cases early and drives friendly copy):
//   • the reserved reference-root names a slot may not shadow + the safe-identifier rule,
//   • the SLOT-draft shape + factory (local ids for stable v-for keys),
//   • `validateSlotDrafts()` — the client name check (required / invalid / reserved / duplicate),
//   • `slotFieldError()` — the client OBJECT-field check (key invalid / duplicate), mirroring consts,
//   • `slotDraftToDescriptor()` / `slotDraftToWire()` — draft → the shared type-system descriptor,
//   • `catalogSlots()` — the `{name, descriptor}` list posted to `/generator/catalog` + `/preview`,
//   • `slotSampleDefault()` — the default sample value the live preview seeds per slot.
//
// It REUSES the consts descriptor model verbatim (`draftToDescriptor` / `descriptorToDraft` /
// `defaultValueForDescriptor` / `EnumOptionDraft` / `ObjectFieldDraft`), so a slot's type authoring
// and a const's stay one implementation — no parallel type-builder. `object` slots author nested
// FIELDS exactly like an object const; `file` slots emit the FIXED composite descriptor (the
// {id,name,type,size,url} subfields the backend resolves `slots.<name>.<subfield>` against).
import {
  draftRowId,
  draftToDescriptor,
  defaultValueForDescriptor,
  descriptorToDraft,
  emptyFieldDraft,
  emptyOptionDraft,
  isSafeKey,
  type EnumOptionDraft,
  type ObjectFieldDraft,
} from '../variables/consts';
import type {
  CatalogDescriptorField,
  CatalogVariableDescriptor,
  ConstantBase,
  ConstantDescriptor,
} from '../workflows/types';
import type { TemplateCatalogSlot, TemplateSlot } from './types';

/**
 * The reference-root names a slot may NOT take — it would shadow / collide with a resolution
 * root. Mirrors the backend whitelist superset `['trigger','steps','globals','slots']` plus the
 * element-pipeline scope roots. The server stays authoritative; this catches the obvious case.
 */
export const RESERVED_SLOT_NAMES = ['slots', 'globals', 'trigger', 'steps', 'element', 'index'] as const;

/**
 * The AUTHORABLE bases a slot may take, in the order the type Select offers them. `object`
 * authors nested FIELDS (reusing the consts object type-builder); `file` is a FIXED composite
 * (no user-authored fields). `time` is NOT offered. `multi` is authored as `enum` + the `array`
 * toggle. A scalar / enum slot renders exactly through the preview's `TypedLiteralInput`.
 */
export type SlotBase = 'text' | 'number' | 'boolean' | 'date' | 'enum' | 'object' | 'file';
export const SLOT_BASES: SlotBase[] = ['text', 'number', 'boolean', 'date', 'enum', 'object', 'file'];

/** The bases whose value is a STRUCTURAL container (no `array` modifier; array-of-container is deferred). */
export const STRUCTURAL_SLOT_BASES: SlotBase[] = ['object', 'file'];

/**
 * The FIXED file-composite subfields, mirroring `VariableType::fileSubfieldTypes()` — the invariant
 * {id,name,type,size,url} set a `slots.<name>.<subfield>` reference resolves against (all scalars,
 * `size` a number, the rest text). The single FE source for the file descriptor + the preview's
 * sample snapshot, so the two can never disagree on the subfield set or its types.
 */
export const FILE_SUBFIELDS: ReadonlyArray<{ key: string; base: 'text' | 'number' }> = [
  { key: 'id', base: 'text' },
  { key: 'name', base: 'text' },
  { key: 'type', base: 'text' },
  { key: 'size', base: 'number' },
  { key: 'url', base: 'text' },
];

/** The file descriptor's `{key,label,descriptor}` fields (label = key, mirroring the backend). */
function fileDescriptorFields(): CatalogDescriptorField[] {
  return FILE_SUBFIELDS.map(({ key, base }) => ({
    key,
    label: key,
    descriptor: { base, nullable: false, array: false },
  }));
}

/** One editor SLOT row: a local `id` for stable v-for keys + the authored fields. */
export interface SlotDraft {
  id: string;
  name: string;
  description: string;
  base: SlotBase;
  nullable: boolean;
  array: boolean;
  /** Enum options (used only when base === 'enum'; reuses the consts option-row shape). */
  options: EnumOptionDraft[];
  /** Object fields (used only when base === 'object'; reuses the consts field-row shape). */
  fields: ObjectFieldDraft[];
}

/** A fresh empty slot row (defaults to a text slot, with starter enum/object sub-rows). */
export function emptySlotDraft(): SlotDraft {
  return {
    id: draftRowId(),
    name: '',
    description: '',
    base: 'text',
    nullable: false,
    array: false,
    options: [emptyOptionDraft()],
    fields: [emptyFieldDraft()],
  };
}

/** Whether a base is a structural container (object / file) — no `array` modifier is offered. */
export function isStructuralBase(base: SlotBase): boolean {
  return STRUCTURAL_SLOT_BASES.includes(base);
}

/** Whether a stored base is one this panel can author (else it clamps to text). */
function clampBase(base: string): SlotBase {
  return (SLOT_BASES as string[]).includes(base) ? (base as SlotBase) : 'text';
}

/** Project a saved slot onto an editor row (seeding an edit) via the shared descriptor draft. */
export function slotToDraft(slot: TemplateSlot): SlotDraft {
  // A FILE slot has no authorable sub-editor — its subfields are fixed — so it never round-trips
  // through the consts draft (whose authorable bases exclude `file` and would clamp it to text).
  if (slot.descriptor.base === 'file') {
    return {
      id: draftRowId(),
      name: slot.name,
      description: slot.description ?? '',
      base: 'file',
      nullable: !!slot.descriptor.nullable,
      array: !!slot.descriptor.array,
      options: [emptyOptionDraft()],
      fields: [emptyFieldDraft()],
    };
  }
  const draft = descriptorToDraft(slot.descriptor as unknown as ConstantDescriptor);
  return {
    id: draftRowId(),
    name: slot.name,
    description: slot.description ?? '',
    base: clampBase(draft.base),
    nullable: draft.nullable,
    array: draft.array,
    options: draft.options,
    fields: draft.fields,
  };
}

/**
 * Build the shared type-system descriptor for a slot draft. A `file` slot emits the FIXED
 * composite descriptor (its subfields are backend-fixed); every other base reuses the consts
 * `draftToDescriptor` (scalar / enum options / object fields), so a slot's type and a const's
 * stay one implementation. A structural base never carries the `array` modifier (deferred).
 */
export function slotDraftToDescriptor(draft: SlotDraft): CatalogVariableDescriptor {
  if (draft.base === 'file') {
    return {
      base: 'file',
      nullable: draft.nullable,
      array: false,
      fields: fileDescriptorFields(),
    };
  }
  return draftToDescriptor({
    base: draft.base as ConstantBase,
    nullable: draft.nullable,
    array: isStructuralBase(draft.base) ? false : draft.array,
    options: draft.options,
    fields: draft.fields,
  }) as unknown as CatalogVariableDescriptor;
}

/** Project an editor row onto the wire slot ({name, description?, descriptor}), trimming. */
export function slotDraftToWire(draft: SlotDraft): TemplateSlot {
  const slot: TemplateSlot = { name: draft.name.trim(), descriptor: slotDraftToDescriptor(draft) };
  const description = draft.description.trim();
  if (description !== '') slot.description = description;
  return slot;
}

/** The client slot-name error codes (i18n key suffixes under `generator.templates.slots.errors.*`). */
export type SlotErrorCode = 'nameRequired' | 'nameInvalid' | 'nameReserved' | 'nameDuplicate';

/** The client OBJECT-field error codes (i18n key suffixes under `generator.templates.slots.errors.*`). */
export type SlotFieldErrorCode = 'fieldKeyInvalid' | 'fieldDuplicate';

/**
 * Validate the slot rows client-side (mirrors TemplateSlotValidator): each needs a NON-EMPTY,
 * SAFE-identifier, NON-RESERVED, DISTINCT name. Returns a map of row index → first error code
 * (empty ⇒ all names valid). The server stays authoritative.
 */
export function validateSlotDrafts(drafts: SlotDraft[]): Record<number, SlotErrorCode> {
  const errors: Record<number, SlotErrorCode> = {};
  const seen = new Set<string>();
  drafts.forEach((draft, index) => {
    const name = draft.name.trim();
    if (name === '') {
      errors[index] = 'nameRequired';
      return;
    }
    if (!isSafeKey(name)) {
      errors[index] = 'nameInvalid';
      return;
    }
    if ((RESERVED_SLOT_NAMES as readonly string[]).includes(name)) {
      errors[index] = 'nameReserved';
      return;
    }
    if (seen.has(name)) {
      errors[index] = 'nameDuplicate';
      return;
    }
    seen.add(name);
  });
  return errors;
}

/**
 * Validate an OBJECT slot's declared FIELDS client-side (mirrors the consts object type-builder):
 * every field key must be a SAFE identifier and DISTINCT. Returns the first failing code, or null
 * (not an object slot, or all fields valid). The server stays authoritative.
 */
export function slotFieldError(draft: SlotDraft): SlotFieldErrorCode | null {
  if (draft.base !== 'object') return null;
  const keys = draft.fields.map((field) => field.key.trim());
  if (keys.some((key) => !isSafeKey(key))) return 'fieldKeyInvalid';
  if (new Set(keys).size !== keys.length) return 'fieldDuplicate';
  return null;
}

/**
 * Whether a slot draft is structurally usable in the LIVE catalog / preview right now — its name
 * is non-empty / safe / non-reserved / first-seen AND (for an object) its fields all validate. A
 * half-typed / clashing / mid-authored row is skipped, exactly like a function's half-typed arg.
 */
function isUsableSlot(draft: SlotDraft, name: string, seen: Set<string>): boolean {
  return (
    name !== '' &&
    isSafeKey(name) &&
    !(RESERVED_SLOT_NAMES as readonly string[]).includes(name) &&
    !seen.has(name) &&
    slotFieldError(draft) === null
  );
}

/**
 * The `{name, descriptor}` slots posted to `/generator/catalog` + `/generator/preview` — every
 * draft with a usable name + valid fields (dedup, first wins). Keeps the editor's variable picker
 * live with `slots.<name>` (and its object / file subfields) as the user declares them, before the
 * whole form is valid or saved.
 */
export function catalogSlots(drafts: SlotDraft[]): TemplateCatalogSlot[] {
  const seen = new Set<string>();
  const out: TemplateCatalogSlot[] = [];
  for (const draft of drafts) {
    const name = draft.name.trim();
    if (!isUsableSlot(draft, name, seen)) continue;
    seen.add(name);
    out.push({ name, descriptor: slotDraftToDescriptor(draft) });
  }
  return out;
}

/** The default sample FILE snapshot ({id,name,type,size,url}) the live preview seeds for a file slot. */
export function defaultFileValue(): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const { key, base } of FILE_SUBFIELDS) out[key] = base === 'number' ? null : '';
  return out;
}

/**
 * The default sample value the live preview seeds for a slot — a `{id,name,type,size,url}` snapshot
 * for a file, a per-field `{}` for an object, else the base default (`''` / `false` / `[]` / first
 * enum key…), so a fresh preview shows a plausible render. Reuses the consts value-default logic
 * (nullable does NOT force null here; the preview exposes an explicit "no value" toggle).
 */
export function slotSampleDefault(descriptor: CatalogVariableDescriptor): unknown {
  if (!descriptor.array && descriptor.base === 'file') return defaultFileValue();
  return defaultValueForDescriptor(descriptor as unknown as ConstantDescriptor);
}
