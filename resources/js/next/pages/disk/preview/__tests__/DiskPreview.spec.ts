// @vitest-environment happy-dom
// DiskPreview shell — resolution (level lists → deep-link /info fallback), grid-ordered
// prev/next bounds, and close clearing the ?preview query. The preview is a full-viewport
// MODAL (teleported to body), so assertions query document.body; it is mounted inside a real
// router route (the shell registers onBeforeRouteUpdate, which needs a matched-route ancestry).
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';
import { createRouter, createMemoryHistory, useRoute, RouterView } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';
import { installBrowserMocks, restoreBrowserMocks } from '../../../../__tests__/helpers/dom';

// Partial mock: the comments tab pulls the auth store, which imports more than `api` from this
// module (e.g. WORKSPACE_KEY) — keep the real exports, stub only the HTTP surface.
vi.mock('../../../../app/lib/api', async (importOriginal) => {
  const actual = await importOriginal<Record<string, unknown>>();
  return {
    ...actual,
    api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
  };
});

import { api } from '../../../../app/lib/api';
import DiskPreview from '../DiskPreview.vue';
import { useDiskStore } from '../../../../app/stores/disk';
import type { DiskFile, DiskFolder } from '../../types';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

function file(id: string, over: Partial<DiskFile> = {}): DiskFile {
  return {
    id, name: `Plik ${id}`, path: `/api/disk/${id}`, type: 'another', size: 5, size_human: '5 B',
    created_at: '2026-07-19 10:00', description: null, mime_type: null, folder_id: null,
    source: 'disk', created_at_iso: null, updated_at_iso: null, disk_trashed_at: null,
    can_be_updated: true, can_be_moved: true, can_be_deleted: true,
    can_be_restored: false, can_be_force_deleted: false, has_draft: false, ...over,
  };
}
function folder(id: string, over: Partial<DiskFolder> = {}): DiskFolder {
  return {
    id, name: `Folder ${id}`, parent_id: null, depth: 1, ancestor_ids: [],
    created_at: null, updated_at: null, deleted_at: null,
    can_be_updated: true, can_be_moved: true, can_be_deleted: true, can_be_restored: false, ...over,
  };
}

// Host route component: renders the shell with the itemId taken from ?preview (like DiskView).
const Host = defineComponent({
  setup() {
    const route = useRoute();
    return () => {
      const id = typeof route.query.preview === 'string' ? route.query.preview : null;
      return h('div', [h('span', 'grid'), id ? h(DiskPreview, { itemId: id, key: id }) : null]);
    };
  },
});

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/disk/:folder?', name: 'next.disk', component: Host }],
  });
}

/** The teleported modal's button by its accessible name. */
function btn(label: string): HTMLButtonElement | null {
  return document.body.querySelector<HTMLButtonElement>(`button[aria-label="${label}"]`);
}

describe('DiskPreview', () => {
  beforeEach(() => {
    installBrowserMocks();
    setActivePinia(createPinia());
    apiMock.get.mockReset();
    // Generic fallbacks: changelog pages + label pages resolve empty.
    apiMock.get.mockResolvedValue({ data: [], meta: { next_cursor: null } });
  });
  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
    delete document.body.dataset.nextModalLocks;
    delete document.body.dataset.nextPrevOverflow;
  });

  it('resolves the item from the level lists and bounds prev/next by the grid order', async () => {
    const router = makeRouter();
    const store = useDiskStore();
    store.folders = [folder('d1')];
    store.files = [file('f1'), file('f2')];

    await router.push({ path: '/disk', query: { preview: 'f1' } });
    const wrapper = mount({ render: () => h(RouterView) }, { global: { plugins: [router] }, attachTo: document.body });
    await flushPromises();

    // The modal content is teleported to body.
    expect(document.body.textContent).toContain('Plik f1');

    // f1 sits between the folder (d1) and f2 in grid order.
    expect(btn('Previous item')!.disabled).toBe(false);
    expect(btn('Next item')!.disabled).toBe(false);

    // Jump to the last item: next must disable.
    btn('Next item')!.click();
    await flushPromises();
    expect(router.currentRoute.value.query.preview).toBe('f2');
    expect(btn('Next item')!.disabled).toBe(true);

    wrapper.unmount();
  });

  it('closes by clearing the ?preview query (the grid stays underneath)', async () => {
    const router = makeRouter();
    const store = useDiskStore();
    store.files = [file('f1')];

    await router.push({ path: '/disk', query: { preview: 'f1' } });
    const wrapper = mount({ render: () => h(RouterView) }, { global: { plugins: [router] }, attachTo: document.body });
    await flushPromises();

    // The browser grid renders BENEATH the modal (no body swap).
    expect(wrapper.text()).toContain('grid');

    btn('Close preview')!.click();
    await flushPromises();

    expect(router.currentRoute.value.query.preview).toBeUndefined();
    expect(document.body.textContent).not.toContain('Plik f1');
    expect(wrapper.text()).toContain('grid');

    wrapper.unmount();
  });

  it('deep links resolve through /info when the item is not in the loaded level', async () => {
    const router = makeRouter();
    useDiskStore(); // empty level
    apiMock.get.mockImplementation((url: string) => {
      if (url === '/disk/fx/info') return Promise.resolve({ data: file('fx', { name: 'Głęboki link' }) });
      return Promise.resolve({ data: [], meta: { next_cursor: null } });
    });

    await router.push({ path: '/disk', query: { preview: 'fx' } });
    const wrapper = mount({ render: () => h(RouterView) }, { global: { plugins: [router] }, attachTo: document.body });
    await flushPromises();

    expect(apiMock.get).toHaveBeenCalledWith('/disk/fx/info');
    expect(document.body.textContent).toContain('Głęboki link');
    // Not in the level → prev/next both disabled.
    expect(btn('Previous item')!.disabled).toBe(true);
    expect(btn('Next item')!.disabled).toBe(true);

    wrapper.unmount();
  });
});
