// relationLabels.spec — the wording rules, and the one place the client is allowed to own prose.
//
// Two claims are worth more than the rest:
//
//   1. EVERY verb reads both ways, in both languages. The neighbour list and the rail panel show
//      direction as a WORD, never as a reversed arrow, so a missing inverse is not a cosmetic gap —
//      it is a row that reads backwards and asserts the opposite of what is stored.
//   2. SYMMETRY IS DATA. `knows` reads the same both ways on purpose. That has to come from the
//      vocabulary's `symmetric` flag, not from noticing that two strings happen to match, because
//      two verbs can coincide in one language and diverge in another.
import { describe, it, expect, beforeEach } from 'vitest';
import { setLocale } from '../../../../app/i18n';
import {
  RELATION_GROUPS,
  directionFrom,
  entryTypeIcon,
  entryTypeLabel,
  groupedVocabulary,
  pairingVerdict,
  predicateLabel,
  relationEnds,
  relationPredicate,
} from '../relationLabels';
import type { KnowledgeRelationTypeId, KnowledgeRelationVocabularyItem } from '../../types';

/** The fifteen ids the server's `KnowledgeRelationType` enum defines. */
const ALL_TYPES: KnowledgeRelationTypeId[] = [
  'member_of',
  'works_on',
  'knows',
  'created',
  'owns',
  'located_in',
  'participated_in',
  'occurred_during',
  'part_of',
  'is_a',
  'uses',
  'depends_on',
  'precedes',
  'caused',
  'opposes',
];

/** The two the server reports as symmetric (`KnowledgeRelationType::isSymmetric`). */
const SYMMETRIC: KnowledgeRelationTypeId[] = ['knows', 'opposes'];

function vocab(over: Partial<KnowledgeRelationVocabularyItem> = {}): KnowledgeRelationVocabularyItem {
  return {
    id: 'member_of',
    label: 'is a member of',
    inverse_label: 'has member',
    symmetric: false,
    property_keys: ['role'],
    from_types: ['person', 'organization'],
    to_types: ['organization'],
    ...over,
  };
}

describe('every verb is worded both ways, in both languages', () => {
  for (const locale of ['pl', 'en'] as const) {
    it(`has a forward and an inverse label for all fifteen types (${locale})`, () => {
      setLocale(locale);

      for (const type of ALL_TYPES) {
        const forward = predicateLabel(type, 'forward');
        const inverse = predicateLabel(type, 'inverse');

        // Not the key echoed back and not empty — either on screen would mean a user reading a
        // dotted path in the middle of a sentence.
        //
        // Deliberately NOT asserting `!== type`: the English `knows` IS the word "knows", and a
        // check that treated a legitimately identical label as a miss would force the catalog to
        // be worded badly to satisfy the test. The key-path check is what actually distinguishes
        // "translated" from "fell through".
        expect(forward, `${type} forward in ${locale}`).not.toContain('knowledge.relations');
        expect(forward.length, `${type} forward in ${locale}`).toBeGreaterThan(0);

        expect(inverse, `${type} inverse in ${locale}`).not.toContain('knowledge.relations');
        expect(inverse.length, `${type} inverse in ${locale}`).toBeGreaterThan(0);
      }
    });
  }
});

describe('symmetric verbs', () => {
  beforeEach(() => setLocale('en'));

  it('word identically in both directions — which is the point, not an oversight', () => {
    for (const type of SYMMETRIC) {
      expect(predicateLabel(type, 'forward')).toBe(predicateLabel(type, 'inverse'));
    }
  });

  it('reads a symmetric RELATION the same way even when asked for the inverse', () => {
    const knows = {
      relation_type: 'knows' as const,
      label: 'knows',
      inverse_label: 'known by',
      symmetric: true,
    };

    // THE assertion: the flag wins over the server's own inverse string. A symmetric row is stored
    // with the smaller id first — canonical bookkeeping, not a claim about who comes first — so
    // wording it backwards for the second end would invent a direction the data does not have.
    expect(relationPredicate(knows, 'inverse')).toBe(relationPredicate(knows, 'forward'));
  });

  it('still uses the inverse for an ASYMMETRIC relation', () => {
    const member = {
      relation_type: 'member_of' as const,
      label: 'is a member of',
      inverse_label: 'has member',
      symmetric: false,
    };

    expect(relationPredicate(member, 'forward')).not.toBe(relationPredicate(member, 'inverse'));
  });
});

describe('the server label is a FALLBACK, not the source', () => {
  beforeEach(() => setLocale('en'));

  it('prefers the client catalog, because the server renders in the SERVER locale', () => {
    // The API has no idea which language this browser is in — `__()` runs against APP_LOCALE, and
    // nothing syncs the two. A server string on a Polish UI would be an English verb mid-sentence.
    expect(predicateLabel('member_of', 'forward', 'CZLONEK-SERWERA')).toBe('is a member of');
  });

  it('falls back to the server when this build does not know the verb', () => {
    // A sixteenth verb shipped by the backend renders in the server's language rather than as a raw
    // `some_new_verb` — degraded, not broken.
    expect(predicateLabel('some_new_verb', 'forward', 'relates to')).toBe('relates to');
  });

  it('falls back to the RAW id only when there is nothing else at all', () => {
    expect(predicateLabel('some_new_verb', 'forward')).toBe('some_new_verb');
  });
});

describe('direction is decided by the ends, not by what happens to be loaded', () => {
  const relation = { from_entry_id: 'anna', to_entry_id: 'acme' };

  it('reads forward from the SOURCE end', () => {
    expect(directionFrom(relation, 'anna')).toBe('forward');
  });

  it('reads inverse from the TARGET end', () => {
    expect(directionFrom(relation, 'acme')).toBe('inverse');
  });

  it('reads forward for a bystander and for no viewpoint at all', () => {
    // Inventing an inverse for an entry that is neither end would be worse than plain.
    expect(directionFrom(relation, 'someone-else')).toBe('forward');
    expect(directionFrom(relation, null)).toBe('forward');
  });

  it('reads forward for a SELF relation, which has no other end to turn towards', () => {
    expect(directionFrom({ from_entry_id: 'x', to_entry_id: 'x' }, 'x')).toBe('forward');
  });

  it('orders the ends for the direction, as subject and object', () => {
    const ends = {
      from_entry: { id: 'a', title: 'Anna', slug: 'anna', entry_type: 'person' as const },
      to_entry: { id: 'b', title: 'Acme', slug: 'acme', entry_type: 'organization' as const },
    };

    expect(relationEnds(ends, 'forward').subject?.title).toBe('Anna');
    expect(relationEnds(ends, 'inverse').subject?.title).toBe('Acme');
    expect(relationEnds(ends, 'inverse').object?.title).toBe('Anna');
  });
});

describe('entry types', () => {
  beforeEach(() => setLocale('en'));

  it('names null NEUTRALLY — it is the state of every entry written before types existed', () => {
    expect(entryTypeLabel(null)).toBe('Unspecified');
    // What it must NOT be: anything that reads as a defect.
    expect(entryTypeLabel(null).toLowerCase()).not.toContain('missing');
  });

  it('names all eight server categories', () => {
    for (const type of [
      'person',
      'organization',
      'event',
      'place',
      'product',
      'work',
      'concept',
      'other',
    ]) {
      expect(entryTypeLabel(type)).not.toContain('knowledge.entityType');
    }
  });

  it('gives every category a distinct icon, so a badge row is scannable', () => {
    const icons = ['person', 'organization', 'event', 'place', 'product', 'work', 'concept'].map(
      entryTypeIcon,
    );

    expect(new Set(icons).size).toBe(icons.length);
  });
});

describe('the picker groups', () => {
  it('files every one of the fifteen types exactly once', () => {
    const filed = RELATION_GROUPS.flatMap((group) => group.types);

    expect(new Set(filed).size).toBe(filed.length);
    expect([...filed].sort()).toEqual([...ALL_TYPES].sort());
  });

  it('offers only what the BASE allows', () => {
    const catalog = [vocab({ id: 'member_of' }), vocab({ id: 'knows' }), vocab({ id: 'uses' })];
    const groups = groupedVocabulary(catalog, ['member_of', 'knows']);
    const offered = groups.flatMap((group) => group.items.map((item) => item.id));

    expect(offered.sort()).toEqual(['knows', 'member_of']);
  });

  it('treats a null allow-list as "everything" — the server already resolved it', () => {
    const catalog = [vocab({ id: 'member_of' }), vocab({ id: 'uses' })];

    expect(groupedVocabulary(catalog, null).flatMap((g) => g.items)).toHaveLength(2);
  });

  it('keeps an UNGROUPED verb reachable instead of dropping it', () => {
    // A verb the backend adds must still be pickable before this file learns where to file it.
    // Silently vanishing from the picker would look like the server had never sent it.
    const catalog = [vocab({ id: 'brand_new' as KnowledgeRelationTypeId, label: 'relates to' })];
    const groups = groupedVocabulary(catalog, null);

    expect(groups.at(-1)?.id).toBe('other');
    expect(groups.at(-1)?.items[0].id).toBe('brand_new');
  });

  it('drops groups that end up empty rather than rendering bare headings', () => {
    expect(groupedVocabulary([vocab({ id: 'knows' })], null).map((g) => g.id)).toEqual(['social']);
  });
});

describe('the advisory type check', () => {
  const memberOf = vocab({ from_types: ['person'], to_types: ['organization'] });

  it('approves a pairing the matrix allows', () => {
    expect(pairingVerdict(memberOf, 'person', 'organization')).toBe('ok');
  });

  it('flags a pairing the matrix does not — as UNUSUAL, which the caller renders advisory', () => {
    expect(pairingVerdict(memberOf, 'place', 'person')).toBe('unusual');
  });

  it('has NO OPINION when either end is untyped', () => {
    // This is the whole point of the soft edge, and it mirrors `RelationVocabulary::checkTypes`:
    // every entry written before `entry_type` existed is untyped, and a check that refused on
    // unknown types would light a warning on every correct relation in an old base.
    expect(pairingVerdict(memberOf, null, 'organization')).toBeNull();
    expect(pairingVerdict(memberOf, 'person', null)).toBeNull();
  });

  it('has NO OPINION about `other`, which is the writer saying no class applies', () => {
    expect(pairingVerdict(memberOf, 'other', 'organization')).toBeNull();
    expect(pairingVerdict(memberOf, 'person', 'other')).toBeNull();
  });

  it('has no opinion about a verb it cannot find', () => {
    expect(pairingVerdict(null, 'person', 'organization')).toBeNull();
  });
});
