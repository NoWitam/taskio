// submissionAnswers.spec — the pure answer-row projection behind the submission
// preview drawer's `diff` mode. It walks the form content (sections/grids/
// repeaters → fields) against a NESTED submission snapshot to produce ONE ordered
// row per input field (schema label + raw values), and appends any unmatched key
// (a field deleted from the form) as a raw fallback row so nothing is dropped.
import { describe, it, expect } from 'vitest';
import { buildAnswerRows } from '../submissionAnswers';
import type { FormElement } from '../types';

const field = (id: string, type: FormElement['type'], config: Record<string, unknown> = {}): FormElement =>
  ({ id, type, config } as FormElement);

describe('buildAnswerRows', () => {
  it('walks sections in order and pairs each field with its schema label + raw values', () => {
    const content: FormElement[] = [
      field('sec', 'section', {
        name: 'Section',
        children: [
          field('name', 'short_text', { label: 'Full name' }),
          field('age', 'number', { label: 'Age' }),
        ],
      }),
    ];
    const snapshot = { sec: { name: 'Ann', age: 24 } };
    const current = { sec: { name: 'Ann', age: 25 } };

    const rows = buildAnswerRows(content, snapshot, current);

    expect(rows.map((r) => r.label)).toEqual(['Full name', 'Age']);
    expect(rows[0]).toMatchObject({ label: 'Full name', snapshotRaw: 'Ann', currentRaw: 'Ann', known: true });
    expect(rows[1]).toMatchObject({ label: 'Age', snapshotRaw: 24, currentRaw: 25, known: true });
    expect(rows.every((r) => r.element !== null)).toBe(true);
  });

  it('flattens grid columns to the same object level', () => {
    const content: FormElement[] = [
      field('grid', 'grid', {
        columns: [
          { width: 50, element: field('a', 'short_text', { label: 'A' }) },
          { width: 50, element: field('b', 'short_text', { label: 'B' }) },
        ],
      }),
    ];
    const rows = buildAnswerRows(content, { a: 'x', b: 'y' }, null);
    expect(rows.map((r) => [r.label, r.snapshotRaw])).toEqual([
      ['A', 'x'],
      ['B', 'y'],
    ]);
  });

  it('expands repeater instances, suffixing the label with the instance number', () => {
    const content: FormElement[] = [
      field('rep', 'repeater', {
        children: [field('item', 'short_text', { label: 'Item' })],
      }),
    ];
    const snapshot = { rep: [{ item: 'first' }, { item: 'second' }] };
    const rows = buildAnswerRows(content, snapshot, null);
    expect(rows.map((r) => [r.label, r.snapshotRaw])).toEqual([
      ['Item #1', 'first'],
      ['Item #2', 'second'],
    ]);
  });

  it('appends a raw fallback row for a key with no matching schema element', () => {
    const content: FormElement[] = [field('name', 'short_text', { label: 'Full name' })];
    const rows = buildAnswerRows(content, { name: 'Ann', legacy: 'old' }, null);

    expect(rows).toHaveLength(2);
    const fallback = rows.find((r) => r.label === 'legacy')!;
    expect(fallback).toMatchObject({ label: 'legacy', element: null, snapshotRaw: 'old', known: false });
  });

  it('surfaces a current-only key (a field added since the run) as a fallback row', () => {
    const rows = buildAnswerRows([], { a: 1 }, { a: 1, b: 2 });
    expect(rows.map((r) => r.label).sort()).toEqual(['a', 'b']);
    expect(rows.find((r) => r.label === 'b')).toMatchObject({ currentRaw: 2, known: false });
  });

  it('turns every key into a fallback row when the schema is empty', () => {
    const rows = buildAnswerRows([], { color: 'red', size: 'M' }, null);
    expect(rows.every((r) => !r.known && r.element === null)).toBe(true);
    expect(rows.map((r) => r.label)).toEqual(['color', 'size']);
  });
});
