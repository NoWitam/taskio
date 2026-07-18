// @vitest-environment happy-dom
// DiskFilePickerModal — browse the disk and pick ONE file for a file field. Pins the two
// behaviours that matter: files exceeding the field's max size (or not matching its accepted
// types) are shown but NOT selectable, and confirming a selection emits that file (the host
// then copies it to a temp). api is mocked; the Modal teleports to <body>, so we drive it.
import { describe, it, expect, beforeEach, afterEach, beforeAll, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('../../../app/lib/api', () => ({
  api: { get: vi.fn() },
}));

import { api } from '../../../app/lib/api';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import DiskFilePickerModal from '../DiskFilePickerModal.vue';
import type { DiskFile } from '../types';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

function flush(): Promise<void> {
  return new Promise((r) => setTimeout(r, 0));
}

/** A DiskFile fixture (only the fields the picker reads matter; the rest satisfy the type). */
function file(overrides: Partial<DiskFile> = {}): DiskFile {
  return {
    id: 'f',
    name: 'file.bin',
    path: '/x',
    type: 'document',
    size: 1000,
    size_human: '1 KB',
    created_at: '2026-07-18 10:00',
    description: null,
    mime_type: 'application/octet-stream',
    folder_id: null,
    source: 'disk',
    created_at_iso: null,
    updated_at_iso: null,
    disk_trashed_at: null,
    can_be_updated: true,
    can_be_moved: true,
    can_be_deleted: true,
    can_be_restored: false,
    can_be_force_deleted: false,
    ...overrides,
  };
}

function buttonWithText(text: string): HTMLButtonElement | undefined {
  return Array.from(document.body.querySelectorAll('button')).find((b) => (b.textContent ?? '').includes(text));
}

describe('DiskFilePickerModal', () => {
  beforeAll(() => setLocale('en'));
  beforeEach(() => {
    installBrowserMocks();
    apiMock.get.mockReset().mockImplementation((url: string) => {
      // Folders index at any level → none; the files index → one eligible + one too big.
      if (url.startsWith('/disk/folders')) return Promise.resolve({ data: [] });
      return Promise.resolve({
        data: [
          file({ id: 'small', name: 'photo.png', type: 'image', mime_type: 'image/png', size: 1000 }),
          file({ id: 'big', name: 'huge.png', type: 'image', mime_type: 'image/png', size: 10 * 1024 * 1024 }),
        ],
        meta: { next_cursor: null },
      });
    });
  });
  afterEach(() => restoreBrowserMocks());

  it('disables files over the max size but lets an eligible one be chosen', async () => {
    const wrapper = mount(DiskFilePickerModal, {
      attachTo: document.body,
      props: { open: true, maxSize: 1, acceptedTypes: [] }, // 1 MB cap
    });
    await flush();

    // The oversized file is shown but not selectable; the small one is.
    expect(buttonWithText('huge.png')!.disabled).toBe(true);
    expect(buttonWithText('photo.png')!.disabled).toBe(false);

    // Choose is disabled until something is selected.
    expect(buttonWithText('Choose')!.disabled).toBe(true);

    buttonWithText('photo.png')!.click();
    await flush();
    buttonWithText('Choose')!.click();
    await flush();

    const selected = wrapper.emitted('select') as unknown[][] | undefined;
    expect(selected?.length).toBe(1);
    expect((selected![0][0] as DiskFile).id).toBe('small');
    wrapper.unmount();
  });

  it('gates on accepted types too (a type mismatch is not selectable)', async () => {
    apiMock.get.mockImplementation((url: string) => {
      if (url.startsWith('/disk/folders')) return Promise.resolve({ data: [] });
      return Promise.resolve({
        data: [file({ id: 'pdf', name: 'doc.pdf', type: 'document', mime_type: 'application/pdf', size: 100 })],
        meta: { next_cursor: null },
      });
    });
    const wrapper = mount(DiskFilePickerModal, {
      attachTo: document.body,
      props: { open: true, maxSize: null, acceptedTypes: ['image/*'] },
    });
    await flush();

    // A PDF cannot satisfy an image-only field, so it is disabled.
    expect(buttonWithText('doc.pdf')!.disabled).toBe(true);
    wrapper.unmount();
  });
});
