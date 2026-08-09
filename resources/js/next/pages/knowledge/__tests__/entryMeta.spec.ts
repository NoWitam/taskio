// entryMeta.spec — the derivations the reader, the rail and the table all share.
//
// The similarity cases carry the most weight. A similarity edge is stored DIRECTED but means
// something symmetric, so an entry can be the `from` of one row and the `to` of another with the
// same neighbour. Reading only `links` hides half of a mutual relationship; reading both without
// de-duplicating lists the same entry twice. Both failures are invisible until a user notices the
// panel disagrees with itself.
import { describe, it, expect } from 'vitest';
import {
  similarNeighbours,
  mentionNeighbours,
  mentionText,
  outgoingWikilinks,
  backlinkSources,
  ghostSlugs,
  firstParagraph,
  byPosition,
  offSchemaKeys,
  entryIndexLabel,
  entryIndexHint,
  entryIndexHintVariant,
} from '../entryMeta';
import type { KnowledgeEntry, KnowledgeLink } from '../types';

const t = (key: string, _d?: string, params?: Record<string, string | number>) =>
  params ? `${key}:${JSON.stringify(params)}` : key;

function link(over: Partial<KnowledgeLink>): KnowledgeLink {
  return {
    id: 'l1',
    knowledge_base_id: 'b1',
    from_entry_id: 'e1',
    to_entry_id: 'e2',
    target_slug: null,
    source: 'wikilink',
    is_ghost: false,
    score: null,
    evidence: null,
    // The server's own verdict (`KnowledgeLinkSource::isDismissable()`): true for the DERIVED
    // kinds. Defaulted to false here because the fixture defaults to a wikilink.
    can_be_dismissed: false,
    dismissed_at: null,
    created_at: null,
    ...over,
  };
}

function entry(over: Partial<KnowledgeEntry> = {}): KnowledgeEntry {
  return {
    id: 'e1',
    knowledge_base_id: 'b1',
    title: 'Brand voice',
    slug: 'brand-voice',
    aliases: [],
    content: '',
    metadata: {},
    status: 'approved',
    stale_at: null,
    is_stale: false,
    position: 1,
    current_revision_id: 'r1',
    index: { status: 'indexed', chunks_count: 2, needs_indexing: false },
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    can_be_purged: false,
    created_at: null,
    updated_at: null,
    deleted_at: null,
    ...over,
  };
}

describe('similarNeighbours — the union of both directions', () => {
  it('collects similarity edges pointing OUT and IN', () => {
    const e = entry({
      links: [
        link({
          id: 'l1',
          source: 'similarity',
          score: 0.9,
          target: { id: 'e2', title: 'Pricing', slug: 'pricing' },
        }),
      ],
      backlinks: [
        link({
          id: 'l2',
          source: 'similarity',
          score: 0.88,
          source_entry: { id: 'e3', title: 'Refunds', slug: 'refunds' },
        }),
      ],
    });

    const rows = similarNeighbours(e);
    expect(rows.map((r) => r.target.slug)).toEqual(['pricing', 'refunds']);
  });

  it('de-duplicates a MUTUAL edge, keeping the higher score', () => {
    const both = { id: 'e2', title: 'Pricing', slug: 'pricing' };
    const e = entry({
      links: [link({ id: 'l1', source: 'similarity', score: 0.7, target: both })],
      backlinks: [link({ id: 'l2', source: 'similarity', score: 0.93, source_entry: both })],
    });

    const rows = similarNeighbours(e);
    expect(rows).toHaveLength(1);
    expect(rows[0].score).toBe(0.93);
  });

  it('sorts by score, descending', () => {
    const e = entry({
      links: [
        link({ id: 'l1', source: 'similarity', score: 0.5, target: { id: 'a', title: 'A', slug: 'a' } }),
        link({ id: 'l2', source: 'similarity', score: 0.95, target: { id: 'b', title: 'B', slug: 'b' } }),
        link({ id: 'l3', source: 'similarity', score: 0.8, target: { id: 'c', title: 'C', slug: 'c' } }),
      ],
    });
    expect(similarNeighbours(e).map((r) => r.target.slug)).toEqual(['b', 'c', 'a']);
  });

  it('EXCLUDES wikilink edges — those are not suggestions', () => {
    const e = entry({
      links: [link({ id: 'l1', source: 'wikilink', target: { id: 'e2', title: 'P', slug: 'p' } })],
    });
    expect(similarNeighbours(e)).toEqual([]);
  });

  it('keeps a DISMISSED edge, flagged — the row stays so the undo has something to attach to', () => {
    const e = entry({
      links: [
        link({
          id: 'l1',
          source: 'similarity',
          score: 0.9,
          dismissed_at: '2026-01-01T00:00:00Z',
          target: { id: 'e2', title: 'P', slug: 'p' },
        }),
      ],
    });
    const rows = similarNeighbours(e);
    expect(rows).toHaveLength(1);
    expect(rows[0].dismissed).toBe(true);
  });

  it('is empty for a null entry or one with no loaded links', () => {
    expect(similarNeighbours(null)).toEqual([]);
    expect(similarNeighbours(entry())).toEqual([]);
  });
});

// A MENTION (B10) is the opposite of a similarity in every way that matters to these helpers: it
// is DIRECTIONAL (both directions can exist as two rows and must not be folded together), it has
// no score, and its evidence points into the SOURCE entry's text — which only the outgoing side
// can resolve.
describe('mentionText — quoting the mentioning words', () => {
  it('slices the words the offsets point at', () => {
    expect(mentionText('Zobacz Politykę zwrotów w sklepie', { char_start: 7, char_length: 16 })).toBe(
      'Politykę zwrotów',
    );
  });

  it('counts CODE POINTS, matching PHP mb_substr — not UTF-16 units', () => {
    // One astral character before the mention. Slicing the string directly would drift by one and
    // quote the wrong words.
    const content = '🎯 Zobacz Cennik dalej';
    expect(mentionText(content, { char_start: 9, char_length: 6 })).toBe('Cennik');
  });

  it('returns null rather than a guess when there is nothing to slice', () => {
    expect(mentionText(null, { char_start: 0, char_length: 3 })).toBeNull();
    expect(mentionText('', { char_start: 0, char_length: 3 })).toBeNull();
    expect(mentionText('abc', null)).toBeNull();
    expect(mentionText('abc', {})).toBeNull();
    expect(mentionText('abc', { char_start: 'x', char_length: 2 })).toBeNull();
    expect(mentionText('abc', { char_start: 99, char_length: 2 })).toBeNull();
    expect(mentionText('abc', { char_start: 0, char_length: 0 })).toBeNull();
  });

  it('returns null when the slice is only whitespace', () => {
    expect(mentionText('a    b', { char_start: 1, char_length: 3 })).toBeNull();
  });
});

describe('mentionNeighbours — direction is kept, never merged', () => {
  const outgoing = link({
    id: 'm-out',
    source: 'mention',
    can_be_dismissed: true,
    evidence: { char_start: 7, char_length: 6 },
    target: { id: 'e2', title: 'Cennik', slug: 'cennik' },
  });
  const incoming = link({
    id: 'm-in',
    source: 'mention',
    can_be_dismissed: true,
    evidence: { char_start: 3, char_length: 5 },
    source_entry: { id: 'e3', title: 'Zwroty', slug: 'zwroty' },
  });

  it('lists both directions as SEPARATE rows, outgoing first', () => {
    const rows = mentionNeighbours(entry({ content: 'Zobacz Cennik', links: [outgoing], backlinks: [incoming] }));

    expect(rows.map((r) => [r.direction, r.target.slug])).toEqual([
      ['outgoing', 'cennik'],
      ['incoming', 'zwroty'],
    ]);
    // Two edges, two dismissals — folding them would leave one with nothing to act on.
    expect(new Set(rows.map((r) => r.linkId)).size).toBe(2);
  });

  it('quotes the text on the OUTGOING row only', () => {
    const rows = mentionNeighbours(entry({ content: 'Zobacz Cennik', links: [outgoing], backlinks: [incoming] }));

    expect(rows.find((r) => r.direction === 'outgoing')?.text).toBe('Cennik');
    // The incoming mention lives in the OTHER entry's body, which this client does not hold.
    expect(rows.find((r) => r.direction === 'incoming')?.text).toBeNull();
  });

  it('carries the SERVER’s dismissible flag rather than re-deriving it', () => {
    const rows = mentionNeighbours(
      entry({ content: 'Zobacz Cennik', links: [outgoing, link({ id: 'm2', source: 'mention', can_be_dismissed: false, target: { id: 'e9', title: 'X', slug: 'x' } })] }),
    );

    expect(rows.find((r) => r.linkId === 'm-out')?.canDismiss).toBe(true);
    expect(rows.find((r) => r.linkId === 'm2')?.canDismiss).toBe(false);
  });

  it('ignores every other edge kind, and a dismissed one stays with its flag', () => {
    const rows = mentionNeighbours(
      entry({
        links: [
          link({ id: 'w', source: 'wikilink', target: { id: 'e4', title: 'W', slug: 'w' } }),
          link({ id: 's', source: 'similarity', score: 0.9, target: { id: 'e5', title: 'S', slug: 's' } }),
          link({
            id: 'm-dismissed',
            source: 'mention',
            can_be_dismissed: true,
            dismissed_at: '2026-08-01T00:00:00Z',
            target: { id: 'e6', title: 'M', slug: 'm' },
          }),
        ],
      }),
    );

    expect(rows.map((r) => r.linkId)).toEqual(['m-dismissed']);
    expect(rows[0].dismissed).toBe(true);
  });

  it('is empty for an entry with no mentions', () => {
    expect(mentionNeighbours(null)).toEqual([]);
    expect(mentionNeighbours(entry())).toEqual([]);
  });
});

describe('similarNeighbours — the dismissible flag', () => {
  it('passes the server flag through instead of inferring it from `source`', () => {
    const rows = similarNeighbours(
      entry({
        links: [
          link({ id: 's1', source: 'similarity', score: 0.9, can_be_dismissed: true, target: { id: 'e2', title: 'A', slug: 'a' } }),
        ],
      }),
    );
    expect(rows[0].canDismiss).toBe(true);
  });
});

describe('wikilink relations', () => {
  it('lists resolved outgoing links and skips ghosts', () => {
    const e = entry({
      links: [
        link({ id: 'l1', target: { id: 'e2', title: 'Pricing', slug: 'pricing' } }),
        link({ id: 'l2', is_ghost: true, target_slug: 'missing', to_entry_id: null }),
      ],
    });
    expect(outgoingWikilinks(e).map((x) => x.slug)).toEqual(['pricing']);
  });

  it('lists backlink sources', () => {
    const e = entry({
      backlinks: [link({ id: 'l3', source_entry: { id: 'e9', title: 'Home', slug: 'home' } })],
    });
    expect(backlinkSources(e).map((x) => x.slug)).toEqual(['home']);
  });

  it('collects ghost slugs, de-duplicated', () => {
    const e = entry({
      links: [
        link({ id: 'l1', is_ghost: true, target_slug: 'missing', to_entry_id: null }),
        link({ id: 'l2', is_ghost: true, target_slug: 'missing', to_entry_id: null }),
        link({ id: 'l3', is_ghost: true, target_slug: 'other', to_entry_id: null }),
      ],
    });
    expect(ghostSlugs(e)).toEqual(['missing', 'other']);
  });
});

describe('firstParagraph', () => {
  it('skips a leading heading and returns prose', () => {
    expect(firstParagraph('# Title\n\nThe body starts here.')).toBe('The body starts here.');
  });

  it('collapses whitespace', () => {
    expect(firstParagraph('a\n  b   c')).toBe('a b c');
  });

  it('truncates with an ellipsis', () => {
    const long = 'x'.repeat(300);
    const out = firstParagraph(long, 50);
    expect(out).toHaveLength(51);
    expect(out.endsWith('…')).toBe(true);
  });

  it('returns an empty string for empty content', () => {
    expect(firstParagraph('')).toBe('');
    expect(firstParagraph(null)).toBe('');
  });
});

describe('byPosition', () => {
  it('orders by position, then by id — the same total order the server uses', () => {
    const rows = [
      { id: 'b', position: 2 },
      { id: 'a', position: 1 },
      { id: 'c', position: 1 },
    ];
    expect(byPosition(rows).map((r) => r.id)).toEqual(['a', 'c', 'b']);
  });

  it('does not mutate its input', () => {
    const rows = [
      { id: 'b', position: 2 },
      { id: 'a', position: 1 },
    ];
    byPosition(rows);
    expect(rows[0].id).toBe('b');
  });
});

describe('offSchemaKeys', () => {
  it('finds values whose field left the schema (the backend keeps them, so we show them)', () => {
    expect(offSchemaKeys({ a: 1, b: 2, c: 3 }, ['a', 'c'])).toEqual(['b']);
  });

  it('is empty when everything is described', () => {
    expect(offSchemaKeys({ a: 1 }, ['a'])).toEqual([]);
    expect(offSchemaKeys(null, ['a'])).toEqual([]);
  });
});

describe('index labels', () => {
  it('renders `partial` as the bare word — the entry contract has no done/total pair', () => {
    // `index.chunks_count` is the chunks that EXIST; there is no count of how many are embedded,
    // so a fraction here would need a denominator nobody supplied.
    expect(entryIndexLabel('partial', t)).toBe('knowledge.index.partialShort');
  });

  it('maps the remaining states to their keys', () => {
    expect(entryIndexLabel('indexed', t)).toBe('knowledge.index.indexed');
    expect(entryIndexLabel('pending_budget', t)).toBe('knowledge.index.pendingBudget');
    expect(entryIndexLabel(null, t)).toBe('knowledge.index.pending');
  });

  it('gives every non-healthy state an explanation, and the healthy one none', () => {
    expect(entryIndexHint('indexed', t)).toBeNull();
    expect(entryIndexHint('pending', t)).toBe('knowledge.index.pendingHint');
    expect(entryIndexHint('failed', t)).toBe('knowledge.index.failedHint');
    expect(entryIndexHint('pending_budget', t)).toBe('knowledge.index.pendingBudgetHint');
  });

  it('matches the alert severity to the badge severity', () => {
    expect(entryIndexHintVariant('failed')).toBe('danger');
    expect(entryIndexHintVariant('pending_budget')).toBe('warning');
    expect(entryIndexHintVariant('partial')).toBe('warning');
    expect(entryIndexHintVariant('pending')).toBe('info');
  });
});
