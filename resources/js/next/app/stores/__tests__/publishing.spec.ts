// @vitest-environment happy-dom
// Unit tests for the "next" publishing store: filter serialization (repeated `platform[]`,
// and `all` as the ABSENCE of `status`), the counts contract, cursor reset/append with the
// stale-token guard, and the in-place reconciliation every write performs.
//
// THE ASSERTION THAT MATTERS MOST IS ABOUT ZERO. `counts` is null until a successful
// response and goes back to null on failure, because "we could not count" and "there is
// nothing" are different statements — and only one of them is true. A single `?? 0` in the
// store would make the tab bar claim the second while meaning the first, on the bar people
// read to decide where to go next.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../lib/api', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
  // The auth store (the workspace clock's only source of an id) reads these from the same
  // module. Mocked with their real values so it keeps its own storage behaviour.
  TOKEN_KEY: 'taskio_token',
  WORKSPACE_KEY: 'taskio_workspace',
}));

import { api } from '../../lib/api';
import { useAuthStore } from '../auth';
import { serializeFilters, usePublishingStore } from '../publishing';
import type { Publication } from '../../../pages/publishing/types';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  put: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
};

function publication(overrides: Partial<Publication> = {}): Publication {
  return {
    id: 'p1',
    title: 'Autumn teaser',
    body: null,
    platform: 'youtube',
    platform_label: 'YouTube',
    publishes_publicly: true,
    platform_connection_id: null,
    status: 'draft',
    status_label: 'Draft',
    status_tone: null,
    needs_attention: false,
    scheduled_at: null,
    published_at: null,
    media: [],
    options: {},
    remote_id: null,
    remote_url: null,
    attempts: 0,
    last_attempt_at: null,
    failure_code: null,
    failure_context: null,
    approval_pipeline_id: null,
    is_in_approval: false,
    approval_state: null,
    intended_publish_at: null,
    creator: null,
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    can_be_scheduled: true,
    can_be_reconciled: false,
    created_at: '2026-09-01T10:00:00.000000Z',
    updated_at: '2026-09-01T10:00:00.000000Z',
    ...overrides,
  };
}

beforeEach(() => {
  setActivePinia(createPinia());
  vi.clearAllMocks();
});

describe('serializeFilters', () => {
  it('repeats `platform[]` per value — the convention the validator reads', () => {
    const params = serializeFilters({ platform: ['youtube', 'dry_run'] });
    expect(params.getAll('platform[]')).toEqual(['youtube', 'dry_run']);
  });

  it('omits `status` entirely for "all" — the server knows no such value', () => {
    expect(serializeFilters({}).has('status')).toBe(false);
    expect(serializeFilters({ status: 'failed' }).get('status')).toBe('failed');
  });

  it('skips empty values so a cleared control never narrows the query', () => {
    const params = serializeFilters({ search: '', platform: [], scheduled_from: undefined });
    expect(params.toString()).toBe('');
  });

  it('passes the search term through as a literal', () => {
    // The server escapes `%` and `_`; the client must not pre-mangle the term.
    expect(serializeFilters({ search: '50% off_now' }).get('search')).toBe('50% off_now');
  });
});

describe('counts', () => {
  it('stores what the server answered', async () => {
    apiMock.get.mockResolvedValueOnce({
      data: {
        counts: {
          draft: 3,
          scheduled: 5,
          publishing: 0,
          published: 12,
          failed: 1,
          needs_reconcile: 0,
          blocked: 2,
        },
        total: 23,
        needs_attention: 3,
      },
    });

    const store = usePublishingStore();
    await store.fetchCounts();

    expect(store.counts?.total).toBe(23);
    expect(store.counts?.counts.publishing).toBe(0);
    // Server-computed, never summed here — an eighth attention status must not need a
    // client change.
    expect(store.needsAttention).toBe(3);
  });

  it('goes back to NULL on failure — never to zero', async () => {
    apiMock.get.mockRejectedValueOnce({ response: { status: 500, data: {} } });

    const store = usePublishingStore();
    await expect(store.fetchCounts()).rejects.toBeTruthy();

    expect(store.counts).toBeNull();
    expect(store.countsErrored).toBe(true);
    // The nav badge disappears rather than claiming there is nothing to do.
    expect(store.needsAttention).toBeNull();
  });

  it('asks for counts WITHOUT the status filter but WITH the others', async () => {
    apiMock.get.mockResolvedValue({ data: { counts: {}, total: 0, needs_attention: 0 } });

    const store = usePublishingStore();
    await store.fetchCounts({ status: 'failed', platform: ['youtube'], search: 'teaser' });

    const url = String(apiMock.get.mock.calls[0][0]);
    expect(url).toContain('/publishing/counts?');
    expect(url).not.toContain('status=');
    expect(url).toContain('platform%5B%5D=youtube');
    expect(url).toContain('search=teaser');
  });
});

describe('the list', () => {
  it('resets, then appends', async () => {
    apiMock.get.mockResolvedValueOnce({
      data: [publication({ id: 'p1' })],
      meta: { next_cursor: 'cur-1' },
    });

    const store = usePublishingStore();
    await store.fetchPublications();
    expect(store.items.map((p) => p.id)).toEqual(['p1']);
    expect(store.hasMore).toBe(true);

    apiMock.get.mockResolvedValueOnce({
      data: [publication({ id: 'p2' })],
      meta: { next_cursor: null },
    });
    await store.loadMore();
    expect(store.items.map((p) => p.id)).toEqual(['p1', 'p2']);
    expect(store.hasMore).toBe(false);
  });

  it('keeps the loaded pages on screen when an APPEND fails', async () => {
    apiMock.get.mockResolvedValueOnce({ data: [publication()], meta: { next_cursor: 'cur-1' } });
    const store = usePublishingStore();
    await store.fetchPublications();

    apiMock.get.mockRejectedValueOnce({ response: { status: 500, data: {} } });
    await store.loadMore();

    expect(store.items).toHaveLength(1);
    expect(store.loadMoreErrored).toBe(true);
    // The cursor survives, so the same page can be asked for again.
    expect(store.hasMore).toBe(true);
  });

  it('discards a response that a later reset superseded', async () => {
    let resolveFirst: ((value: unknown) => void) | null = null;
    apiMock.get.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          resolveFirst = resolve;
        }),
    );
    apiMock.get.mockResolvedValueOnce({
      data: [publication({ id: 'fresh' })],
      meta: { next_cursor: null },
    });

    const store = usePublishingStore();
    const stale = store.fetchPublications({ status: 'draft' });
    await store.fetchPublications({ status: 'failed' });
    resolveFirst?.({ data: [publication({ id: 'stale' })], meta: { next_cursor: null } });
    await stale;

    expect(store.items.map((p) => p.id)).toEqual(['fresh']);
  });
});

describe('writes reconcile the list in place', () => {
  it('replaces the row a schedule answered with', async () => {
    apiMock.get.mockResolvedValueOnce({
      data: [publication({ id: 'p1', status: 'draft' })],
      meta: { next_cursor: null },
    });
    const store = usePublishingStore();
    await store.fetchPublications();

    apiMock.post.mockResolvedValueOnce({
      data: publication({ id: 'p1', status: 'scheduled', status_label: 'Scheduled' }),
    });
    const result = await store.schedulePublication('p1', '2026-09-10T09:00');

    expect(apiMock.post).toHaveBeenCalledWith('/publishing/publications/p1/schedule', {
      scheduled_at: '2026-09-10T09:00',
    });
    expect(result.status).toBe('scheduled');
    expect(store.items[0].status).toBe('scheduled');
  });

  it('hands back a REVIEW outcome unchanged, without renaming it', async () => {
    // `POST …/schedule` on a draft with a pipeline parks the moment and opens a review: 200,
    // still a draft, `is_in_approval: true`. The store must not tidy that into "scheduled".
    apiMock.post.mockResolvedValueOnce({
      data: publication({
        id: 'p1',
        status: 'draft',
        is_in_approval: true,
        approval_pipeline_id: 'pipe-1',
        approval_state: 'pending',
        intended_publish_at: '2026-09-10T07:00:00.000000Z',
      }),
    });

    const store = usePublishingStore();
    const result = await store.schedulePublication('p1', '2026-09-10T09:00');

    expect(result.status).toBe('draft');
    expect(result.is_in_approval).toBe(true);
    expect(result.intended_publish_at).toBe('2026-09-10T07:00:00.000000Z');
  });

  it('sends the whole row on update and puts the answer back in the list', async () => {
    apiMock.get.mockResolvedValueOnce({
      data: [publication({ id: 'p1', title: 'Old' })],
      meta: { next_cursor: null },
    });
    const store = usePublishingStore();
    await store.fetchPublications();

    apiMock.put.mockResolvedValueOnce({ data: publication({ id: 'p1', title: 'New' }) });
    await store.updatePublication('p1', {
      title: 'New',
      body: null,
      platform: 'youtube',
      platform_connection_id: null,
      scheduled_at: null,
      media: [],
      options: { privacy: 'unlisted' },
    });

    expect(apiMock.put).toHaveBeenCalledWith(
      '/publishing/publications/p1',
      expect.objectContaining({ options: { privacy: 'unlisted' } }),
    );
    expect(store.items[0].title).toBe('New');
  });

  it('sends NO BODY when it asks the platform what happened', async () => {
    // The one input this endpoint could take — a remote id from the client — is the one
    // value that must never arrive from outside.
    apiMock.post.mockResolvedValueOnce({ data: publication({ status: 'published' }) });
    const store = usePublishingStore();
    await store.reconcilePublication('p1');
    expect(apiMock.post).toHaveBeenCalledWith('/publishing/publications/p1/reconcile');
  });

  it('drops a deleted row from the list', async () => {
    apiMock.get.mockResolvedValueOnce({
      data: [publication({ id: 'p1' }), publication({ id: 'p2' })],
      meta: { next_cursor: null },
    });
    const store = usePublishingStore();
    await store.fetchPublications();

    apiMock.delete.mockResolvedValueOnce(undefined);
    await store.deletePublication('p1');

    expect(store.items.map((p) => p.id)).toEqual(['p2']);
  });

  it('prepends a newly created publication', async () => {
    apiMock.get.mockResolvedValueOnce({
      data: [publication({ id: 'old' })],
      meta: { next_cursor: null },
    });
    const store = usePublishingStore();
    await store.fetchPublications();

    apiMock.post.mockResolvedValueOnce({ data: publication({ id: 'new' }) });
    await store.createPublication({
      title: 'New',
      body: null,
      platform: 'dry_run',
      platform_connection_id: null,
      scheduled_at: null,
      media: [],
      options: {},
    });

    // The list is newest-CREATED first, so a new row belongs at the top.
    expect(store.items.map((p) => p.id)).toEqual(['new', 'old']);
  });
});

describe('resetAll — a workspace switch', () => {
  it('drops the LATCHED CLOCK, not only the rows', async () => {
    // `timezone` is loaded once and remembered. Left standing across a switch it keeps the
    // PREVIOUS workspace's zone, and every moment in the module — "Scheduled for 09:00", the
    // composer's wall clock, the delivery card — is then read on a clock nobody on the new
    // team uses. Nothing says so; the times simply look plausible and are wrong.
    const auth = useAuthStore();
    auth.currentWorkspaceId = 'w1';

    apiMock.get.mockResolvedValueOnce({ data: { timezone: 'Europe/Warsaw' } });
    const store = usePublishingStore();
    await store.loadTimezone();
    expect(store.timezone).toBe('Europe/Warsaw');

    // The latch: a second call while it stands asks for nothing.
    await store.loadTimezone();
    expect(apiMock.get).toHaveBeenCalledTimes(1);

    store.resetAll();
    expect(store.timezone).toBeNull();

    // And the next workspace's clock CAN be loaded — the latch was released too.
    auth.currentWorkspaceId = 'w2';
    apiMock.get.mockResolvedValueOnce({ data: { timezone: 'America/New_York' } });
    await store.loadTimezone();
    expect(apiMock.get).toHaveBeenCalledTimes(2);
    expect(store.timezone).toBe('America/New_York');
  });

  it('drops the counts back to "we do not know", never to zero', async () => {
    apiMock.get.mockResolvedValueOnce({
      data: { counts: { draft: 3 }, total: 3, needs_attention: 1 },
    });
    const store = usePublishingStore();
    await store.fetchCounts();
    expect(store.counts?.total).toBe(3);

    store.resetAll();

    // A tab bar carrying the previous workspace's numbers says how much is waiting somewhere
    // else; zero would say there is nothing to do at all.
    expect(store.counts).toBeNull();
    expect(store.countsErrored).toBe(false);
    expect(store.needsAttention).toBeNull();
    expect(store.items).toEqual([]);
    expect(store.detail).toBeNull();
  });
});

describe('the detail', () => {
  it('remembers a 404 as its own answer, not as "something went wrong"', async () => {
    apiMock.get.mockRejectedValueOnce({
      response: { status: 404, data: { message: 'Not found.' } },
    });

    const store = usePublishingStore();
    await expect(store.fetchPublication('gone')).rejects.toBeTruthy();

    expect(store.detail).toBeNull();
    expect(store.detailStatus).toBe(404);
  });
});
