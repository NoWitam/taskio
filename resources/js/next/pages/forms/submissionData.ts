// Submission data <-> form-field mapping for the "next" Forms module.
//
// The FormViewer holds field values in a FLAT map keyed by element id (a
// repeater instance's child is keyed `${childId}_${i}`, a checklist option is
// `${id}_${value}`). The backend stores/returns a NESTED structure mirroring the
// element tree (verified against the legacy FormViewer + `FormElementType`'s
// JsonSchema builder):
//   • section   → nested object under the section id,
//   • repeater  → array of per-instance objects under the repeater id,
//   • grid      → columns flattened to the SAME level (no nesting),
//   • checklist → array of the checked option values,
//   • leaf input→ its value under the element id,
//   • content   → heading / text_block / divider produce no data.
//
// `structureFormData` (flat → nested) builds the submission payload;
// `flattenFormData` (nested → flat) hydrates the viewer to display/edit an
// existing submission. They are inverses for the common element shapes.
// (Containers nested INSIDE a repeater are an edge case the legacy renderer also
// didn't key uniquely; leaf/checklist repeater children are fully supported.)
import type { FormElement } from './types';

type Flat = Record<string, unknown>;
type Nested = Record<string, unknown>;

function isContent(type: FormElement['type']): boolean {
  return type === 'heading' || type === 'text_block' || type === 'divider';
}

/** Flat key for an element, suffixed with the repeater instance when nested. */
function keyFor(id: string, suffix: string): string {
  return suffix ? `${id}_${suffix}` : id;
}

function buildNested(
  els: FormElement[],
  target: Nested,
  suffix: string,
  flat: Flat,
  repeaterInstances: Record<string, number>,
): void {
  for (const el of els) {
    const c = el.config;
    if (isContent(el.type)) continue;

    if (el.type === 'section') {
      const obj: Nested = {};
      buildNested(c.children ?? [], obj, suffix, flat, repeaterInstances);
      if (Object.keys(obj).length) target[el.id] = obj;
      continue;
    }

    if (el.type === 'grid') {
      for (const col of c.columns ?? []) {
        if (col.element) buildNested([col.element], target, suffix, flat, repeaterInstances);
      }
      continue;
    }

    if (el.type === 'repeater') {
      const baseId = keyFor(el.id, suffix);
      const count = repeaterInstances[baseId] ?? repeaterInstances[el.id] ?? c.min ?? 1;
      const items: Nested[] = [];
      for (let i = 1; i <= count; i += 1) {
        const item: Nested = {};
        buildNested(c.children ?? [], item, String(i), flat, repeaterInstances);
        items.push(item);
      }
      target[el.id] = items;
      continue;
    }

    if (el.type === 'checklist') {
      const k = keyFor(el.id, suffix);
      const selected = (c.options ?? [])
        .filter((o) => !!flat[`${k}_${o.value}`])
        .map((o) => o.value);
      if (selected.length) target[el.id] = selected;
      continue;
    }

    // Leaf input (short_text/long_text/select/number/date/time/url/image/checkbox).
    const value = flat[keyFor(el.id, suffix)];
    if (value !== undefined && value !== null && value !== '') {
      target[el.id] = value;
    }
  }
}

/** FLAT (viewer) → NESTED (submission payload). */
export function structureFormData(
  flat: Flat,
  elements: FormElement[],
  repeaterInstances: Record<string, number> = {},
): Nested {
  const result: Nested = {};
  buildNested(elements, result, '', flat, repeaterInstances);
  return result;
}

function readFlat(
  els: FormElement[],
  source: Nested,
  suffix: string,
  flat: Flat,
  instances: Record<string, number>,
): void {
  for (const el of els) {
    const c = el.config;
    if (isContent(el.type)) continue;

    if (el.type === 'section') {
      const child = (source?.[el.id] as Nested) ?? {};
      readFlat(c.children ?? [], child, suffix, flat, instances);
      continue;
    }

    if (el.type === 'grid') {
      for (const col of c.columns ?? []) {
        if (col.element) readFlat([col.element], source, suffix, flat, instances);
      }
      continue;
    }

    if (el.type === 'repeater') {
      const arr = Array.isArray(source?.[el.id]) ? (source[el.id] as Nested[]) : [];
      instances[keyFor(el.id, suffix)] = Math.max(c.min ?? 1, arr.length);
      arr.forEach((item, idx) => readFlat(c.children ?? [], item ?? {}, String(idx + 1), flat, instances));
      continue;
    }

    if (el.type === 'checklist') {
      const vals = Array.isArray(source?.[el.id]) ? (source[el.id] as unknown[]) : [];
      const k = keyFor(el.id, suffix);
      for (const o of c.options ?? []) {
        flat[`${k}_${o.value}`] = vals.includes(o.value);
      }
      continue;
    }

    const value = source?.[el.id];
    if (value !== undefined) flat[keyFor(el.id, suffix)] = value;
  }
}

/** NESTED (submission) → FLAT (viewer) + the repeater instance counts to seed. */
export function flattenFormData(
  nested: Nested,
  elements: FormElement[],
): { flat: Flat; instances: Record<string, number> } {
  const flat: Flat = {};
  const instances: Record<string, number> = {};
  readFlat(elements, nested ?? {}, '', flat, instances);
  return { flat, instances };
}
