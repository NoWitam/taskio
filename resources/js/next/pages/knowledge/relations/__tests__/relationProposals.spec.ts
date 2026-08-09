// relationProposals.spec — grouping, ordering, and the dependency rule.
//
// The rule with real consequences is the LIVE release: `depends_on_draft` is frozen when the run
// generates, so a row that waits on a draft must stop waiting the moment that draft is accepted.
// Without subtracting the accepted set the row stays blocked with a reason that has stopped being
// true, and the reviewer is told to do something they have already done.
import { describe, it, expect } from 'vitest';
import { RELATION_GROUPS } from '../relationLabels';
import {
  blockingDrafts,
  draftTitle,
  groupProposals,
  isBlocked,
  proposalKey,
} from '../relationProposals';
import type { KnowledgeProposedEntity, KnowledgeProposedRelation } from '../../types';

function proposal(over: Partial<KnowledgeProposedRelation> = {}): KnowledgeProposedRelation {
  return {
    kind: 'relation',
    id: null,
    op: 'create',
    from: 'E1',
    to: 'E2',
    relation: null,
    from_title: 'Anna',
    to_title: 'Acme',
    relation_type: 'member_of',
    description: null,
    properties: {},
    valid_from: null,
    valid_to: null,
    depends_on_draft: [],
    key: 'graph:0',
    pair_with: null,
    replaces: null,
    ...over,
  };
}

function entity(over: Partial<KnowledgeProposedEntity> = {}): KnowledgeProposedEntity {
  return {
    id: null,
    handle: 'E1',
    title: 'Anna',
    slug: null,
    entry_type: 'person',
    is_draft: true,
    ...over,
  };
}

describe('grouping is per ENTITY', () => {
  it('collects every operation about one subject into one group', () => {
    // Reviewing relations is checking facts about somebody — "what will be true about Anna" is one
    // glance, where grouping by operation makes the reviewer jump between subjects every row.
    const groups = groupProposals(
      [
        proposal({ from: 'E1', to_title: 'Acme' }),
        proposal({ from: 'E1', to_title: 'Orion', relation_type: 'works_on' }),
        proposal({ from: 'E9', from_title: 'Bartek', to_title: 'Acme' }),
      ],
      [],
    );

    expect(groups).toHaveLength(2);
    expect(groups.find((g) => g.handle === 'E1')?.proposals).toHaveLength(2);
  });

  it('orders operations inside a group: what arrives, what changes, what goes away', () => {
    const groups = groupProposals(
      [
        proposal({ op: 'end', relation: 'R1' }),
        proposal({ op: 'create' }),
        proposal({ op: 'update', relation: 'R2' }),
      ],
      [],
    );

    expect(groups[0].proposals.map((p) => p.op)).toEqual(['create', 'update', 'end']);
  });

  it('orders the groups alphabetically, so a person is where you last saw them', () => {
    const groups = groupProposals(
      [
        proposal({ from: 'E2', from_title: 'Zofia' }),
        proposal({ from: 'E1', from_title: 'Anna' }),
      ],
      [],
    );

    // Deliberately NOT by count: "most relations first" moves the whole list every time a
    // refinement changes one number.
    expect(groups.map((g) => g.title)).toEqual(['Anna', 'Zofia']);
  });

  it('marks a group whose subject is itself a DRAFT', () => {
    const groups = groupProposals([proposal({ from: 'E1' })], [entity({ handle: 'E1' })]);

    expect(groups[0]).toMatchObject({ isDraft: true, entryType: 'person' });
  });

  it('does not lose an operation whose subject end is unnamed', () => {
    // An operation the reviewer cannot see is one they cannot approve or reject — and it would
    // still be counted in the total above the list.
    const groups = groupProposals([proposal({ from: null, from_title: null })], []);

    expect(groups).toHaveLength(1);
    expect(groups[0].proposals).toHaveLength(1);
  });

  it('gives every proposal a UNIQUE key, including two ends of the same relation', () => {
    const proposals = [
      proposal({ op: 'end', relation: 'R1' }),
      proposal({ op: 'end', relation: 'R1' }),
    ];
    const keys = proposals.map((p, i) => proposalKey(p, i));

    // A duplicate key silently drops a row from the review.
    expect(new Set(keys).size).toBe(2);
  });

  it('distinguishes two DIFFERENT verbs between the same pair', () => {
    const a = proposalKey(proposal({ relation_type: 'works_on' }), 0);
    const b = proposalKey(proposal({ relation_type: 'created' }), 0);

    expect(a).not.toBe(b);
  });
});

describe('the draft dependency', () => {
  const waiting = proposal({ depends_on_draft: ['E1', 'E2'] });

  it('blocks a relation whose end is a draft nobody has accepted', () => {
    expect(isBlocked(waiting, new Set())).toBe(true);
    expect(blockingDrafts(waiting, new Set())).toEqual(['E1', 'E2']);
  });

  it('RELEASES the block as each draft is accepted — the list is frozen, the state is not', () => {
    // THE assertion. `depends_on_draft` still names E1 after E1 is published; without subtracting
    // the accepted set the row stays disabled telling the reviewer to do what they just did.
    expect(blockingDrafts(waiting, new Set(['E1']))).toEqual(['E2']);
    expect(isBlocked(waiting, new Set(['E1', 'E2']))).toBe(false);
  });

  it('never blocks a relation between two entries that already exist', () => {
    // The common case, and the reason this panel is not a card in the draft board.
    expect(isBlocked(proposal(), new Set())).toBe(false);
  });

  it('names the blocking draft by TITLE, falling back to the handle rather than to blank', () => {
    expect(draftTitle('E1', [entity({ handle: 'E1', title: 'Anna Kowalska' })])).toBe('Anna Kowalska');
    // "Waiting for the entry «»" looks broken; an opaque code at least says something is missing.
    expect(draftTitle('E7', [])).toBe('E7');
  });
});

describe('the picker groups stay a complete partition', () => {
  it('files each verb exactly once', () => {
    const filed = RELATION_GROUPS.flatMap((group) => group.types);

    expect(new Set(filed).size).toBe(filed.length);
  });
});
