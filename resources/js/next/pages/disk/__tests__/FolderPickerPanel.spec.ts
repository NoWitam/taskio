// @vitest-environment happy-dom
// FolderPickerPanel — the inline folder browser reused by move/copy/restore. Pins the breadcrumb
// reconstruction: when the panel opens AT a preset folder (a copy/move default = the current
// folder), its trail must show that folder's ancestors + itself, not just "Disk".
import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

vi.mock('../../../app/lib/api', () => ({ api: { get: vi.fn() } }));

import { api } from '../../../app/lib/api';
import { setLocale } from '../../../app/i18n';
import FolderPickerPanel from '../FolderPickerPanel.vue';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

describe('FolderPickerPanel', () => {
  beforeAll(() => setLocale('en'));
  beforeEach(() => apiMock.get.mockReset());

  it('rebuilds the breadcrumb trail (ancestors + self) when opened at a preset folder', async () => {
    apiMock.get.mockImplementation((url: string) => {
      // The show endpoint (breadcrumb reconstruction): ancestors in `breadcrumbs`, self in `data`.
      if (url === '/disk/folders/deep') {
        return Promise.resolve({
          data: { id: 'deep', name: 'Deep' },
          breadcrumbs: [{ id: 'camp', name: 'Campaigns' }],
        });
      }
      return Promise.resolve({ data: [] }); // children of the level
    });

    const wrapper = mount(FolderPickerPanel, { props: { modelValue: 'deep' } });
    await flushPromises();

    // The trail reads Disk / Campaigns / Deep — the ancestor AND the folder itself.
    expect(wrapper.text()).toContain('Campaigns');
    expect(wrapper.text()).toContain('Deep');
    // And it fetched the ancestry for that preset folder.
    expect(apiMock.get).toHaveBeenCalledWith('/disk/folders/deep');
  });

  it('does NOT fetch ancestry when opened at the root (null)', async () => {
    apiMock.get.mockResolvedValue({ data: [] });
    const wrapper = mount(FolderPickerPanel, { props: { modelValue: null } });
    await flushPromises();

    // Only the children request — no breadcrumb reconstruction at the root.
    expect(apiMock.get).toHaveBeenCalledTimes(1);
    expect(apiMock.get).toHaveBeenCalledWith('/disk/folders');
    wrapper.unmount();
  });
});
