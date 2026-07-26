// Unit tests for the PURE consts authoring model (formerly "globals"): the draft ⇆
// descriptor mapping, the CLIENT mirror of the backend ConstantTypeValidator
// (value-vs-descriptor), the key slug + safety rules, and the list summaries. No DOM — pure
// functions only.
import { describe, it, expect, beforeEach } from 'vitest';
import { setLocale, translate as t } from '../../../app/i18n';
import {
  draftToDescriptor,
  descriptorToDraft,
  defaultValueForDescriptor,
  slugifyKey,
  isSafeKey,
  validateValueAgainstDescriptor,
  typeSummary,
  valuePreview,
  newTypeDraft,
  type ConstantTypeDraft,
} from '../consts';

beforeEach(() => setLocale('en'));

/** Build a type draft over the defaults. */
function draft(overrides: Partial<ConstantTypeDraft> = {}): ConstantTypeDraft {
  return { ...newTypeDraft(), ...overrides };
}

describe('slugifyKey + isSafeKey', () => {
  it('slugs a name into a safe underscore key (mirrors Str::slug(name, "_"))', () => {
    expect(slugifyKey('Brand Name')).toBe('brand_name');
    expect(slugifyKey('Café #1')).toBe('cafe_1');
    expect(slugifyKey('  Trailing  ')).toBe('trailing');
  });

  it('flags unsafe keys (leading digit, illegal chars) and accepts safe ones', () => {
    expect(isSafeKey('brand_name')).toBe(true);
    expect(isSafeKey('_x')).toBe(true);
    expect(isSafeKey('1budget')).toBe(false);
    expect(isSafeKey('a-b')).toBe(false);
    expect(isSafeKey('')).toBe(false);
  });
});

describe('draftToDescriptor', () => {
  it('a plain text type → {base,nullable:false,array:false}', () => {
    expect(draftToDescriptor(draft({ base: 'text' }))).toEqual({
      base: 'text',
      nullable: false,
      array: false,
    });
  });

  it('a nullable number → nullable:true', () => {
    expect(draftToDescriptor(draft({ base: 'number', nullable: true }))).toEqual({
      base: 'number',
      nullable: true,
      array: false,
    });
  });

  it('an enum → options {key,label} with label falling back to the key', () => {
    const descriptor = draftToDescriptor(
      draft({
        base: 'enum',
        options: [
          { id: 'a', key: 'open', label: 'Open ticket' },
          { id: 'b', key: 'done', label: '' },
        ],
      }),
    );
    expect(descriptor).toEqual({
      base: 'enum',
      nullable: false,
      array: false,
      options: [
        { key: 'open', label: 'Open ticket' },
        { key: 'done', label: 'done' },
      ],
    });
  });

  it('an array<text> → array:true', () => {
    expect(draftToDescriptor(draft({ base: 'text', array: true }))).toEqual({
      base: 'text',
      nullable: false,
      array: true,
    });
  });

  it('an object → scalar child descriptors', () => {
    const descriptor = draftToDescriptor(
      draft({
        base: 'object',
        fields: [{ id: 'f', key: 'city', label: 'City', base: 'text' }],
      }),
    );
    expect(descriptor).toEqual({
      base: 'object',
      nullable: false,
      array: false,
      fields: [{ key: 'city', label: 'City', descriptor: { base: 'text', nullable: false, array: false } }],
    });
  });
});

describe('descriptorToDraft (edit hydration)', () => {
  it('round-trips an enum descriptor into option rows', () => {
    const back = descriptorToDraft({
      base: 'enum',
      nullable: false,
      array: false,
      options: [{ key: 'a', label: 'A' }],
    });
    expect(back.base).toBe('enum');
    expect(back.options.map((o) => ({ key: o.key, label: o.label }))).toEqual([{ key: 'a', label: 'A' }]);
  });
});

describe('validateValueAgainstDescriptor (client mirror of the backend)', () => {
  const D = (base: string, extra = {}) => ({ base, nullable: false, array: false, ...extra }) as never;

  it('text: a string passes, a non-string fails', () => {
    expect(validateValueAgainstDescriptor(D('text'), 'hi')).toBeNull();
    expect(validateValueAgainstDescriptor(D('text'), 5)).toBe('notText');
  });

  it('number: a numeric value passes, a non-numeric one is BLOCKED', () => {
    expect(validateValueAgainstDescriptor(D('number'), 5000)).toBeNull();
    expect(validateValueAgainstDescriptor(D('number'), 'abc')).toBe('notNumber');
  });

  it('null is required unless the type is nullable', () => {
    expect(validateValueAgainstDescriptor(D('text'), null)).toBe('required');
    expect(validateValueAgainstDescriptor(D('text', { nullable: true }), null)).toBeNull();
  });

  it('enum: a member passes, a value OUTSIDE the options is BLOCKED', () => {
    const enumD = D('enum', { options: [{ key: 'open', label: 'Open' }, { key: 'done', label: 'Done' }] });
    expect(validateValueAgainstDescriptor(enumD, 'open')).toBeNull();
    expect(validateValueAgainstDescriptor(enumD, 'nope')).toBe('notEnum');
  });

  it('boolean + date leaves', () => {
    expect(validateValueAgainstDescriptor(D('boolean'), true)).toBeNull();
    expect(validateValueAgainstDescriptor(D('boolean'), 'yes')).toBe('notBoolean');
    expect(validateValueAgainstDescriptor(D('date'), '2026-07-22')).toBeNull();
    expect(validateValueAgainstDescriptor(D('date'), '')).toBe('notDate');
  });

  it('array<text>: a list of strings passes; a non-list / a bad element is BLOCKED', () => {
    const arr = D('text', { array: true });
    expect(validateValueAgainstDescriptor(arr, ['a', 'b'])).toBeNull();
    expect(validateValueAgainstDescriptor(arr, 'a')).toBe('notList');
    expect(validateValueAgainstDescriptor(arr, ['a', 5])).toBe('element');
  });

  it('object: matching fields pass; a bad field is BLOCKED', () => {
    const obj = D('object', {
      fields: [{ key: 'city', label: 'City', descriptor: { base: 'text', nullable: false, array: false } }],
    });
    expect(validateValueAgainstDescriptor(obj, { city: 'Warsaw' })).toBeNull();
    expect(validateValueAgainstDescriptor(obj, { city: 5 })).toBe('field');
    expect(validateValueAgainstDescriptor(obj, 'nope')).toBe('notObject');
  });
});

describe('defaultValueForDescriptor', () => {
  it('array → [] ; object → per-field defaults ; enum → first key ; boolean → false ; text → ""', () => {
    expect(defaultValueForDescriptor(draftToDescriptor(draft({ base: 'text', array: true })))).toEqual([]);
    expect(defaultValueForDescriptor(draftToDescriptor(draft({ base: 'text' })))).toBe('');
    expect(defaultValueForDescriptor(draftToDescriptor(draft({ base: 'boolean' })))).toBe(false);
    expect(
      defaultValueForDescriptor(
        draftToDescriptor(draft({ base: 'enum', options: [{ id: 'a', key: 'x', label: 'X' }] })),
      ),
    ).toBe('x');
    expect(
      defaultValueForDescriptor(
        draftToDescriptor(draft({ base: 'object', fields: [{ id: 'f', key: 'n', label: 'N', base: 'number' }] })),
      ),
    ).toEqual({ n: null });
  });
});

describe('typeSummary + valuePreview (list rendering)', () => {
  it('summarizes the type', () => {
    expect(typeSummary(draftToDescriptor(draft({ base: 'text' })), t)).toBe('Text');
    expect(typeSummary(draftToDescriptor(draft({ base: 'text', array: true })), t)).toBe('List of Text');
    expect(
      typeSummary(draftToDescriptor(draft({ base: 'enum', options: [{ id: 'a', key: 'x', label: 'X' }, { id: 'b', key: 'y', label: 'Y' }] })), t),
    ).toBe('Choice (2)');
    expect(typeSummary(draftToDescriptor(draft({ base: 'text', nullable: true })), t)).toBe('Text · optional');
  });

  it('previews the value compactly', () => {
    expect(valuePreview(draftToDescriptor(draft({ base: 'text', nullable: true })), null, t)).toBe('—');
    expect(valuePreview(draftToDescriptor(draft({ base: 'boolean' })), true, t)).toBe('Yes');
    expect(
      valuePreview(
        draftToDescriptor(draft({ base: 'enum', options: [{ id: 'a', key: 'open', label: 'Open ticket' }] })),
        'open',
        t,
      ),
    ).toBe('Open ticket');
    expect(valuePreview(draftToDescriptor(draft({ base: 'text', array: true })), ['a', 'b'], t)).toBe('a, b');
  });
});
