// @vitest-environment happy-dom
// DiskFileDrawer — the file detail panel. Pins that opening it seeds the editable metadata,
// fetches the file's history from the generic changelog endpoint, and renders the entries.
// api is mocked; the Drawer teleports to <body>.
import { describe, it, expect, beforeEach, afterEach, beforeAll, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../../app/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));
const toast = { danger: vi.fn(), success: vi.fn() };
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => toast }));

import { api } from '../../../app/lib/api';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import DiskFileDrawer from '../DiskFileDrawer.vue';
import type { DiskFile } from '../types';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn>; patch: ReturnType<typeof vi.fn> };

function diskFile(over: Partial<DiskFile> = {}): DiskFile {
  return {
    id: 'f1', name: 'raport.pdf', path: '/api/disk/f1', type: 'document', size: 2048, size_human: '2 KB',
    created_at: '2026-07-17 10:00', description: 'a note', mime_type: 'application/pdf', folder_id: null,
    source: 'disk', created_at_iso: '2026-07-17T10:00:00Z', updated_at_iso: null, disk_trashed_at: null,
    labels: [], can_be_updated: true, can_be_moved: true, can_be_deleted: true,
    can_be_restored: true, can_be_force_deleted: true, ...over,
  };
}

const CHANGELOG = {
  data: [{ id: 1, event: 'updated', event_description: 'Renamed the file', details: {}, causer: { id: 'u', name: 'Ada', email: 'a@x' }, created_at: '2026-07-17T10:00:00Z' }],
  meta: { next_cursor: null },
};

function routeGet(): void {
  apiMock.get.mockImplementation((url: string) => {
    if (url.endsWith('/changelog')) return Promise.resolve(CHANGELOG);
    if (url.includes('inline=1')) return Promise.resolve(new Blob(['x']));
    return Promise.resolve({ data: [] }); // /labels etc.
  });
}

function flush(): Promise<void> {
  return new Promise((r) => setTimeout(r, 0));
}

describe('DiskFileDrawer', () => {
  beforeAll(() => setLocale('en'));
  beforeEach(() => {
    setActivePinia(createPinia());
    installBrowserMocks();
    apiMock.get.mockReset();
    apiMock.patch.mockReset();
    toast.success.mockReset();
    routeGet();
    // The preview/download build object URLs; happy-dom may not implement them.
    if (!URL.createObjectURL) URL.createObjectURL = () => 'blob:x';
    if (!URL.revokeObjectURL) URL.revokeObjectURL = () => {};
  });
  afterEach(() => restoreBrowserMocks());

  it('seeds metadata, fetches the history, and renders the entries', async () => {
    const wrapper = mount(DiskFileDrawer, {
      attachTo: document.body,
      props: { open: true, file: diskFile() },
    });
    await flush();

    // History came from the generic changelog endpoint keyed on the `file` morph alias.
    expect(apiMock.get).toHaveBeenCalledWith('/file/f1/changelog');
    expect(document.body.textContent).toContain('Renamed the file');
    expect(document.body.textContent).toContain('Ada');

    // The metadata facts + editable name are seeded from the file.
    expect(document.body.textContent).toContain('2 KB');
    const nameInput = document.body.querySelector('input[aria-label="Name"]') as HTMLInputElement | null;
    expect(nameInput?.value).toBe('raport.pdf');
    wrapper.unmount();
  });

  it('a non-editable file hides the Save affordance', async () => {
    const wrapper = mount(DiskFileDrawer, {
      attachTo: document.body,
      props: { open: true, file: diskFile({ can_be_updated: false }) },
    });
    await flush();

    const nameInput = document.body.querySelector('input[aria-label="Name"]') as HTMLInputElement | null;
    expect(nameInput?.disabled).toBe(true);
    wrapper.unmount();
  });
});
