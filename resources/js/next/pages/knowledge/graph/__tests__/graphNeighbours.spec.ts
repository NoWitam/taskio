// graphNeighbours.spec — the LIST half of the graph (D9).
//
// The canvas is aria-hidden, so these rows are the accessible content. What is asserted here is
// exactly what that makes load-bearing: one row per neighbour (never one per edge), the strongest
// relation wins, and the grouping order matches the legend.
import { describe, it, expect } from 'vitest';
import { groupNeighbours, neighboursOf } from '../graphNeighbours';
import { layoutKnowledgeGraph } from '../knowledgeGraphLayout';
import type { KnowledgeGraphData } from '../../types';

function payload(): KnowledgeGraphData {
  return {
    center: 'c',
    nodes: [
      { id: 'c', slug: 'centrum', title: 'Centrum', status: 'approved', is_stale: false, degree: 0, distance: 0 },
      { id: 'w', slug: 'wiki', title: 'Wiki', status: 'approved', is_stale: false, degree: 0, distance: 1 },
      { id: 's', slug: 'sim', title: 'Similar', status: 'draft', is_stale: true, degree: 0, distance: 1 },
    ],
    edges: [
      { id: 'e1', from: 'c', to: 'w', source: 'wikilink', score: null, evidence: null, dismissed: false },
      // The SAME pair, joined twice: a written link and a machine proposal.
      { id: 'e2', from: 'c', to: 'w', source: 'similarity', score: 0.91, evidence: null, dismissed: false },
      { id: 'e3', from: 's', to: 'c', source: 'similarity', score: 0.88, evidence: { chunks: [1, 2] }, dismissed: false },
    ],
    ghosts: [{ target_slug: 'brakuje', from_ids: ['c'], count: 3 }],
    truncated: { hidden_nodes: 0, hidden_edges: 0 },
  };
}

describe('graphNeighbours', () => {
  it('emits ONE row per neighbour, keeping the strongest relation', () => {
    const rows = neighboursOf(layoutKnowledgeGraph(payload()), 'c');
    const wiki = rows.filter((row) => row.id === 'w');

    expect(wiki).toHaveLength(1);
    expect(wiki[0].group).toBe('wikilink'); // a written link outranks a guessed one
  });

  it('carries everything a dot encodes: score, status, staleness, ghost count', () => {
    const rows = neighboursOf(layoutKnowledgeGraph(payload()), 'c');
    const similar = rows.find((row) => row.id === 's');
    const ghost = rows.find((row) => row.isGhost);

    expect(similar).toMatchObject({ group: 'similarity', score: 0.88, status: 'draft', isStale: true, linkId: 'e3' });
    expect(ghost).toMatchObject({ group: 'ghost', isGhost: true, ghostCount: 3, entryId: null, linkId: null });
  });

  it('groups in legend order and drops empty groups', () => {
    const rows = neighboursOf(layoutKnowledgeGraph(payload()), 'c');
    expect(groupNeighbours(rows).map((bucket) => bucket.group)).toEqual(['wikilink', 'similarity', 'ghost']);
  });

  it('lists every drawn node when there is no focus (the overview)', () => {
    const data = { ...payload(), center: null };
    const rows = neighboursOf(layoutKnowledgeGraph(data), null);

    expect(rows.filter((row) => row.group === 'entry')).toHaveLength(3);
    expect(rows.some((row) => row.group === 'ghost')).toBe(true);
  });

  it('gives a ghost no link id — there is no single row to dismiss', () => {
    const rows = neighboursOf(layoutKnowledgeGraph(payload()), 'c');
    expect(rows.filter((row) => row.isGhost).every((row) => row.linkId === null)).toBe(true);
  });
});

// ------------------------------------------------------------------------------------------------
// TYPED RELATIONS — the ONE documented exception to "one row per neighbour".
//
// The collapse above is right for the four soft layers: "similar AND mentioned" is still one
// neighbourhood, and a second row would make it look bigger than it is. It is WRONG for typed
// relations, because a relation carries one of fifteen meanings and the same pair can legitimately
// hold several — Anna `works_on` Orion and Anna `created` Orion are two different facts. Collapsing
// them shows one and destroys the other with no trace that anything was hidden.
//
// This is pinned because the natural "simplify" refactor is to send everything back through
// `betterRelation`. That would look like tidying and would silently lose data.

/** Two relations between the same pair, plus a soft edge to prove the two rules coexist. */
function relationPayload(): KnowledgeGraphData {
  const node = (id: string, slug: string, entryType: string | null = null) => ({
    id,
    slug,
    title: slug === 'anna' ? 'Anna' : slug === 'orion' ? 'Orion' : slug,
    entry_type: entryType,
    status: 'approved' as const,
    is_stale: false,
    degree: 0,
    distance: id === 'anna' ? 0 : 1,
  });

  const relation = (
    id: string,
    to: string,
    type: string,
    over: Record<string, unknown> = {},
  ) => ({
    id,
    kind: 'relation' as const,
    from: 'anna',
    to,
    source: null,
    score: null,
    evidence: null,
    dismissed: false,
    can_be_dismissed: false,
    relation_type: type,
    label: type,
    inverse_label: `${type}-inverse`,
    symmetric: false,
    description: null,
    properties: null,
    valid_from: '2024-01-01',
    valid_to: null,
    state: 'active' as const,
    origin: 'human' as const,
    ...over,
  });

  return {
    center: 'anna',
    nodes: [node('anna', 'anna', 'person'), node('orion', 'orion', 'product')],
    edges: [
      relation('r1', 'orion', 'works_on'),
      relation('r2', 'orion', 'created'),
      // An ENDED one between the same pair, so ordering within a neighbour is exercised too.
      relation('r3', 'orion', 'uses', { state: 'ended', valid_to: '2023-06-01' }),
      // And a soft edge between the SAME pair, which must still collapse to a single row.
      {
        id: 'l1',
        kind: 'link' as const,
        from: 'anna',
        to: 'orion',
        source: 'similarity' as const,
        score: 0.9,
        evidence: null,
        dismissed: false,
        can_be_dismissed: true,
      },
    ],
    ghosts: [],
    truncated: { hidden_nodes: 0, hidden_edges: 0 },
  };
}

describe('typed relations are never collapsed (R35)', () => {
  it('gives TWO relations between the same pair TWO rows', () => {
    const rows = neighboursOf(layoutKnowledgeGraph(relationPayload()), 'anna');
    const relations = rows.filter((row) => row.group === 'relation');

    // THE assertion. One row here would mean a user is never told about the second fact.
    expect(relations).toHaveLength(3);
    expect(relations.map((row) => row.relationType).sort()).toEqual(['created', 'uses', 'works_on']);
  });

  it('still collapses the SOFT layers to one row per neighbour, in the same result', () => {
    const rows = neighboursOf(layoutKnowledgeGraph(relationPayload()), 'anna');

    expect(rows.filter((row) => row.group === 'similarity')).toHaveLength(1);
  });

  it('gives each relation row a UNIQUE key — the placement id is no longer one', () => {
    const rows = neighboursOf(layoutKnowledgeGraph(relationPayload()), 'anna');

    // Several rows share `id` on purpose (it is the canvas correlation handle); `rowId` is what
    // makes them addressable, and a duplicate here would be a broken `v-for` key.
    expect(new Set(rows.map((row) => row.rowId)).size).toBe(rows.length);
  });

  it('keeps one neighbour’s relations together, active before ended', () => {
    const rows = neighboursOf(layoutKnowledgeGraph(relationPayload()), 'anna');
    const relations = rows.filter((row) => row.group === 'relation');

    expect(relations.map((row) => row.historical)).toEqual([false, false, true]);
  });

  it('leads the group order — an ASSERTED statement outranks every derived reading', () => {
    const groups = groupNeighbours(neighboursOf(layoutKnowledgeGraph(relationPayload()), 'anna'));

    expect(groups[0].group).toBe('relation');
  });

  it('carries the VERB and its inverse, so the list can say what the edge label says', () => {
    const rows = neighboursOf(layoutKnowledgeGraph(relationPayload()), 'anna');
    const worksOn = rows.find((row) => row.relationType === 'works_on');

    // The whole four-file chain: types → layout → graphNeighbours → list.
    expect(worksOn).toMatchObject({
      label: 'works_on',
      inverseLabel: 'works_on-inverse',
      direction: 'forward',
      validFrom: '2024-01-01',
      relationId: 'r1',
    });
  });

  it('reads the verb BACKWARDS when the focus is the target end', () => {
    const rows = neighboursOf(layoutKnowledgeGraph(relationPayload()), 'orion');

    for (const row of rows.filter((r) => r.group === 'relation')) {
      expect(row.direction).toBe('inverse');
    }
  });

  it('never offers a relation as a DISMISSIBLE link', () => {
    const rows = neighboursOf(layoutKnowledgeGraph(relationPayload()), 'anna');

    // "These are not related" is not the available answer for an assertion — ending it or
    // deleting it are, and both act through `relationId`.
    for (const row of rows.filter((r) => r.group === 'relation')) {
      expect(row.linkId).toBeNull();
      expect(row.canDismiss).toBe(false);
    }
  });

  it('carries the neighbour’s entry TYPE, which the canvas deliberately does not draw', () => {
    const rows = neighboursOf(layoutKnowledgeGraph(relationPayload()), 'anna');

    expect(rows.find((row) => row.group === 'relation')?.entryType).toBe('product');
  });
});
