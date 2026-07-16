// Answer-row projection for the submission preview drawer's `diff` mode.
//
// A run captures a NESTED snapshot of a submission's answers
// (`trigger_payload.fields`, same shape as `FormSubmission.data`). To show it as
// a read-only, labelled form — NOT a raw JSON blob — we walk the form's element
// tree IN ORDER and emit one row per input field: its schema label + the raw
// snapshot/current values (the component formats them by type). Sections nest
// their children under the section id, grids flatten to the SAME level, and
// repeaters are arrays of per-instance objects — mirroring ./submissionData.
//
// Any snapshot/current key with NO matching schema element (e.g. a field deleted
// from the form since the run) is NOT dropped: it falls back to a raw key+value
// row. With an empty `content` (schema absent / still loading) every key becomes
// a fallback row, so the drawer degrades to the pre-schema raw view.
import type { FormElement } from './types';

export interface AnswerRow {
  /** Stable identity for `v-for` (encodes the field's path + repeater index). */
  key: string;
  /** The field's schema label, or the raw key for an unknown (deleted) field. */
  label: string;
  /** The schema element (drives type-aware formatting), or null for a fallback. */
  element: FormElement | null;
  /** Raw snapshot value for this field (may be undefined). */
  snapshotRaw: unknown;
  /** Raw current value for this field (may be undefined). */
  currentRaw: unknown;
  /** True when the field maps to a known schema element. */
  known: boolean;
}

type Nested = Record<string, unknown>;

const CONTENT_TYPES = new Set<FormElement['type']>(['heading', 'text_block', 'divider']);

/** Coerce a nested value to a plain object (a missing/array value → {}). */
function toObj(value: unknown): Nested {
  return value && typeof value === 'object' && !Array.isArray(value) ? (value as Nested) : {};
}

/** Field label, suffixed with the repeater instance path when nested (e.g. `#2`). */
function labelFor(el: FormElement, instance: number[]): string {
  const base = el.config.label || el.id;
  return instance.length ? `${base} #${instance.join('.')}` : base;
}

function walk(
  els: FormElement[],
  snap: Nested,
  cur: Nested | null,
  rows: AnswerRow[],
  consumed: Set<string>,
  keyPrefix: string,
  instance: number[],
): void {
  for (const el of els) {
    if (CONTENT_TYPES.has(el.type)) continue;

    // Grid columns flatten to the SAME object level → share consumed + prefix.
    if (el.type === 'grid') {
      for (const col of el.config.columns ?? []) {
        if (col.element) walk([col.element], snap, cur, rows, consumed, keyPrefix, instance);
      }
      continue;
    }

    consumed.add(el.id);

    if (el.type === 'section') {
      const sSnap = toObj(snap[el.id]);
      const sCur = cur ? toObj(cur[el.id]) : null;
      const childConsumed = new Set<string>();
      walk(el.config.children ?? [], sSnap, sCur, rows, childConsumed, `${keyPrefix}${el.id}.`, instance);
      addLeftovers(sSnap, sCur, childConsumed, rows, `${keyPrefix}${el.id}.`);
      continue;
    }

    if (el.type === 'repeater') {
      const snapArr = Array.isArray(snap[el.id]) ? (snap[el.id] as unknown[]) : [];
      const curArr = cur && Array.isArray(cur[el.id]) ? (cur[el.id] as unknown[]) : null;
      const count = Math.max(snapArr.length, curArr?.length ?? 0);
      for (let i = 0; i < count; i += 1) {
        const iSnap = toObj(snapArr[i]);
        const iCur = curArr ? toObj(curArr[i]) : null;
        const childConsumed = new Set<string>();
        walk(
          el.config.children ?? [],
          iSnap,
          iCur,
          rows,
          childConsumed,
          `${keyPrefix}${el.id}[${i}].`,
          [...instance, i + 1],
        );
        addLeftovers(iSnap, iCur, childConsumed, rows, `${keyPrefix}${el.id}[${i}].`);
      }
      continue;
    }

    // Input leaf (short_text/long_text/select/image/checkbox/number/date/time/url/checklist).
    rows.push({
      key: `${keyPrefix}${el.id}`,
      label: labelFor(el, instance),
      element: el,
      snapshotRaw: snap[el.id],
      currentRaw: cur ? cur[el.id] : undefined,
      known: true,
    });
  }
}

/** Emit raw rows for any keys the schema walk did NOT consume (deleted fields). */
function addLeftovers(
  snap: Nested,
  cur: Nested | null,
  consumed: Set<string>,
  rows: AnswerRow[],
  keyPrefix: string,
): void {
  const keys: string[] = [];
  for (const k of Object.keys(snap)) if (!consumed.has(k) && !keys.includes(k)) keys.push(k);
  if (cur) for (const k of Object.keys(cur)) if (!consumed.has(k) && !keys.includes(k)) keys.push(k);
  for (const k of keys) {
    rows.push({
      key: `${keyPrefix}${k}`,
      label: k,
      element: null,
      snapshotRaw: snap[k],
      currentRaw: cur ? cur[k] : undefined,
      known: false,
    });
  }
}

/**
 * Walk the form `content` against a NESTED submission `snapshot` (and, when
 * loaded, the `current` submission) to produce an ORDERED, labelled list of
 * answer rows — one per input field, grouped by the form's sections/grids/
 * repeaters, with unmatched keys appended as raw fallback rows.
 */
export function buildAnswerRows(
  content: FormElement[],
  snapshot: Nested,
  current: Nested | null,
): AnswerRow[] {
  const rows: AnswerRow[] = [];
  const consumed = new Set<string>();
  const snap = snapshot ?? {};
  const cur = current ?? null;
  walk(content ?? [], snap, cur, rows, consumed, '', []);
  addLeftovers(snap, cur, consumed, rows, '');
  return rows;
}
