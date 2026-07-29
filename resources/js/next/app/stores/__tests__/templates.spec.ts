// @vitest-environment happy-dom
// Unit tests for the "next" templates store (R2 Generator / Templatki) — filter serialization,
// cursor reset/append (NO total), the name-sorted in-place reconciliation after create/update/
// delete, and the two DRAFT-FRIENDLY editor actions (catalog + faithful preview), including the
// flat / wrapped envelope tolerance. The api client is mocked (no real HTTP).
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

import { api } from '../../lib/api';
import { useTemplatesStore, serializeFilters } from '../templates';
import type { Template } from '../../../pages/generator/types';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  put: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
};

function template(overrides: Partial<Template> = {}): Template {
  return {
    id: 't1',
    name: 'Launch post',
    description: null,
    content_type: 'post',
    slots: [{ name: 'topic', descriptor: { base: 'text', nullable: false, array: false } }],
    content: { body: { markdown: 'Write about @[variable]("{}")' } },
    creator: null,
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
  };
}

beforeEach(() => {
  setActivePinia(createPinia());
  vi.clearAllMocks();
});

describe('serializeFilters', () => {
  it('serializes only a non-empty search', () => {
    expect(serializeFilters({}).toString()).toBe('');
    expect(serializeFilters({ search: 'post' }).toString()).toBe('search=post');
  });
});

describe('fetchTemplates', () => {
  it('resets the list + reads cursor from meta (NO total)', async () => {
    apiMock.get.mockResolvedValueOnce({ data: [template()], meta: { next_cursor: 't2' } });
    const store = useTemplatesStore();

    await store.fetchTemplates({ search: 'la' }, { reset: true });

    expect(apiMock.get).toHaveBeenCalledWith('/generator/templates?search=la');
    expect(store.items).toHaveLength(1);
    expect(store.cursor).toBe('t2');
    expect(store.hasMore).toBe(true);
  });

  it('appends the next page', async () => {
    apiMock.get.mockResolvedValueOnce({ data: [template({ id: 't1', name: 'A' })], meta: { next_cursor: 't2' } });
    const store = useTemplatesStore();
    await store.fetchTemplates({}, { reset: true });

    apiMock.get.mockResolvedValueOnce({ data: [template({ id: 't2', name: 'B' })], meta: { next_cursor: null } });
    await store.loadMore({});

    expect(store.items.map((t) => t.id)).toEqual(['t1', 't2']);
    expect(store.hasMore).toBe(false);
  });
});

describe('mutations reconcile the list (name-sorted)', () => {
  it('createTemplate inserts in NAME order', async () => {
    const store = useTemplatesStore();
    apiMock.get.mockResolvedValueOnce({ data: [template({ id: 't1', name: 'Beta' })], meta: { next_cursor: null } });
    await store.fetchTemplates({}, { reset: true });

    apiMock.post.mockResolvedValueOnce({ data: template({ id: 't2', name: 'Alpha' }) });
    await store.createTemplate({ name: 'Alpha', content_type: 'post', slots: [], content: { body: { markdown: '' } } });

    expect(apiMock.post).toHaveBeenCalledWith('/generator/templates', expect.objectContaining({ name: 'Alpha' }));
    expect(store.items.map((t) => t.name)).toEqual(['Alpha', 'Beta']);
  });

  it('updateTemplate replaces in place', async () => {
    const store = useTemplatesStore();
    apiMock.get.mockResolvedValueOnce({ data: [template({ id: 't1', name: 'Launch' })], meta: { next_cursor: null } });
    await store.fetchTemplates({}, { reset: true });

    apiMock.put.mockResolvedValueOnce({ data: template({ id: 't1', name: 'Launch v2' }) });
    await store.updateTemplate('t1', { name: 'Launch v2', content_type: 'post', slots: [], content: { body: { markdown: '' } } });

    expect(apiMock.put).toHaveBeenCalledWith('/generator/templates/t1', expect.objectContaining({ name: 'Launch v2' }));
    expect(store.items[0].name).toBe('Launch v2');
  });

  it('deleteTemplate drops the row', async () => {
    const store = useTemplatesStore();
    apiMock.get.mockResolvedValueOnce({ data: [template({ id: 't1' }), template({ id: 't2', name: 'Other' })], meta: { next_cursor: null } });
    await store.fetchTemplates({}, { reset: true });

    apiMock.delete.mockResolvedValueOnce({ message: 'ok' });
    await store.deleteTemplate('t1');

    expect(apiMock.delete).toHaveBeenCalledWith('/generator/templates/t1');
    expect(store.items.map((t) => t.id)).toEqual(['t2']);
  });
});

describe('fetchCatalog (draft-friendly)', () => {
  it('POSTs the draft slots and normalizes a FLAT response', async () => {
    const store = useTemplatesStore();
    apiMock.post.mockResolvedValueOnce({
      variables: [{ source: 'slots', path: 'slots.topic', name: 'topic', type: 'text' }],
      operations: [{ id: 'uppercase', input: 'text', output: 'text', args: [] }],
      types: [],
    });

    const slots = [{ name: 'topic', descriptor: { base: 'text', nullable: false, array: false } as const }];
    const catalog = await store.fetchCatalog({ slots });

    expect(apiMock.post).toHaveBeenCalledWith('/generator/catalog', { slots });
    expect(catalog.variables).toHaveLength(1);
    expect(catalog.variables[0].path).toBe('slots.topic');
    expect(catalog.operations).toHaveLength(1);
  });

  it('normalizes a WRAPPED ({data}) catalog response too', async () => {
    const store = useTemplatesStore();
    apiMock.post.mockResolvedValueOnce({
      data: { variables: [{ source: 'globals', path: 'globals.brand', name: 'Brand', type: 'text' }], operations: [], types: [] },
    });

    const catalog = await store.fetchCatalog({ slots: [] });

    expect(catalog.variables[0].path).toBe('globals.brand');
  });
});

describe('fetchContentTypes', () => {
  it('GETs the code-defined content-type registry', async () => {
    const store = useTemplatesStore();
    apiMock.get.mockResolvedValueOnce({
      data: [
        { id: 'post', label: 'Post', parts: [{ key: 'body', kind: 'text_body', label: 'Post body', required: true, config: {} }] },
      ],
    });

    const types = await store.fetchContentTypes();

    expect(apiMock.get).toHaveBeenCalledWith('/generator/content-types');
    expect(types).toHaveLength(1);
    expect(types[0].parts[0].kind).toBe('text_body');
  });
});

describe('preview (faithful, per-part)', () => {
  it('POSTs {content_type, content, slots, slot_values} and returns the parts (from {data})', async () => {
    const store = useTemplatesStore();
    apiMock.post.mockResolvedValueOnce({ data: { parts: { body: { rendered: 'Write about Cats' } } } });

    const request = {
      content_type: 'post',
      content: { body: { markdown: 'Write about @[variable]("{}")' } },
      slots: [{ name: 'topic', descriptor: { base: 'text', nullable: false, array: false } as const }],
      slot_values: { topic: 'Cats' },
    };
    const result = await store.preview(request);

    expect(apiMock.post).toHaveBeenCalledWith('/generator/preview', request);
    expect((result.parts.body as { rendered: string }).rendered).toBe('Write about Cats');
  });

  it('falls back to a FLAT ({parts}) preview response', async () => {
    const store = useTemplatesStore();
    apiMock.post.mockResolvedValueOnce({ parts: { body: { rendered: 'Hello' } } });

    const result = await store.preview({ content_type: 'post', content: {}, slots: [], slot_values: {} });

    expect((result.parts.body as { rendered: string }).rendered).toBe('Hello');
  });
});
