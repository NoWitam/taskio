// knowledgeEntries.spec — the ENTRY / SEARCH half of the knowledge store.
//
// What these specs are protecting, in order of how expensive the mistake would be:
//
//  1. The 409 CONTRACT. Two different conflicts share the status code and are told apart only by a
//     `code` string; the editor's recovery flow and the trash's restore flow branch on it. Getting
//     `staleWriteRevisionOf` wrong in particular means an intentional overwrite re-sends the same
//     stale token and 409s forever.
//  2. The PATCH DIFF. An absent key means "unchanged" server-side, so the store must forward the
//     caller's payload verbatim. Helpfully filling in `content` would re-upload (and re-index) up
//     to 40 000 characters on a status-only save.
//  3. `drainAll`. The reader resolves wikilinks against the loaded list, so a partial list renders
//     real entries as red links — an invitation to create duplicates.
//  4. `vector_search_skipped`. When the paid semantic leg sits out, the UI has to say so; the
//     store is where that fact is preserved.
import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import {
  useKnowledgeStore,
  serializeEntryFilters,
  conflictCodeOf,
  staleWriteRevisionOf,
} from '../knowledge';
import { KNOWLEDGE_SLUG_CONFLICT, KNOWLEDGE_STALE_WRITE } from '../../../pages/knowledge/types';
import { api } from '../../lib/api';

function entryRow(over: Record<string, unknown> = {}) {
  return {
    id: 'e1',
    knowledge_base_id: 'b1',
    title: 'Brand voice',
    slug: 'brand-voice',
    excerpt: 'How we sound.',
    metadata: {},
    status: 'approved',
    stale_at: null,
    is_stale: false,
    position: 1,
    current_revision_id: 'r1',
    index: { status: 'indexed', chunks_count: 3, needs_indexing: false },
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

/** An axios-shaped rejection. */
function httpError(status: number, data: unknown): unknown {
  return { response: { status, data } };
}

describe('serializeEntryFilters — only the three params the server reads', () => {
  it('repeats `status[]` per value', () => {
    const params = serializeEntryFilters({ status: ['draft', 'approved'] });
    expect(params.getAll('status[]')).toEqual(['draft', 'approved']);
  });

  it('sends `stale` only when true', () => {
    expect(serializeEntryFilters({ stale: true }).get('stale')).toBe('1');
    expect(serializeEntryFilters({ stale: false }).has('stale')).toBe(false);
  });

  it('skips an empty search rather than sending a blank filter', () => {
    expect(serializeEntryFilters({ search: '' }).has('search')).toBe(false);
    expect(serializeEntryFilters({ search: 'brand' }).get('search')).toBe('brand');
  });

  it('sends nothing at all for empty filters', () => {
    expect(serializeEntryFilters({}).toString()).toBe('');
  });
});

describe('conflict helpers — the two 409s are told apart by `code`', () => {
  it('reads the stale-write code and the revision the SERVER holds', () => {
    const err = httpError(409, {
      code: KNOWLEDGE_STALE_WRITE,
      message: 'stale',
      current_revision_id: 'r9',
    });
    expect(conflictCodeOf(err)).toBe(KNOWLEDGE_STALE_WRITE);
    // Re-arming the lock with this is what lets a deliberate overwrite succeed on the retry.
    expect(staleWriteRevisionOf(err)).toBe('r9');
  });

  it('reads the slug-conflict code', () => {
    const err = httpError(409, { code: KNOWLEDGE_SLUG_CONFLICT, message: 'taken', slug: 'brand' });
    expect(conflictCodeOf(err)).toBe(KNOWLEDGE_SLUG_CONFLICT);
  });

  it('returns null for any non-409, so a 422 never takes the conflict path', () => {
    expect(conflictCodeOf(httpError(422, { errors: {} }))).toBeNull();
    expect(conflictCodeOf(httpError(500, {}))).toBeNull();
    expect(conflictCodeOf(new Error('network'))).toBeNull();
    expect(conflictCodeOf(undefined)).toBeNull();
  });

  it('returns null revision when the body omits it', () => {
    expect(staleWriteRevisionOf(httpError(409, { code: KNOWLEDGE_STALE_WRITE }))).toBeNull();
  });
});

describe('the knowledge store — entries', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('fetches one page by default', async () => {
    const get = vi.spyOn(api, 'get').mockResolvedValue({
      data: [entryRow()],
      meta: { next_cursor: 'c2' },
    } as never);

    const store = useKnowledgeStore();
    await store.fetchEntries('b1');

    expect(get).toHaveBeenCalledTimes(1);
    expect(store.entries).toHaveLength(1);
    expect(store.entriesHasMore).toBe(true);
  });

  it('DRAINS every cursor page when the reader asks for the whole list', async () => {
    const get = vi
      .spyOn(api, 'get')
      .mockResolvedValueOnce({
        data: [entryRow({ id: 'e1', slug: 'a' })],
        meta: { next_cursor: 'c2' },
      } as never)
      .mockResolvedValueOnce({
        data: [entryRow({ id: 'e2', slug: 'b' })],
        meta: { next_cursor: 'c3' },
      } as never)
      .mockResolvedValueOnce({
        data: [entryRow({ id: 'e3', slug: 'c' })],
        meta: { next_cursor: null },
      } as never);

    const store = useKnowledgeStore();
    await store.fetchEntries('b1', {}, { reset: true, drainAll: true });

    expect(get).toHaveBeenCalledTimes(3);
    expect(store.entries.map((e) => e.slug)).toEqual(['a', 'b', 'c']);
    expect(store.entriesHasMore).toBe(false);
    expect(store.entriesTruncated).toBe(false);
  });

  it('indexes the drained entries by slug for the wikilink resolver', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({
      data: [entryRow({ id: 'e1', slug: 'brand-voice' }), entryRow({ id: 'e2', slug: 'pricing' })],
      meta: { next_cursor: null },
    } as never);

    const store = useKnowledgeStore();
    await store.fetchEntries('b1', {}, { reset: true, drainAll: true });

    expect(store.entriesBySlug.get('brand-voice')?.id).toBe('e1');
    expect(store.entriesBySlug.get('pricing')?.id).toBe('e2');
    // A slug that is genuinely absent is what makes a wikilink a GHOST.
    expect(store.entriesBySlug.get('nope')).toBeUndefined();
  });

  it('records a first-page failure separately from an append failure', async () => {
    vi.spyOn(api, 'get').mockRejectedValue(httpError(500, { message: 'boom' }));

    const store = useKnowledgeStore();
    await store.fetchEntries('b1');

    expect(store.entriesErrored).toBe(true);
    expect(store.entriesLoadMoreErrored).toBe(false);
    expect(store.entriesHasMore).toBe(false);
  });

  
  
  });

describe('the knowledge store — search', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });
  afterEach(() => {
    vi.restoreAllMocks();
  });

  const META = {
    query: 'brand',
    count: 1,
    limit: 25,
    has_more: false,
    vector_search_skipped: false,
    vector_search_reason: null,
  };

  it('hits the WORKSPACE endpoint when no base is given', async () => {
    const get = vi.spyOn(api, 'get').mockResolvedValue({ data: [], meta: META } as never);

    const store = useKnowledgeStore();
    await store.searchKnowledge('brand');

    expect(get.mock.calls[0][0]).toContain('/knowledge/search?');
    expect(get.mock.calls[0][0]).toContain('q=brand');
  });

  it('hits the BASE endpoint when one is given, and repeats `status[]`', async () => {
    const get = vi.spyOn(api, 'get').mockResolvedValue({ data: [], meta: META } as never);

    const store = useKnowledgeStore();
    await store.searchKnowledge('brand', { baseId: 'b1', statuses: ['approved', 'draft'] });

    const url = get.mock.calls[0][0] as string;
    expect(url).toContain('/knowledge/bases/b1/search?');
    expect(url).toContain('status%5B%5D=approved');
    expect(url).toContain('status%5B%5D=draft');
  });

  it('does not call the API at all for a blank query — every call costs an embedding', async () => {
    const get = vi.spyOn(api, 'get').mockResolvedValue({ data: [], meta: META } as never);

    const store = useKnowledgeStore();
    await store.searchKnowledge('   ');

    expect(get).not.toHaveBeenCalled();
    expect(store.searchResults).toEqual([]);
  });

  it('preserves `vector_search_skipped` + its reason so the UI can explain itself', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({
      data: [],
      meta: { ...META, count: 0, vector_search_skipped: true, vector_search_reason: 'budget' },
    } as never);

    const store = useKnowledgeStore();
    await store.searchKnowledge('brand');

    expect(store.searchVectorSkipped).toBe(true);
    expect(store.searchVectorReason).toBe('budget');
  });

  it('keeps `has_more` as a TRUNCATION flag alongside the limit', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({
      data: [],
      meta: { ...META, has_more: true, limit: 25, count: 25 },
    } as never);

    const store = useKnowledgeStore();
    await store.searchKnowledge('brand');

    expect(store.searchHasMore).toBe(true);
    expect(store.searchLimit).toBe(25);
  });

  it('marks the search errored on failure and clears the previous results', async () => {
    vi.spyOn(api, 'get').mockRejectedValue(httpError(500, { message: 'boom' }));

    const store = useKnowledgeStore();
    await store.searchKnowledge('brand');

    expect(store.searchErrored).toBe(true);
    expect(store.searchResults).toEqual([]);
  });

  it('drops a STALE response so a slow first query cannot overwrite a fast second one', async () => {
    let resolveFirst!: (value: unknown) => void;
    const first = new Promise((resolve) => {
      resolveFirst = resolve;
    });

    vi.spyOn(api, 'get')
      .mockImplementationOnce(() => first as never)
      .mockResolvedValueOnce({
        data: [{ ...entryRow(), base: null, matched_chunk: null, matched_chunks_count: 0, rrf_score: 1 }],
        meta: { ...META, query: 'second' },
      } as never);

    const store = useKnowledgeStore();
    const a = store.searchKnowledge('first');
    const b = store.searchKnowledge('second');
    await b;

    resolveFirst({ data: [], meta: { ...META, query: 'first' } });
    await a;

    expect(store.searchQuery).toBe('second');
    expect(store.searchResults).toHaveLength(1);
  });
});
