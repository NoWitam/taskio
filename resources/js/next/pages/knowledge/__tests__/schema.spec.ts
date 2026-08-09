// Unit tests for the PURE knowledge helpers: the metadata-schema authoring model
// (`schema.ts`), the base card derivations (`baseMeta.ts`) and the status maps
// (`statusMaps.ts`). No Vue, no DOM — these are the drift-critical mappings between the
// builder's rows and the shape the backend stores, so they are pinned on their own.
import { describe, expect, it } from 'vitest';
import {
  MAX_SCHEMA_FIELDS,
  SAFE_KEY_RE,
  SCHEMA_FIELD_BASES,
  draftToSchema,
  emptyFieldDraft,
  emptyOptionDraft,
  isOfferedBase,
  resolvedFieldKey,
  schemaToDraft,
  schemasEqual,
  slugifyFieldKey,
  validateSchemaDraft,
  type SchemaFieldDraft,
} from '../schema';
import { baseSubtitle, charterSummary, formatDate, languageLabel } from '../baseMeta';
import { ENTRY_STATUSES, INDEX_STATUSES, entryStatusMap, indexStatusLabel, indexStatusMap } from '../statusMaps';
import type { KnowledgeBase } from '../types';

/** A translator that echoes the key + params, so a test asserts WHICH key was used. */
const t = (key: string, def?: string, params?: Record<string, string | number>): string =>
  params ? `${key}(${Object.entries(params).map(([k, v]) => `${k}=${v}`).join(',')})` : (def ?? key);

function row(overrides: Partial<SchemaFieldDraft> = {}): SchemaFieldDraft {
  return { ...emptyFieldDraft(), keyTouched: true, key: 'field', label: 'Field', ...overrides };
}

describe('offered bases', () => {
  it('offers exactly the five FLAT bases (no object / file / time)', () => {
    expect(SCHEMA_FIELD_BASES).toEqual(['text', 'number', 'boolean', 'date', 'enum']);
    expect(isOfferedBase('object')).toBe(false);
    expect(isOfferedBase('file')).toBe(false);
    expect(isOfferedBase('enum')).toBe(true);
  });
});

describe('slugifyFieldKey / resolvedFieldKey', () => {
  it('slugs a human label the way the backend slugs a const key', () => {
    expect(slugifyFieldKey('Linia produktowa')).toBe('linia_produktowa');
    expect(slugifyFieldKey('  Kanał — Główny  ')).toBe('kana_g_owny');
    expect(slugifyFieldKey('')).toBe('');
  });

  it('tracks the label until the key is pinned', () => {
    const untouched = row({ keyTouched: false, key: '', label: 'Product line' });
    expect(resolvedFieldKey(untouched)).toBe('product_line');

    const pinned = row({ keyTouched: true, key: 'custom_key', label: 'Product line' });
    expect(resolvedFieldKey(pinned)).toBe('custom_key');
  });

  it('mirrors the backend SAFE_KEY rule', () => {
    expect(SAFE_KEY_RE.test('product_line')).toBe(true);
    expect(SAFE_KEY_RE.test('_x1')).toBe(true);
    expect(SAFE_KEY_RE.test('1product')).toBe(false);
    expect(SAFE_KEY_RE.test('product-line')).toBe(false);
    expect(SAFE_KEY_RE.test('')).toBe(false);
  });
});

describe('draftToSchema', () => {
  it('emits {key,label,descriptor} with the orthogonal flags', () => {
    const schema = draftToSchema([
      row({ key: 'summary', label: 'Summary', base: 'text', nullable: true, array: false }),
    ]);

    expect(schema).toEqual([
      { key: 'summary', label: 'Summary', descriptor: { base: 'text', nullable: true, array: false } },
    ]);
  });

  it('attaches options ONLY for an enum, defaulting a blank choice label to its key', () => {
    const enumRow = row({
      key: 'channel',
      label: 'Channel',
      base: 'enum',
      array: true,
      options: [
        { id: 'o1', key: 'ig', label: 'Instagram' },
        { id: 'o2', key: 'tt', label: '' },
      ],
    });
    const numberRow = row({ key: 'weight', label: 'Weight', base: 'number' });

    const [enumField, numberField] = draftToSchema([enumRow, numberRow]);

    expect(enumField.descriptor).toEqual({
      base: 'enum',
      nullable: false,
      array: true,
      options: [
        { key: 'ig', label: 'Instagram' },
        { key: 'tt', label: 'tt' },
      ],
    });
    expect(numberField.descriptor.options).toBeUndefined();
  });

  it('falls back to the key when the label is blank', () => {
    const [field] = draftToSchema([row({ key: 'topic', label: '   ' })]);
    expect(field.label).toBe('topic');
  });

  it('re-emits an UNSUPPORTED descriptor untouched (never silently rewritten)', () => {
    const original = { base: 'object' as const, nullable: false, array: false, fields: [] };
    const [field] = draftToSchema([row({ key: 'legacy', label: 'Legacy', unsupported: original })]);

    expect(field.descriptor).toBe(original);
  });
});

describe('schemaToDraft', () => {
  it('round-trips an offered field', () => {
    const schema = [
      {
        key: 'channel',
        label: 'Channel',
        descriptor: {
          base: 'enum' as const,
          nullable: true,
          array: true,
          options: [{ key: 'ig', label: 'Instagram' }],
        },
      },
    ];

    const [draft] = schemaToDraft(schema);

    expect(draft.key).toBe('channel');
    // A STORED key is authoritative — re-deriving it from the label would rename a field every
    // entry's metadata is keyed by.
    expect(draft.keyTouched).toBe(true);
    expect(draft.base).toBe('enum');
    expect(draft.nullable).toBe(true);
    expect(draft.array).toBe(true);
    expect(draft.options.map((o) => o.key)).toEqual(['ig']);
    expect(draft.unsupported).toBeUndefined();
    expect(draftToSchema([draft])).toEqual(schema);
  });

  it('marks a base the builder cannot express as unsupported and preserves it end-to-end', () => {
    const schema = [
      { key: 'blob', label: 'Blob', descriptor: { base: 'object' as const, nullable: false, array: false } },
    ];

    const draft = schemaToDraft(schema);

    expect(draft[0].unsupported).toEqual(schema[0].descriptor);
    expect(draftToSchema(draft)).toEqual(schema);
  });

  it('gives an enum without stored options one starter choice row', () => {
    const [draft] = schemaToDraft([
      { key: 'k', label: 'K', descriptor: { base: 'enum', nullable: false, array: false } },
    ]);
    expect(draft.options).toHaveLength(1);
    expect(draft.options[0].key).toBe('');
  });

  it('treats a null/undefined schema as no fields', () => {
    expect(schemaToDraft(null)).toEqual([]);
    expect(schemaToDraft(undefined)).toEqual([]);
  });
});

describe('validateSchemaDraft', () => {
  it('accepts a well-formed schema', () => {
    const result = validateSchemaDraft([row({ key: 'a', label: 'A' }), row({ key: 'b', label: 'B' })]);
    expect(result.valid).toBe(true);
    expect(result.fieldErrors).toEqual({});
    expect(result.formError).toBeNull();
  });

  it('rejects an unsafe key', () => {
    const bad = row({ key: '1bad', label: 'Bad' });
    const result = validateSchemaDraft([bad]);
    expect(result.fieldErrors[bad.id]).toBe('knowledge.schema.keyInvalid');
    expect(result.valid).toBe(false);
  });

  it('marks BOTH halves of a duplicate key', () => {
    const first = row({ key: 'dup', label: 'One' });
    const second = row({ key: 'dup', label: 'Two' });
    const result = validateSchemaDraft([first, second]);

    expect(result.fieldErrors[first.id]).toBe('knowledge.schema.keyDuplicate');
    expect(result.fieldErrors[second.id]).toBe('knowledge.schema.keyDuplicate');
  });

  it('requires a label', () => {
    const bad = row({ key: 'ok', label: '  ' });
    expect(validateSchemaDraft([bad]).fieldErrors[bad.id]).toBe('knowledge.schema.labelRequired');
  });

  it('requires every enum choice to carry a key', () => {
    const bad = row({
      key: 'ch',
      label: 'Channel',
      base: 'enum',
      options: [{ id: 'o1', key: '', label: 'Empty' }],
    });
    expect(validateSchemaDraft([bad]).fieldErrors[bad.id]).toBe('knowledge.schema.optionKeyRequired');
  });

  it('requires enum choice keys to be distinct', () => {
    const bad = row({
      key: 'ch',
      label: 'Channel',
      base: 'enum',
      options: [
        { id: 'o1', key: 'x', label: 'X' },
        { id: 'o2', key: 'x', label: 'Y' },
      ],
    });
    expect(validateSchemaDraft([bad]).fieldErrors[bad.id]).toBe('knowledge.schema.optionKeyDuplicate');
  });

  it('does not re-judge an unsupported row against rules it cannot satisfy', () => {
    const legacy = row({
      key: 'blob',
      label: 'Blob',
      base: 'text',
      unsupported: { base: 'object', nullable: false, array: false },
      options: [emptyOptionDraft()],
    });
    expect(validateSchemaDraft([legacy]).valid).toBe(true);
  });

  it('bounds the field count', () => {
    const rows = Array.from({ length: MAX_SCHEMA_FIELDS + 1 }, (_, i) =>
      row({ key: `f${i}`, label: `F${i}` }),
    );
    const result = validateSchemaDraft(rows);
    expect(result.formError).toBe('knowledge.schema.tooManyFields');
    expect(result.valid).toBe(false);
  });
});

describe('schemasEqual', () => {
  const schema = [
    {
      key: 'channel',
      label: 'Channel',
      descriptor: { base: 'enum' as const, nullable: false, array: false, options: [{ key: 'ig', label: 'IG' }] },
    },
  ];

  it('is true for a semantically identical schema built in a different key order', () => {
    const rebuilt = [
      {
        label: 'Channel',
        key: 'channel',
        descriptor: { array: false, options: [{ label: 'IG', key: 'ig' }], base: 'enum' as const, nullable: false },
      },
    ];
    expect(schemasEqual(schema, rebuilt)).toBe(true);
  });

  it('treats null / undefined / [] as the same empty schema', () => {
    expect(schemasEqual(null, [])).toBe(true);
    expect(schemasEqual(undefined, null)).toBe(true);
  });

  it('is false when anything real changes', () => {
    expect(schemasEqual(schema, [{ ...schema[0], label: 'Kanał' }])).toBe(false);
    expect(
      schemasEqual(schema, [
        { ...schema[0], descriptor: { ...schema[0].descriptor, nullable: true } },
      ]),
    ).toBe(false);
    expect(
      schemasEqual(schema, [
        { ...schema[0], descriptor: { ...schema[0].descriptor, options: [{ key: 'tt', label: 'IG' }] } },
      ]),
    ).toBe(false);
    expect(schemasEqual(schema, [])).toBe(false);
  });
});

describe('baseMeta', () => {
  function base(overrides: Partial<KnowledgeBase> = {}): KnowledgeBase {
    return {
      id: 'b1',
      name: 'Brand',
      description: null,
      charter: null,
      language: 'pl',
      metadata_schema: [],
      creator: null,
      is_owner: true,
      can_be_edited: true,
      can_be_managed: true,
      can_be_deleted: true,
      created_at: null,
      updated_at: null,
      deleted_at: null,
      ...overrides,
    };
  }

  it('takes the charter down to its first sentence', () => {
    expect(charterSummary('This base holds brand rules. It excludes pricing.')).toBe(
      'This base holds brand rules.',
    );
  });

  it('stops at the first line break when the opening line has no terminator', () => {
    expect(charterSummary('What is this base?\nWho is it for?')).toBe('What is this base?');
    expect(charterSummary('Brand voice and tone\nSecond line')).toBe('Brand voice and tone');
  });

  it('trims an over-long single sentence', () => {
    const long = `${'a'.repeat(200)}.`;
    const summary = charterSummary(long, 20);
    expect(summary).toHaveLength(21);
    expect(summary?.endsWith('…')).toBe(true);
  });

  it('returns null for an empty charter', () => {
    expect(charterSummary(null)).toBeNull();
    expect(charterSummary('   ')).toBeNull();
  });

  it('falls back charter → description → "no charter"', () => {
    expect(baseSubtitle(base({ charter: 'Rules of the brand.' }), t)).toBe('Rules of the brand.');
    expect(baseSubtitle(base({ description: 'Short note' }), t)).toBe('Short note');
    expect(baseSubtitle(base(), t)).toBe('knowledge.bases.noCharter');
  });

  it('labels known language tags and passes unknown ones through', () => {
    expect(languageLabel('pl', (key, def) => (key === 'knowledge.language.pl' ? 'Polski' : (def ?? key)))).toBe(
      'Polski',
    );
    expect(languageLabel('pt-BR', (_key, def) => def ?? '')).toBe('PT-BR');
    expect(languageLabel('', t)).toBe('—');
  });

  it('formats an ISO date as dd.mm.yyyy', () => {
    expect(formatDate('2026-07-31T10:00:00Z')).toBe('31.07.2026');
    expect(formatDate(null)).toBe('—');
  });
});

describe('statusMaps', () => {
  it('covers every editorial status with an icon + a label', () => {
    const map = entryStatusMap(t);
    expect(ENTRY_STATUSES).toEqual(['draft', 'proposed', 'approved', 'archived']);
    for (const status of ENTRY_STATUSES) {
      expect(map[status]?.icon).toBeTruthy();
      expect(map[status]?.label).toBe(`knowledge.status.${status}`);
    }
  });

  it('covers every index state with an icon + a label', () => {
    const map = indexStatusMap(t);
    expect(INDEX_STATUSES).toEqual([
      'pending',
      'indexing',
      'indexed',
      'partial',
      'pending_budget',
      'failed',
    ]);
    for (const status of INDEX_STATUSES) {
      expect(map[status]?.icon).toBeTruthy();
      expect(map[status]?.label).toBeTruthy();
    }
    // Only the broken state shouts.
    expect(map.failed?.tone).toBe('solid');
    expect(map.indexed?.tone).toBe('subtle');
  });

  it('counts a partial index only when both numbers are known', () => {
    expect(indexStatusLabel('partial', t, { done: 3, total: 8 })).toBe('knowledge.index.partial(done=3,total=8)');
    expect(indexStatusLabel('partial', t)).toBe('knowledge.index.partial');
    expect(indexStatusLabel('partial', t, { done: 3, total: null })).toBe('knowledge.index.partial');
  });

  it('maps the snake_case budget state onto its camelCase key', () => {
    expect(indexStatusLabel('pending_budget', t)).toBe('knowledge.index.pendingBudget');
    expect(indexStatusLabel('indexed', t)).toBe('knowledge.index.indexed');
  });
});
