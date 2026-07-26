// @vitest-environment happy-dom
// DiskRestoreModal — the P5 restore matrix in the UI. Pins the two shapes:
//   • restorable in place → the original location + a destination choice, and CONFIRM
//     posts an EMPTY body (the backend keys on target_folder_id being ABSENT);
//   • not restorable (detached / folder gone) → the reason + a mandatory picker, and
//     CONFIRM posts target_folder_id (null = the picker's root level).
// The api client is mocked; the Modal teleports to <body>, so we drive document.body.
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
import DiskRestoreModal from '../DiskRestoreModal.vue';
import type { DiskFile, DiskFolder, RestorePreview } from '../types';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
};

function diskFile(over: Partial<DiskFile> = {}): DiskFile {
  return {
    id: 'f1', name: 'raport.pdf', path: '/api/disk/f1', type: 'document', size: 10, size_human: '10 B',
    created_at: '2026-07-17 10:00', description: null, mime_type: 'application/pdf', folder_id: null,
    source: 'disk', created_at_iso: null, updated_at_iso: null, disk_trashed_at: '2026-07-17T10:00:00Z',
    can_be_updated: true, can_be_moved: true, can_be_deleted: true,
    can_be_restored: true, can_be_force_deleted: true, has_draft: false, ...over,
  };
}
function fld(id: string, name: string): DiskFolder {
  return {
    id, name, parent_id: null, depth: 0, ancestor_ids: [],
    created_at: null, updated_at: null, deleted_at: null,
    can_be_updated: true, can_be_moved: true, can_be_deleted: true, can_be_restored: true,
  };
}

function preview(over: Partial<RestorePreview> = {}): RestorePreview {
  return {
    file: diskFile(),
    original_folder: fld('a', 'Kampanie'),
    // REAL API shape: ancestors ONLY — a root-level folder has none; the folder itself
    // rides in original_folder (the location line must still name it).
    breadcrumbs: [],
    can_restore_in_place: true,
    reason: null,
    ...over,
  };
}

/** Route api.get: the preview endpoint + the picker's folder levels. */
function routeGet(p: RestorePreview): void {
  apiMock.get.mockImplementation((url: string) => {
    if (url.includes('/restore-preview')) return Promise.resolve({ data: p });
    if (url.startsWith('/disk/folders')) return Promise.resolve({ data: [] });
    return Promise.resolve({ data: [] });
  });
}

function flush(): Promise<void> {
  return new Promise((r) => setTimeout(r, 0));
}

function bodyButtons(): HTMLButtonElement[] {
  return Array.from(document.body.querySelectorAll('button'));
}
function restoreButton(): HTMLButtonElement | undefined {
  // The footer confirm — labelled exactly 'Restore' (the menu item never renders here).
  return bodyButtons().find((b) => (b.textContent ?? '').trim() === 'Restore');
}

function mountModal(): ReturnType<typeof mount> {
  return mount(DiskRestoreModal, {
    attachTo: document.body,
    props: { open: true, file: diskFile() },
  });
}

describe('DiskRestoreModal', () => {
  beforeAll(() => setLocale('en'));
  beforeEach(() => {
    setActivePinia(createPinia());
    installBrowserMocks();
    apiMock.get.mockReset();
    apiMock.post.mockReset();
    toast.success.mockReset();
    toast.danger.mockReset();
  });
  afterEach(() => restoreBrowserMocks());

  it('in-place: shows the original location and confirm posts an EMPTY body', async () => {
    routeGet(preview());
    apiMock.post.mockResolvedValue({});
    const wrapper = mountModal();
    await flush();

    expect(document.body.textContent).toContain('Original location:');
    expect(document.body.textContent).toContain('Disk / Kampanie');

    restoreButton()!.click();
    await flush();

    // The presence contract: in-place restore NEVER sends target_folder_id.
    expect(apiMock.post).toHaveBeenCalledWith('/disk/f1/restore', {});
    expect(toast.success).toHaveBeenCalled();
    wrapper.unmount();
  });

  it('detached: explains why, forces the picker, and confirm sends target_folder_id', async () => {
    routeGet(preview({ original_folder: null, breadcrumbs: [], can_restore_in_place: false, reason: 'detached' }));
    apiMock.post.mockResolvedValue({});
    const wrapper = mountModal();
    await flush();

    expect(document.body.textContent).toContain('pick where to restore it');
    // No in-place choice is offered.
    expect(document.body.textContent).not.toContain('Original spot');

    restoreButton()!.click();
    await flush();

    // The picker's default level is the root → target present, null.
    expect(apiMock.post).toHaveBeenCalledWith('/disk/f1/restore', { target_folder_id: null });
    wrapper.unmount();
  });

  it('a failed restore keeps the dialog open and toasts the server message', async () => {
    routeGet(preview());
    apiMock.post.mockRejectedValue({ response: { data: { message: 'target required' } } });
    const wrapper = mountModal();
    await flush();

    restoreButton()!.click();
    await flush();

    expect(toast.danger).toHaveBeenCalledWith('target required');
    expect(wrapper.emitted('update:open') ?? []).not.toContainEqual([false]);
    wrapper.unmount();
  });
});
