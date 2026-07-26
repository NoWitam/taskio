// @vitest-environment happy-dom
// DiskCopyModal — duplicate a file (name + destination). Pins the wire: it POSTs to /disk/{id}/copy
// with the (defaulted "Copy of X") name and the picked folder, and the destination defaults to the
// folder the copy was started from. api is mocked; the Modal teleports to <body>, so we drive it.
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
import DiskCopyModal from '../DiskCopyModal.vue';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
};

function flush(): Promise<void> {
  return new Promise((r) => setTimeout(r, 0));
}

function copyHereButton(): HTMLButtonElement | undefined {
  return Array.from(document.body.querySelectorAll('button')).find((b) => (b.textContent ?? '').trim() === 'Copy here');
}

describe('DiskCopyModal', () => {
  beforeAll(() => setLocale('en'));
  beforeEach(() => {
    setActivePinia(createPinia());
    installBrowserMocks();
    apiMock.get.mockReset().mockResolvedValue({ data: [] }); // picker: no subfolders
    apiMock.post.mockReset().mockResolvedValue({ data: { id: 'c1', folder_id: null } });
    toast.success.mockReset();
    toast.danger.mockReset();
  });
  afterEach(() => restoreBrowserMocks());

  it('copies with the defaulted "Copy of X" name into the default (root) folder', async () => {
    const wrapper = mount(DiskCopyModal, {
      attachTo: document.body,
      props: { open: true, source: { id: 'f1', name: 'brief.pdf' }, defaultFolderId: null },
    });
    await flush();

    copyHereButton()!.click();
    await flush();

    expect(apiMock.post).toHaveBeenCalledWith('/disk/f1/copy', { name: 'Copy of brief.pdf', folder_id: null });
    expect(toast.success).toHaveBeenCalled();
    wrapper.unmount();
  });

  it('surfaces a backend error message verbatim', async () => {
    apiMock.post.mockRejectedValue({ response: { data: { message: 'Folder not found.' } } });
    const wrapper = mount(DiskCopyModal, {
      attachTo: document.body,
      props: { open: true, source: { id: 'f1', name: 'brief.pdf' }, defaultFolderId: null },
    });
    await flush();

    copyHereButton()!.click();
    await flush();

    expect(toast.danger).toHaveBeenCalledWith('Folder not found.');
    wrapper.unmount();
  });
});
