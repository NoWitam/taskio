// baseIndexState.spec — collapsing a base's `index_summary` census into one badge.
//
// The unit trap this guards: at BASE level the census counts ENTRIES (`total === entries_count`),
// while an ENTRY's own `index.chunks_count` counts CHUNKS. Mixing them prints a fraction whose two
// halves came from different denominators, which is the kind of number a user cannot argue with and
// cannot trust.
//
// The priority order is a product decision, not arithmetic: what does the user need to know FIRST.
// `pending_budget` outranking a partial count matters because nothing is broken there and no retry
// helps — raising the workspace AI cap does — so it must not be reported as progress or as failure.
import { describe, it, expect } from 'vitest';
import { baseIndexState } from '../baseMeta';

const t = (key: string, _d?: string, params?: Record<string, string | number>) =>
  params ? `${key}:${JSON.stringify(params)}` : key;

function base(summary: Record<string, number>, entriesCount?: number) {
  return { index_summary: summary, entries_count: entriesCount } as never;
}

describe('baseIndexState', () => {
  it('returns null for an EMPTY base — "0/0 indexed" is a claim about nothing', () => {
    expect(baseIndexState(base({ total: 0 }), t)).toBeNull();
    expect(baseIndexState(base({}, 0), t)).toBeNull();
    expect(baseIndexState(base({}), t)).toBeNull();
  });

  it('reports `indexed` when every entry is done', () => {
    const state = baseIndexState(base({ total: 5, indexed: 5 }), t);
    expect(state).toEqual({ status: 'indexed', label: 'knowledge.index.indexed' });
  });

  it('reports `partial` WITH the entry-counted fraction', () => {
    const state = baseIndexState(base({ total: 10, indexed: 3, pending: 7 }), t);
    expect(state?.status).toBe('partial');
    expect(state?.label).toBe('knowledge.index.partial:{"done":3,"total":10}');
  });

  it('reports `pending` when nothing has started', () => {
    const state = baseIndexState(base({ total: 4, pending: 4 }), t);
    expect(state?.status).toBe('pending');
  });

  it('reports `indexing` when work is in flight and nothing is done yet', () => {
    const state = baseIndexState(base({ total: 4, indexing: 2, pending: 2 }), t);
    expect(state?.status).toBe('indexing');
  });

  // --- priority ---------------------------------------------------------------
  it('puts FAILED first — it is the only state that needs a human', () => {
    const state = baseIndexState(
      base({ total: 10, indexed: 8, failed: 1, pending: 1, indexing: 1, pending_budget: 1 }),
      t,
    );
    expect(state?.status).toBe('failed');
  });

  it('puts PENDING_BUDGET above progress — no retry helps, so it must not read as progress', () => {
    const state = baseIndexState(
      base({ total: 10, indexed: 5, pending_budget: 5, indexing: 2 }),
      t,
    );
    expect(state?.status).toBe('pending_budget');
  });

  it('does not fold pending_budget into failed — nothing is broken there', () => {
    const state = baseIndexState(base({ total: 3, pending_budget: 3 }), t);
    expect(state?.status).not.toBe('failed');
    expect(state?.status).toBe('pending_budget');
  });

  it('prefers a complete count over an in-flight one', () => {
    // Everything is indexed even though a worker is still nominally running.
    const state = baseIndexState(base({ total: 3, indexed: 3, indexing: 1 }), t);
    expect(state?.status).toBe('indexed');
  });

  it('falls back to entries_count when the summary omits `total`', () => {
    const state = baseIndexState(base({ indexed: 2 }, 4), t);
    expect(state?.label).toBe('knowledge.index.partial:{"done":2,"total":4}');
  });
});
