// @vitest-environment happy-dom
// Unit tests for the "next" knowledge store (R3 / B4) — filter serialization (the ONLY two server
// params), cursor reset/append (NO total), the name-sorted in-place reconciliation after
// create/update/delete, the lifecycle endpoints (restore / force), and the error split between a
// failed FIRST page and a failed APPEND. The api client is mocked (no real HTTP).
//
// The PATCH pass-through is asserted explicitly: an absent key means "unchanged" on the server, so
// a store that helpfully filled in `charter` / `metadata_schema` would both risk a wipe and
// escalate the request's required ability from `update` to `manage`.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

import { api } from '../../lib/api';
import {
  useKnowledgeStore,
  serializeEntryFilters,
  serializeFilters,
  serializeGraphFilters,
} from '../knowledge';
import type { KnowledgeBase } from '../../../pages/knowledge/types';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  patch: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
};

function base(overrides: Partial<KnowledgeBase> = {}): KnowledgeBase {
  return {
    id: 'b1',
    name: 'Brand',
    description: null,
    charter: null,
    language: 'pl',
    metadata_schema: [],
    entries_count: 3,
    creator: null,
    is_owner: true,
    can_be_edited: true,
    can_be_managed: true,
    can_be_deleted: true,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    deleted_at: null,
    ...overrides,
  };
}

beforeEach(() => {
  setActivePinia(createPinia());
  vi.clearAllMocks();
});

describe('serializeFilters', () => {
  it('serializes only the two server filters', () => {
    expect(serializeFilters({}).toString()).toBe('');
    expect(serializeFilters({ search: 'brand' }).toString()).toBe('search=brand');
    expect(serializeFilters({ trashed: true }).toString()).toBe('trashed=1');
    expect(serializeFilters({ search: 'a', trashed: true }).toString()).toBe('search=a&trashed=1');
  });

  it('skips an empty search and a false trashed flag', () => {
    expect(serializeFilters({ search: '', trashed: false }).toString()).toBe('');
  });
});

describe('fetchBases', () => {
  it('resets the list + reads the cursor from meta (NO total)', async () => {
    apiMock.get.mockResolvedValueOnce({ data: [base()], meta: { next_cursor: 'c2' } });
    const store = useKnowledgeStore();

    await store.fetchBases({ search: 'br' }, { reset: true });

    expect(apiMock.get).toHaveBeenCalledWith('/knowledge/bases?search=br');
    expect(store.items).toHaveLength(1);
    expect(store.cursor).toBe('c2');
    expect(store.hasMore).toBe(true);
    expect(store.loading).toBe(false);
  });

  it('lists the trash when asked', async () => {
    apiMock.get.mockResolvedValueOnce({ data: [], meta: { next_cursor: null } });
    const store = useKnowledgeStore();

    await store.fetchBases({ trashed: true }, { reset: true });

    expect(apiMock.get).toHaveBeenCalledWith('/knowledge/bases?trashed=1');
  });

  it('appends the next page and carries the cursor', async () => {
    apiMock.get.mockResolvedValueOnce({ data: [base({ id: 'b1', name: 'A' })], meta: { next_cursor: 'c2' } });
    const store = useKnowledgeStore();
    await store.fetchBases({}, { reset: true });

    apiMock.get.mockResolvedValueOnce({ data: [base({ id: 'b2', name: 'B' })], meta: { next_cursor: null } });
    await store.loadMore({});

    expect(apiMock.get).toHaveBeenLastCalledWith('/knowledge/bases?cursor=c2');
    expect(store.items.map((b) => b.id)).toEqual(['b1', 'b2']);
    expect(store.hasMore).toBe(false);
  });

  it('does not append once the last page has been read', async () => {
    apiMock.get.mockResolvedValueOnce({ data: [base()], meta: { next_cursor: null } });
    const store = useKnowledgeStore();
    await store.fetchBases({}, { reset: true });

    await store.loadMore({});

    expect(apiMock.get).toHaveBeenCalledTimes(1);
  });
});

describe('error handling', () => {
  it('flags a failed FIRST page as errored and stops the sentinel', async () => {
    apiMock.get.mockRejectedValueOnce({ response: { data: { message: 'boom' } } });
    const store = useKnowledgeStore();

    await store.fetchBases({}, { reset: true });

    expect(store.errored).toBe(true);
    expect(store.hasMore).toBe(false);
    expect(store.error).toBe('boom');
    expect(store.loading).toBe(false);
  });

  it('flags a failed APPEND separately, keeping the page retryable', async () => {
    apiMock.get.mockResolvedValueOnce({ data: [base()], meta: { next_cursor: 'c2' } });
    const store = useKnowledgeStore();
    await store.fetchBases({}, { reset: true });

    apiMock.get.mockRejectedValueOnce(new Error('offline'));
    await store.loadMore({});

    expect(store.loadMoreErrored).toBe(true);
    expect(store.errored).toBe(false);
    // The cursor + hasMore survive, so the SAME page can be requested again.
    expect(store.cursor).toBe('c2');
    expect(store.hasMore).toBe(true);

    apiMock.get.mockResolvedValueOnce({ data: [base({ id: 'b2', name: 'B' })], meta: { next_cursor: null } });
    await store.retryLoadMore({});

    expect(store.loadMoreErrored).toBe(false);
    expect(store.items.map((b) => b.id)).toEqual(['b1', 'b2']);
  });

  it('falls back to an i18n key when the error carries no message', async () => {
    apiMock.get.mockRejectedValueOnce(new Error('nope'));
    const store = useKnowledgeStore();

    await store.fetchBases({}, { reset: true });

    expect(store.error).toBe('knowledge.common.loadError');
  });
});

describe('mutations reconcile the list (name-sorted)', () => {
  it('createBase inserts in NAME order', async () => {
    const store = useKnowledgeStore();
    apiMock.get.mockResolvedValueOnce({ data: [base({ id: 'b1', name: 'Brand' })], meta: { next_cursor: null } });
    await store.fetchBases({}, { reset: true });

    apiMock.post.mockResolvedValueOnce({ data: base({ id: 'b2', name: 'Api' }) });
    await store.createBase({ name: 'Api', language: 'pl' });

    expect(apiMock.post).toHaveBeenCalledWith('/knowledge/bases', { name: 'Api', language: 'pl' });
    expect(store.items.map((b) => b.name)).toEqual(['Api', 'Brand']);
  });

  it('updateBase PATCHes the payload VERBATIM (an absent key means unchanged)', async () => {
    const store = useKnowledgeStore();
    apiMock.get.mockResolvedValueOnce({ data: [base({ id: 'b1', name: 'Brand' })], meta: { next_cursor: null } });
    await store.fetchBases({}, { reset: true });

    apiMock.patch.mockResolvedValueOnce({ data: base({ id: 'b1', name: 'Marka' }) });
    await store.updateBase('b1', { name: 'Marka' });

    // No charter, no metadata_schema — sending either would risk a wipe AND escalate the
    // required ability from `update` to `manage`.
    expect(apiMock.patch).toHaveBeenCalledWith('/knowledge/bases/b1', { name: 'Marka' });
    expect(store.items[0].name).toBe('Marka');
  });

  it('keeps entries_count when the write response omits it (whenCounted)', async () => {
    const store = useKnowledgeStore();
    apiMock.get.mockResolvedValueOnce({ data: [base({ id: 'b1', entries_count: 7 })], meta: { next_cursor: null } });
    await store.fetchBases({}, { reset: true });

    const withoutCount = base({ id: 'b1', name: 'Marka' });
    delete withoutCount.entries_count;
    apiMock.patch.mockResolvedValueOnce({ data: withoutCount });
    await store.updateBase('b1', { name: 'Marka' });

    expect(store.items[0].entries_count).toBe(7);
  });

  it('deleteBase drops the row', async () => {
    const store = useKnowledgeStore();
    apiMock.get.mockResolvedValueOnce({ data: [base({ id: 'b1' })], meta: { next_cursor: null } });
    await store.fetchBases({}, { reset: true });

    apiMock.delete.mockResolvedValueOnce(undefined);
    await store.deleteBase('b1');

    expect(apiMock.delete).toHaveBeenCalledWith('/knowledge/bases/b1');
    expect(store.items).toHaveLength(0);
  });
});

describe('lifecycle endpoints', () => {
  it('restoreBase posts to …/restore and drops the row from the trash list', async () => {
    const store = useKnowledgeStore();
    apiMock.get.mockResolvedValueOnce({
      data: [base({ id: 'b1', deleted_at: '2026-02-01T00:00:00Z' })],
      meta: { next_cursor: null },
    });
    await store.fetchBases({ trashed: true }, { reset: true });

    apiMock.post.mockResolvedValueOnce({ data: base({ id: 'b1', deleted_at: null }) });
    const restored = await store.restoreBase('b1');

    expect(apiMock.post).toHaveBeenCalledWith('/knowledge/bases/b1/restore', {});
    expect(restored.deleted_at).toBeNull();
    expect(store.items).toHaveLength(0);
  });

  it('forceDeleteBase deletes …/force and drops the row', async () => {
    const store = useKnowledgeStore();
    apiMock.get.mockResolvedValueOnce({ data: [base({ id: 'b1' })], meta: { next_cursor: null } });
    await store.fetchBases({ trashed: true }, { reset: true });

    apiMock.delete.mockResolvedValueOnce(undefined);
    await store.forceDeleteBase('b1');

    expect(apiMock.delete).toHaveBeenCalledWith('/knowledge/bases/b1/force');
    expect(store.items).toHaveLength(0);
  });
});

describe('fetchBase (single read)', () => {
  it('reads one base without touching the list', async () => {
    const store = useKnowledgeStore();
    apiMock.get.mockResolvedValueOnce({ data: base({ id: 'b9', name: 'Product' }) });

    const one = await store.fetchBase('b9');

    expect(apiMock.get).toHaveBeenCalledWith('/knowledge/bases/b9');
    expect(one.name).toBe('Product');
    expect(store.items).toHaveLength(0);
  });
});

describe('entries: index retry + trash (B6c)', () => {
  const entry = {
    id: 'e1',
    title: 'Zwroty',
    slug: 'zwroty',
    position: 0,
    index: { status: 'pending', chunks_count: 8, indexed_chunks_count: 5, needs_indexing: false, can_retry: false },
  };

  it('sends `trashed` alongside the other three entry filters', () => {
    expect(serializeEntryFilters({}).toString()).toBe('');
    expect(serializeEntryFilters({ trashed: true }).toString()).toBe('trashed=1');
    expect(serializeEntryFilters({ search: 'zwrot', trashed: true }).toString()).toBe(
      'search=zwrot&trashed=1',
    );
    expect(serializeEntryFilters({ status: ['draft'], stale: true, trashed: true }).toString()).toBe(
      'status%5B%5D=draft&stale=1&trashed=1',
    );
  });

  it('retries an index and adopts the RESPONSE — no second read', async () => {
    const store = useKnowledgeStore();
    apiMock.post.mockResolvedValueOnce({ data: entry });

    const result = await store.retryIndex('e1');

    expect(apiMock.post).toHaveBeenCalledWith('/knowledge/entries/e1/retry-index', {});
    // A GET here would race the worker that the retry just queued.
    expect(apiMock.get).not.toHaveBeenCalled();
    expect(result.index.status).toBe('pending');
    expect(store.entry?.id).toBe('e1');
  });

  it('lets a 422 through, so the caller can show the server’s own reason', async () => {
    const store = useKnowledgeStore();
    apiMock.post.mockRejectedValueOnce({
      response: { status: 422, data: { errors: { index: ['Already queued.'] } } },
    });

    await expect(store.retryIndex('e1')).rejects.toMatchObject({
      response: { data: { errors: { index: ['Already queued.'] } } },
    });
  });
});

describe('graph (B5b)', () => {
  const emptyGraph = {
    center: null,
    nodes: [],
    edges: [],
    ghosts: [],
    truncated: { hidden_nodes: 0, hidden_edges: 0 },
  };

  it('serializes exactly the KnowledgeGraphQuery params, sources in the comma form the DTO accepts', () => {
    expect(serializeGraphFilters({}).toString()).toBe('');
    expect(serializeGraphFilters({ entry: 'e1', depth: 2 }).toString()).toBe('entry=e1&depth=2');
    expect(serializeGraphFilters({ sources: ['wikilink', 'manual'] }).toString()).toBe(
      'sources=wikilink%2Cmanual',
    );
    expect(serializeGraphFilters({ minScore: 0.9, includeDismissed: true }).toString()).toBe(
      'min_score=0.9&include_dismissed=1',
    );
  });

  it('never sends an EMPTY sources list — the server would read it as "the default two"', () => {
    expect(serializeGraphFilters({ sources: [] }).toString()).toBe('');
  });

  it('fetches the graph and holds the payload whole', async () => {
    const store = useKnowledgeStore();
    apiMock.get.mockResolvedValueOnce({ data: { ...emptyGraph, center: 'c1' } });

    await store.fetchGraph('b1', { entry: 'c1', depth: 1 });

    expect(apiMock.get).toHaveBeenCalledWith('/knowledge/bases/b1/graph?entry=c1&depth=1');
    expect(store.graph?.center).toBe('c1');
    expect(store.graphLoading).toBe(false);
    expect(store.graphErrored).toBe(false);
  });

  it('a REFRESH keeps the old picture on screen while the new one loads', async () => {
    const store = useKnowledgeStore();
    apiMock.get.mockResolvedValueOnce({ data: emptyGraph });
    await store.fetchGraph('b1');

    let resolve: ((value: unknown) => void) | undefined;
    apiMock.get.mockReturnValueOnce(new Promise((r) => (resolve = r)));
    const inFlight = store.fetchGraph('b1', { entry: 'c1' }, { refresh: true });

    expect(store.graphRefreshing).toBe(true);
    expect(store.graphLoading).toBe(false); // the canvas dims, it does not collapse
    expect(store.graph).not.toBeNull();

    resolve?.({ data: { ...emptyGraph, center: 'c1' } });
    await inFlight;
    expect(store.graphRefreshing).toBe(false);
  });

  it('reports a failure and clears the stale picture', async () => {
    const store = useKnowledgeStore();
    apiMock.get.mockRejectedValueOnce({ response: { data: { message: 'nope' } } });

    await store.fetchGraph('b1');

    expect(store.graph).toBeNull();
    expect(store.graphErrored).toBe(true);
    expect(store.graphError).toBe('nope');
  });

  it('drops ONE edge in place on a dismissal, keeping both of its nodes', () => {
    const store = useKnowledgeStore();
    store.graph = {
      ...emptyGraph,
      nodes: [
        { id: 'a', slug: 'a', title: 'A', status: 'draft', is_stale: false, degree: 1, distance: 0 },
        { id: 'b', slug: 'b', title: 'B', status: 'draft', is_stale: false, degree: 1, distance: 1 },
      ],
      edges: [
        { id: 'e1', from: 'a', to: 'b', source: 'similarity', score: 0.9, evidence: null, dismissed: false },
        { id: 'e2', from: 'a', to: 'b', source: 'wikilink', score: null, evidence: null, dismissed: false },
      ],
    };

    store.removeGraphEdge('e1');

    expect(store.graph?.edges.map((e) => e.id)).toEqual(['e2']);
    expect(store.graph?.nodes).toHaveLength(2);
  });

  it('resetGraph orphans anything in flight', async () => {
    const store = useKnowledgeStore();
    let resolve: ((value: unknown) => void) | undefined;
    apiMock.get.mockReturnValueOnce(new Promise((r) => (resolve = r)));
    const inFlight = store.fetchGraph('b1');

    store.resetGraph();
    resolve?.({ data: { ...emptyGraph, center: 'late' } });
    await inFlight;

    expect(store.graph).toBeNull();
  });
});

// The COMPOSER's wire (B15a). Verified against KnowledgeDraftSessionController + its resource.
describe('the AI composer', () => {
  const session = {
    id: 's1',
    knowledge_base_id: 'b1',
    status: 'generating',
    failure_reason: null,
    source_text: 'raw',
    prompt_history: [],
    seed_slug: null,
    seed_title: null,
    drafts: [],
    duplicates: {},
    created_at: null,
    updated_at: null,
  };

  it('asks for availability BEFORE the form, and never throws at the caller', async () => {
    const store = useKnowledgeStore();
    apiMock.get.mockRejectedValueOnce(new Error('offline'));

    await store.fetchComposeAvailability('b1');

    expect(apiMock.get).toHaveBeenCalledWith('/knowledge/bases/b1/compose-availability');
    // A failed gate must not blank the screen: the POST is authoritative and refuses honestly.
    expect(store.composeAvailabilityErrored).toBe(true);
    expect(store.composeAvailability).toBeNull();
  });

  it('starts a session with the source text and an optional seed', async () => {
    const store = useKnowledgeStore();
    apiMock.post.mockResolvedValueOnce({ data: session });

    await store.startDraftSession('b1', { source_text: 'raw', seed_slug: 'zwroty' });

    expect(apiMock.post).toHaveBeenCalledWith('/knowledge/bases/b1/draft-sessions', {
      source_text: 'raw',
      seed_slug: 'zwroty',
    });
    expect(store.session?.id).toBe('s1');
  });

  it('treats a 404 on a session as EXPIRED, not as an error', async () => {
    const store = useKnowledgeStore();
    apiMock.get.mockRejectedValueOnce({ response: { status: 404 } });

    await store.fetchDraftSession('gone');

    // A deep link to an abandoned session is a normal thing to do; it has its own copy.
    expect(store.sessionMissing).toBe(true);
    expect(store.sessionErrored).toBe(false);
  });

  it('accepts drafts with the ids and the target status', async () => {
    const store = useKnowledgeStore();
    apiMock.post.mockResolvedValueOnce({ accepted: [], conflicts: [] });

    await store.acceptDrafts('s1', ['d1', 'd2'], 'approved');

    expect(apiMock.post).toHaveBeenCalledWith('/knowledge/draft-sessions/s1/accept', {
      entry_ids: ['d1', 'd2'],
      status: 'approved',
    });
  });

  it('returns partial acceptance verbatim — conflicts ride ALONGSIDE the accepted', async () => {
    const store = useKnowledgeStore();
    apiMock.post.mockResolvedValueOnce({
      accepted: [{ id: 'd1' }],
      conflicts: [{ entry_id: 'd2', targets_entry_id: 'e9', current_revision_id: 'r2' }],
    });

    const result = await store.acceptDrafts('s1', ['d1', 'd2'], 'approved');

    expect(result.accepted).toHaveLength(1);
    expect(result.conflicts[0].entry_id).toBe('d2');
  });

  it('rejects ONE draft through the draft-only route', async () => {
    const store = useKnowledgeStore();
    apiMock.delete.mockResolvedValueOnce(undefined);

    await store.rejectDraft('d1');

    // Not `entries/{id}` — the ordinary binding cannot see a draft at all.
    expect(apiMock.delete).toHaveBeenCalledWith('/knowledge/entries/d1/draft');
  });

  it('asks for a diff against a NAMED baseline', async () => {
    const store = useKnowledgeStore();
    apiMock.get.mockResolvedValueOnce({ baseline: 'previous', has_baseline: true, from: null, to: {} });

    await store.fetchDraftDiff('d1', 'previous');

    expect(apiMock.get).toHaveBeenCalledWith('/knowledge/entries/d1/draft-diff?baseline=previous');
  });

  // "Widen the context" spends a metered embedding and has NO visible effect until the next
  // revision. Without a memory of it, the button stays live and the user pays twice for the same
  // widening. The wire carries no such flag (verified: the session resource exposes neither
  // `retrieval_set` nor an "expanded" marker), so the store holds it — per session, and only until
  // a revision consumes it.
  // Whether the context has been widened is the SERVER's `context_expanded_at`, not client
  // bookkeeping: the paid action has to be off for everyone looking at the session and across a
  // reload, not just for the tab that pressed it. The store therefore only adopts what it is told.
  describe('the widened-context flag', () => {
    it('adopts `context_expanded_at` from the expand response', async () => {
      const store = useKnowledgeStore();
      apiMock.post.mockResolvedValueOnce({
        data: { ...session, context_expanded_at: '2026-08-01T10:00:00Z' },
      });

      await store.expandDraftContext('s1');

      expect(apiMock.post).toHaveBeenCalledWith('/knowledge/draft-sessions/s1/expand-context', {});
      expect(store.session?.context_expanded_at).toBe('2026-08-01T10:00:00Z');
    });

    it('adopts the CLEARED flag a refinement returns — no second fetch to re-enable', async () => {
      const store = useKnowledgeStore();
      apiMock.post.mockResolvedValueOnce({
        data: { ...session, context_expanded_at: '2026-08-01T10:00:00Z' },
      });
      await store.expandDraftContext('s1');
      expect(store.session?.context_expanded_at).not.toBeNull();

      // A revision consumes the widened context, and the server hands back the session already
      // cleared — so adopting that response is the whole re-enable.
      apiMock.post.mockResolvedValueOnce({
        data: { ...session, status: 'generating', context_expanded_at: null },
      });
      await store.refineDraftSession('s1', 'krócej');

      expect(store.session?.context_expanded_at).toBeNull();
      expect(apiMock.get).not.toHaveBeenCalled();
    });

    it('leaves the session untouched when the widening was refused', async () => {
      const store = useKnowledgeStore();
      apiMock.get.mockResolvedValueOnce({ data: { ...session, context_expanded_at: null } });
      await store.fetchDraftSession('s1');

      apiMock.post.mockRejectedValueOnce({
        response: { status: 422, data: { code: 'knowledge_context_already_expanded' } },
      });
      await expect(store.expandDraftContext('s1')).rejects.toBeTruthy();

      // The refusal carries no session; the caller refetches to learn the real state.
      expect(store.session?.context_expanded_at).toBeNull();
    });
  });

  it('unwraps the relations preview from its envelope', async () => {
    const store = useKnowledgeStore();
    apiMock.get.mockResolvedValueOnce({
      data: { center: null, nodes: [], edges: [], ghosts: [], truncated: {}, duplicates: {}, vector_skipped: null },
    });

    const relations = await store.fetchDraftRelations('s1');

    expect(apiMock.get).toHaveBeenCalledWith('/knowledge/draft-sessions/s1/relations');
    expect(relations.vector_skipped).toBeNull();
  });
});

describe('resetAll', () => {
  it('drops every cached list flag', async () => {
    const store = useKnowledgeStore();
    apiMock.get.mockResolvedValueOnce({ data: [base()], meta: { next_cursor: 'c2' } });
    await store.fetchBases({}, { reset: true });

    store.resetAll();

    expect(store.items).toHaveLength(0);
    expect(store.cursor).toBeNull();
    expect(store.hasMore).toBe(true);
    expect(store.errored).toBe(false);
    expect(store.loadMoreErrored).toBe(false);
  });
});
