// @vitest-environment happy-dom
// DiskMoveModal — move a file/folder via the inline folder picker. Pins the wire: a file move
// is a PATCH of folder_id; a folder move is the dedicated /move endpoint with target_folder_id;
// the picker's current level is the destination (root = null); and a backend 422 (self /
// descendant / depth / collision) is surfaced verbatim. api is mocked; the Modal teleports to
// <body>, so we drive document.body.
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
import DiskMoveModal, { type MoveTarget } from '../DiskMoveModal.vue';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  patch: ReturnType<typeof vi.fn>;
};

function flush(): Promise<void> {
  return new Promise((r) => setTimeout(r, 0));
}

function moveHereButton(): HTMLButtonElement | undefined {
  return Array.from(document.body.querySelectorAll('button')).find(
    (b) => (b.textContent ?? '').trim() === 'Move here',
  );
}

function mountModal(target: MoveTarget): ReturnType<typeof mount> {
  return mount(DiskMoveModal, { attachTo: document.body, props: { open: true, target } });
}

describe('DiskMoveModal', () => {
  beforeAll(() => setLocale('en'));
  beforeEach(() => {
    setActivePinia(createPinia());
    installBrowserMocks();
    apiMock.get.mockReset().mockResolvedValue({ data: [] }); // picker: no subfolders at root
    apiMock.post.mockReset().mockResolvedValue({ data: {} });
    apiMock.patch.mockReset().mockResolvedValue({ data: {} });
    toast.success.mockReset();
    toast.danger.mockReset();
  });
  afterEach(() => restoreBrowserMocks());

  it('moving a FILE to the root PATCHes folder_id: null', async () => {
    const wrapper = mountModal({ kind: 'file', id: 'f1', name: 'x.png' });
    await flush();

    moveHereButton()!.click();
    await flush();

    expect(apiMock.patch).toHaveBeenCalledWith('/disk/f1', { folder_id: null });
    expect(toast.success).toHaveBeenCalled();
    wrapper.unmount();
  });

  it('moving a FOLDER hits the dedicated /move endpoint with target_folder_id', async () => {
    const wrapper = mountModal({ kind: 'folder', id: 'a', name: 'Campaigns' });
    await flush();

    moveHereButton()!.click();
    await flush();

    expect(apiMock.post).toHaveBeenCalledWith('/disk/folders/a/move', { target_folder_id: null });
    wrapper.unmount();
  });

  it('surfaces the backend 422 message (e.g. move into a descendant)', async () => {
    apiMock.post.mockRejectedValue({ response: { data: { message: 'Cannot move a folder into its own descendant.' } } });
    const wrapper = mountModal({ kind: 'folder', id: 'a', name: 'Campaigns' });
    await flush();

    moveHereButton()!.click();
    await flush();

    expect(toast.danger).toHaveBeenCalledWith('Cannot move a folder into its own descendant.');
    wrapper.unmount();
  });
});
