// Unit tests for the PURE template-slot model — the client mirror of TemplateSlotValidator + the
// draft <-> wire descriptor projection + the live-catalog slot list + the preview sample defaults.
import { describe, expect, it } from 'vitest';
import {
  catalogSlots,
  emptySlotDraft,
  slotDraftToWire,
  slotFieldError,
  slotSampleDefault,
  slotToDraft,
  validateSlotDrafts,
  type SlotDraft,
} from '../templateSlots';
import type { ObjectFieldDraft } from '../../variables/consts';

function draft(overrides: Partial<SlotDraft> = {}): SlotDraft {
  return { ...emptySlotDraft(), name: 'topic', ...overrides };
}

function field(key: string, base: ObjectFieldDraft['base'] = 'text', label = ''): ObjectFieldDraft {
  return { id: `f_${key}`, key, label, base };
}

describe('validateSlotDrafts', () => {
  it('accepts distinct, safe, non-reserved names', () => {
    const errors = validateSlotDrafts([draft({ name: 'topic' }), draft({ name: 'tone' })]);
    expect(errors).toEqual({});
  });

  it('flags required / invalid / reserved / duplicate names by row index', () => {
    const errors = validateSlotDrafts([
      draft({ name: '' }),
      draft({ name: '1bad' }),
      draft({ name: 'globals' }),
      draft({ name: 'dupe' }),
      draft({ name: 'dupe' }),
    ]);
    expect(errors[0]).toBe('nameRequired');
    expect(errors[1]).toBe('nameInvalid');
    expect(errors[2]).toBe('nameReserved');
    expect(errors[4]).toBe('nameDuplicate');
    // The first `dupe` is valid; only the second collides.
    expect(errors[3]).toBeUndefined();
  });

  it('treats every reference-root name as reserved', () => {
    for (const reserved of ['slots', 'globals', 'trigger', 'steps', 'element', 'index']) {
      expect(validateSlotDrafts([draft({ name: reserved })])[0]).toBe('nameReserved');
    }
  });
});

describe('slotDraftToWire', () => {
  it('projects {name, descriptor}, trimming + omitting an empty description', () => {
    const wire = slotDraftToWire(draft({ name: '  topic  ', description: '   ', base: 'text' }));
    expect(wire).toEqual({ name: 'topic', descriptor: { base: 'text', nullable: false, array: false } });
    expect('description' in wire).toBe(false);
  });

  it('keeps a non-empty description + builds an enum descriptor with options', () => {
    const wire = slotDraftToWire(
      draft({
        name: 'tone',
        description: 'The voice',
        base: 'enum',
        array: true,
        options: [{ id: 'o1', key: 'warm', label: 'Warm' }, { id: 'o2', key: 'cool', label: 'Cool' }],
      }),
    );
    expect(wire.name).toBe('tone');
    expect(wire.description).toBe('The voice');
    expect(wire.descriptor.base).toBe('enum');
    expect(wire.descriptor.array).toBe(true);
    expect(wire.descriptor.options).toEqual([
      { key: 'warm', label: 'Warm' },
      { key: 'cool', label: 'Cool' },
    ]);
  });
});

describe('slotToDraft', () => {
  it('hydrates an editor row from a saved slot (round-trips base/nullable/array)', () => {
    const d = slotToDraft({
      name: 'count',
      description: 'How many',
      descriptor: { base: 'number', nullable: true, array: false },
    });
    expect(d.name).toBe('count');
    expect(d.description).toBe('How many');
    expect(d.base).toBe('number');
    expect(d.nullable).toBe(true);
    expect(d.array).toBe(false);
  });
});

describe('catalogSlots', () => {
  it('emits only usable-named slots as {name, descriptor}, deduped (first wins)', () => {
    const slots = catalogSlots([
      draft({ name: 'topic', base: 'text' }),
      draft({ name: '', base: 'text' }), // half-typed → skipped
      draft({ name: 'globals' }), // reserved → skipped
      draft({ name: 'topic', base: 'number' }), // duplicate → skipped
      draft({ name: 'tone', base: 'boolean' }),
    ]);
    expect(slots.map((s) => s.name)).toEqual(['topic', 'tone']);
    expect(slots[0].descriptor.base).toBe('text');
  });

  it('emits object + file slots, but SKIPS an object whose field key is not yet valid', () => {
    const slots = catalogSlots([
      draft({ name: 'product', base: 'object', fields: [field('name', 'text', 'Name')] }),
      draft({ name: 'broken', base: 'object', fields: [field('')] }), // half-typed field → skipped
      draft({ name: 'attachment', base: 'file' }),
    ]);
    expect(slots.map((s) => s.name)).toEqual(['product', 'attachment']);
    expect(slots[0].descriptor.fields?.[0].key).toBe('name');
    expect(slots[1].descriptor.base).toBe('file');
    expect(slots[1].descriptor.fields?.map((f) => f.key)).toEqual(['id', 'name', 'type', 'size', 'url']);
  });
});

describe('slotSampleDefault', () => {
  it('seeds a plausible base default per descriptor', () => {
    expect(slotSampleDefault({ base: 'text', nullable: false, array: false })).toBe('');
    expect(slotSampleDefault({ base: 'number', nullable: false, array: false })).toBeNull();
    expect(slotSampleDefault({ base: 'boolean', nullable: false, array: false })).toBe(false);
    expect(slotSampleDefault({ base: 'text', nullable: false, array: true })).toEqual([]);
    expect(
      slotSampleDefault({ base: 'enum', nullable: false, array: false, options: [{ key: 'a', label: 'A' }] }),
    ).toBe('a');
  });

  it('seeds an OBJECT as a per-field {} and a FILE as an {id,name,type,size,url} snapshot', () => {
    expect(
      slotSampleDefault({
        base: 'object',
        nullable: false,
        array: false,
        fields: [
          { key: 'name', label: 'Name', descriptor: { base: 'text', nullable: false, array: false } },
          { key: 'price', label: 'Price', descriptor: { base: 'number', nullable: false, array: false } },
        ],
      }),
    ).toEqual({ name: '', price: null });

    expect(slotSampleDefault({ base: 'file', nullable: false, array: false, fields: [] })).toEqual({
      id: '',
      name: '',
      type: '',
      size: null,
      url: '',
    });
  });
});

describe('slotDraftToWire — object', () => {
  it('emits an object descriptor with the declared scalar fields (reusing the consts builder)', () => {
    const wire = slotDraftToWire(
      draft({
        name: 'product',
        base: 'object',
        fields: [field('name', 'text', 'Name'), field('price', 'number', 'Price')],
      }),
    );
    expect(wire.name).toBe('product');
    expect(wire.descriptor.base).toBe('object');
    expect(wire.descriptor.array).toBe(false);
    expect(wire.descriptor.fields).toEqual([
      { key: 'name', label: 'Name', descriptor: { base: 'text', nullable: false, array: false } },
      { key: 'price', label: 'Price', descriptor: { base: 'number', nullable: false, array: false } },
    ]);
  });

  it('forces array off for a structural base even if the stale flag is on', () => {
    const wire = slotDraftToWire(draft({ name: 'product', base: 'object', array: true, fields: [field('name')] }));
    expect(wire.descriptor.array).toBe(false);
  });
});

describe('slotDraftToWire — file', () => {
  it('emits the FIXED file composite descriptor ({id,name,type,size,url}) with no user fields', () => {
    const wire = slotDraftToWire(draft({ name: 'attachment', base: 'file', array: true }));
    expect(wire.descriptor.base).toBe('file');
    // A file is never a list here — the structural array is forced off.
    expect(wire.descriptor.array).toBe(false);
    expect(wire.descriptor.fields?.map((f) => f.key)).toEqual(['id', 'name', 'type', 'size', 'url']);
    expect(wire.descriptor.fields?.find((f) => f.key === 'size')?.descriptor.base).toBe('number');
    expect(wire.descriptor.fields?.find((f) => f.key === 'url')?.descriptor.base).toBe('text');
  });
});

describe('slotFieldError', () => {
  it('is null for a non-object slot', () => {
    expect(slotFieldError(draft({ base: 'text' }))).toBeNull();
    expect(slotFieldError(draft({ base: 'file' }))).toBeNull();
  });

  it('flags an invalid (empty) field key and a duplicate key, mirroring the consts builder', () => {
    expect(slotFieldError(draft({ base: 'object', fields: [field('')] }))).toBe('fieldKeyInvalid');
    expect(slotFieldError(draft({ base: 'object', fields: [field('a'), field('a')] }))).toBe('fieldDuplicate');
  });

  it('accepts distinct safe field keys', () => {
    expect(slotFieldError(draft({ base: 'object', fields: [field('a'), field('b')] }))).toBeNull();
  });
});

describe('slotToDraft — structural bases', () => {
  it('round-trips a FILE slot (base stays file — never clamped to text)', () => {
    const d = slotToDraft({
      name: 'attachment',
      descriptor: { base: 'file', nullable: true, array: false, fields: [] },
    });
    expect(d.base).toBe('file');
    expect(d.nullable).toBe(true);
  });

  it('round-trips an OBJECT slot preserving its declared fields', () => {
    const d = slotToDraft({
      name: 'product',
      descriptor: {
        base: 'object',
        nullable: false,
        array: false,
        fields: [{ key: 'name', label: 'Name', descriptor: { base: 'text', nullable: false, array: false } }],
      },
    });
    expect(d.base).toBe('object');
    expect(d.fields.map((f) => f.key)).toEqual(['name']);
    expect(d.fields[0].label).toBe('Name');
  });
});
