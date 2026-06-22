// Unit tests for the submission data <-> field mapping (structure / flatten).
import { describe, expect, it } from 'vitest';
import { structureFormData, flattenFormData } from '../submissionData';
import type { FormElement } from '../types';

function field(id: string, type: FormElement['type'], extra: Record<string, unknown> = {}): FormElement {
  return { id, type, config: { label: id, ...extra } };
}

describe('submission data mapping', () => {
  it('structures leaf fields, skipping empties + content elements', () => {
    const elements: FormElement[] = [
      field('heading', 'heading', { text: 'Hi' }),
      field('name', 'short_text'),
      field('age', 'number'),
      field('empty', 'short_text'),
    ];
    const flat = { name: 'Ada', age: 30, empty: '' };
    expect(structureFormData(flat, elements)).toEqual({ name: 'Ada', age: 30 });
  });

  it('nests section children under the section id', () => {
    const elements: FormElement[] = [
      { id: 'sec', type: 'section', config: { name: 'S', children: [field('city', 'short_text')] } },
    ];
    expect(structureFormData({ city: 'Kraków' }, elements)).toEqual({ sec: { city: 'Kraków' } });
  });

  it('flattens grid columns to the same level', () => {
    const elements: FormElement[] = [
      {
        id: 'grid',
        type: 'grid',
        config: { columns: [
          { width: 50, element: field('first', 'short_text') },
          { width: 50, element: field('last', 'short_text') },
        ] },
      },
    ];
    expect(structureFormData({ first: 'A', last: 'B' }, elements)).toEqual({ first: 'A', last: 'B' });
  });

  it('collects checklist options into an array', () => {
    const elements: FormElement[] = [
      field('tags', 'checklist', { options: [
        { value: 'a', label: 'A' },
        { value: 'b', label: 'B' },
        { value: 'c', label: 'C' },
      ] }),
    ];
    const flat = { tags_a: true, tags_b: false, tags_c: true };
    expect(structureFormData(flat, elements)).toEqual({ tags: ['a', 'c'] });
  });

  it('builds a repeater into an array of per-instance objects', () => {
    const elements: FormElement[] = [
      { id: 'rep', type: 'repeater', config: { name: 'R', min: 1, max: 5, children: [field('val', 'short_text')] } },
    ];
    const flat = { val_1: 'x', val_2: 'y' };
    const out = structureFormData(flat, elements, { rep: 2 });
    expect(out).toEqual({ rep: [{ val: 'x' }, { val: 'y' }] });
  });

  it('flattenFormData round-trips a nested submission back to flat + instances', () => {
    const elements: FormElement[] = [
      field('name', 'short_text'),
      { id: 'sec', type: 'section', config: { name: 'S', children: [field('city', 'short_text')] } },
      field('tags', 'checklist', { options: [{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }] }),
      { id: 'rep', type: 'repeater', config: { name: 'R', min: 1, max: 5, children: [field('val', 'short_text')] } },
    ];
    const nested = { name: 'Ada', sec: { city: 'Kraków' }, tags: ['b'], rep: [{ val: 'x' }, { val: 'y' }] };

    const { flat, instances } = flattenFormData(nested, elements);
    expect(flat).toMatchObject({ name: 'Ada', city: 'Kraków', tags_a: false, tags_b: true, val_1: 'x', val_2: 'y' });
    expect(instances.rep).toBe(2);

    // Re-structuring the flattened data reproduces the original nested payload.
    expect(structureFormData(flat, elements, instances)).toEqual(nested);
  });
});
