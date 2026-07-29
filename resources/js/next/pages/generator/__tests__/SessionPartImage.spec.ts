// @vitest-environment happy-dom
// SessionPartImage.spec — regression for the stale-image bug (R2 sub-stage 2 consolidation review):
// a per-part regenerate/refine/undo bumps `image.version` while sessionId/partKey stay put, and the
// serve URL is version-agnostic (the controller resolves the CURRENT version server-side). The component
// MUST refetch when the version changes and carry the version as a cache-buster — otherwise the
// "Wersja N" badge advances but the picture keeps the stale bytes.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

// The produced image is blob-fetched through the api singleton — mock it to a PNG blob.
vi.mock('../../../app/lib/api', () => ({
  api: { get: vi.fn(async () => new Blob([], { type: 'image/png' })) },
}));

import { api } from '../../../app/lib/api';
import SessionPartImage from '../session/SessionPartImage.vue';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

function mountImage(props: Record<string, unknown>) {
  return mount(SessionPartImage, {
    props: { sessionId: 's1', partKey: 'image', alt: 'Produced image', ...props },
    attachTo: document.body,
  });
}

describe('SessionPartImage', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
    apiMock.get.mockResolvedValue(new Blob([], { type: 'image/png' }));
    // happy-dom lacks object-URL helpers; stub them so the blob→objectURL path resolves.
    URL.createObjectURL = vi.fn(() => 'blob:mock');
    URL.revokeObjectURL = vi.fn();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('refetches with a version cache-buster when image.version changes (regression)', async () => {
    const wrapper = mountImage({
      image: { mime: 'image/png', width: 100, height: 80, version: 1 },
    });
    await flushPromises();

    expect(apiMock.get).toHaveBeenCalledTimes(1);
    expect(apiMock.get).toHaveBeenLastCalledWith(
      '/generator/sessions/s1/parts/image/image?v=1',
      { responseType: 'blob' },
    );

    // A per-part refine/regenerate/undo bumps the version — the picture MUST refetch.
    await wrapper.setProps({ image: { mime: 'image/png', width: 100, height: 80, version: 2 } });
    await flushPromises();

    expect(apiMock.get).toHaveBeenCalledTimes(2);
    expect(apiMock.get).toHaveBeenLastCalledWith(
      '/generator/sessions/s1/parts/image/image?v=2',
      { responseType: 'blob' },
    );
    wrapper.unmount();
  });

  it('omits the version param when no version is known (back-compat)', async () => {
    const wrapper = mountImage({ image: { mime: 'image/png', width: 100, height: 80 } });
    await flushPromises();

    expect(apiMock.get).toHaveBeenLastCalledWith(
      '/generator/sessions/s1/parts/image/image',
      { responseType: 'blob' },
    );
    wrapper.unmount();
  });
});
