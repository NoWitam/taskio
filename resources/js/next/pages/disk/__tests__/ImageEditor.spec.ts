// @vitest-environment happy-dom
// ImageEditor — a render smoke test (the pixel math is covered by imageOps.spec). Pins that the
// transform toolbar + the four classic filters render and the AI slot is present-but-disabled.
import { describe, it, expect, beforeEach, afterEach, beforeAll, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../../app/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => ({ danger: vi.fn(), success: vi.fn() }) }));

import { api } from '../../../app/lib/api';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import ImageEditor from '../ImageEditor.vue';
import type { DiskFile } from '../types';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

function imageFile(): DiskFile {
  return {
    id: 'f1', name: 'hero.png', path: '/api/disk/f1', type: 'image', size: 10, size_human: '10 B',
    created_at: '2026-07-17 10:00', description: null, mime_type: 'image/png', folder_id: null,
    source: 'disk', created_at_iso: null, updated_at_iso: null, disk_trashed_at: null,
    labels: [], can_be_updated: true, can_be_moved: true, can_be_deleted: true,
    can_be_restored: true, can_be_force_deleted: true,
  };
}

describe('ImageEditor', () => {
  beforeAll(() => setLocale('en'));
  beforeEach(() => {
    setActivePinia(createPinia());
    installBrowserMocks();
    apiMock.get.mockReset().mockResolvedValue(new Blob(['x']));
    if (!URL.createObjectURL) URL.createObjectURL = () => 'blob:x';
    if (!URL.revokeObjectURL) URL.revokeObjectURL = () => {};
  });
  afterEach(() => restoreBrowserMocks());

  it('renders the classic filter set and a disabled AI slot', () => {
    const wrapper = mount(ImageEditor, { attachTo: document.body, props: { open: true, file: imageFile() } });

    const text = document.body.textContent ?? '';
    for (const label of ['Original', 'Grayscale', 'Sepia', 'Negative']) {
      expect(text).toContain(label);
    }

    // The AI-filter button is present but disabled (it lands in R2).
    const aiBtn = Array.from(document.body.querySelectorAll('button')).find(
      (b) => (b.textContent ?? '').includes('AI filter'),
    );
    expect(aiBtn?.disabled).toBe(true);
    wrapper.unmount();
  });
});
