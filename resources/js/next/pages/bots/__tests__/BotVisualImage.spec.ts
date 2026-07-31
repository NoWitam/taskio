// @vitest-environment happy-dom
// BotVisualImage.spec — a bot-owned image served through the AUTH-GATED Disk route.
//
// The three things that would break silently: the bytes must be blob-fetched through the `api` client
// (a bare <img src> carries neither the Bearer nor the workspace header), the object URL must be revoked
// (a strip of six likenesses leaks six blobs per re-render otherwise), and a failed load must degrade to
// an in-tile message rather than a broken image.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setLocale } from '../../../app/i18n';
import { en } from '../../../app/i18n/en';

vi.mock('../../../app/lib/api', () => ({ api: { get: vi.fn() } }));

import { api } from '../../../app/lib/api';
import BotVisualImage from '../BotVisualImage.vue';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

const createObjectURL = vi.fn(() => 'blob:likeness');
const revokeObjectURL = vi.fn();

describe('BotVisualImage', () => {
  beforeEach(() => {
    setLocale('en');
    vi.clearAllMocks();
    vi.stubGlobal('URL', { createObjectURL, revokeObjectURL });
  });
  afterEach(() => vi.unstubAllGlobals());

  it('blob-fetches the inline serve route through the api client and paints the object URL', async () => {
    apiMock.get.mockResolvedValue(new Blob(['x']));
    const wrapper = mount(BotVisualImage, { props: { fileId: 'f-1', alt: 'Likeness' } });
    await flushPromises();

    expect(apiMock.get).toHaveBeenCalledWith('/disk/f-1?inline=1', { responseType: 'blob' });
    const img = wrapper.find('img');
    expect(img.attributes('src')).toBe('blob:likeness');
    expect(img.attributes('alt')).toBe('Likeness');
    wrapper.unmount();
  });

  it('revokes the previous object URL when the file id changes, and on unmount', async () => {
    apiMock.get.mockResolvedValue(new Blob(['x']));
    const wrapper = mount(BotVisualImage, { props: { fileId: 'f-1', alt: 'a' } });
    await flushPromises();

    await wrapper.setProps({ fileId: 'f-2' });
    await flushPromises();
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:likeness');
    expect(apiMock.get).toHaveBeenLastCalledWith('/disk/f-2?inline=1', { responseType: 'blob' });

    revokeObjectURL.mockClear();
    wrapper.unmount();
    expect(revokeObjectURL).toHaveBeenCalled();
  });

  it('shows an in-tile message (no broken image) when the bytes cannot be loaded', async () => {
    apiMock.get.mockRejectedValue(new Error('404'));
    const wrapper = mount(BotVisualImage, { props: { fileId: 'gone', alt: 'a' } });
    await flushPromises();

    expect(wrapper.find('img').exists()).toBe(false);
    expect(wrapper.text()).toContain(en.bots.editor.visual.candidates.loadError);
    wrapper.unmount();
  });
});
