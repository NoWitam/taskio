// @vitest-environment happy-dom
// DiskThumbnail — a file tile's preview. Pins the three branches: an image file blob-fetches into
// an <img>, a text file shows a content snippet, and a non-previewable file (video/…) shows the
// glyph WITHOUT any network. The IntersectionObserver is faked to fire immediately so the deferred
// load runs synchronously; api + object-URL are mocked so no real HTTP/URLs happen.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

vi.mock('../../../app/lib/api', () => ({ api: { get: vi.fn() } }));

import { api } from '../../../app/lib/api';
import DiskThumbnail from '../DiskThumbnail.vue';
import type { DiskFile } from '../types';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

function file(overrides: Partial<DiskFile> = {}): DiskFile {
  return {
    id: 'f',
    name: 'file.bin',
    path: '/api/disk/f',
    type: 'document',
    size: 10,
    size_human: '10 B',
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
    has_draft: false,
    ...overrides,
  };
}

/** An IntersectionObserver that reports the target visible the moment it is observed. */
class ImmediateIO {
  private cb: IntersectionObserverCallback;
  constructor(cb: IntersectionObserverCallback) {
    this.cb = cb;
  }
  observe(el: Element): void {
    this.cb([{ isIntersecting: true, target: el } as IntersectionObserverEntry], this as unknown as IntersectionObserver);
  }
  disconnect(): void {}
  unobserve(): void {}
}

describe('DiskThumbnail', () => {
  beforeEach(() => {
    apiMock.get.mockReset();
    vi.stubGlobal('IntersectionObserver', ImmediateIO);
    (URL as unknown as { createObjectURL: unknown }).createObjectURL = vi.fn(() => 'blob:preview');
    (URL as unknown as { revokeObjectURL: unknown }).revokeObjectURL = vi.fn();
  });
  afterEach(() => vi.unstubAllGlobals());

  it('renders an image thumbnail (blob → object URL), fetched inline', async () => {
    apiMock.get.mockResolvedValue(new Blob(['x'], { type: 'image/png' }));
    const wrapper = mount(DiskThumbnail, { props: { file: file({ type: 'image', mime_type: 'image/png', name: 'p.png' }) } });
    await flushPromises();

    const img = wrapper.find('img');
    expect(img.exists()).toBe(true);
    expect(img.attributes('src')).toBe('blob:preview');
    expect(apiMock.get).toHaveBeenCalledWith(expect.stringContaining('inline=1'), { responseType: 'blob' });
  });

  it('renders a text snippet for a text file', async () => {
    apiMock.get.mockResolvedValue(new Blob(['Hello from a text file'], { type: 'text/plain' }));
    const wrapper = mount(DiskThumbnail, { props: { file: file({ type: 'text', mime_type: 'text/plain', name: 'a.txt' }) } });
    await flushPromises();

    expect(wrapper.find('img').exists()).toBe(false);
    expect(wrapper.text()).toContain('Hello from a text file');
  });

  it('shows the glyph and does NOT fetch for a non-previewable file (video)', async () => {
    const wrapper = mount(DiskThumbnail, { props: { file: file({ type: 'video', mime_type: 'video/mp4' }) } });
    await flushPromises();

    expect(apiMock.get).not.toHaveBeenCalled();
    expect(wrapper.find('img').exists()).toBe(false);
    expect(wrapper.find('svg').exists()).toBe(true); // the Icon glyph
  });

  it('renders a PDF page thumbnail from the thumbnail endpoint (object-contain, not the inline URL)', async () => {
    apiMock.get.mockResolvedValue(new Blob(['png-bytes'], { type: 'image/png' }));
    const wrapper = mount(DiskThumbnail, {
      props: { file: file({ type: 'document', mime_type: 'application/pdf', name: 'doc.pdf' }) },
    });
    await flushPromises();

    const img = wrapper.find('img');
    expect(img.exists()).toBe(true);
    expect(img.attributes('src')).toBe('blob:preview');
    // PDFs are page-shaped → contain (don't crop), unlike images (cover).
    expect(img.classes()).toContain('object-contain');
    // Fetched the dedicated thumbnail endpoint (by file id), NOT the inline serve URL.
    expect(apiMock.get).toHaveBeenCalledWith('/disk/f/thumbnail', { responseType: 'blob' });
  });

  it('content-addresses the PDF thumbnail URL with ?v=<updated_at> so a replaced file busts the browser cache', async () => {
    apiMock.get.mockResolvedValue(new Blob(['png-bytes'], { type: 'image/png' }));
    const wrapper = mount(DiskThumbnail, {
      props: {
        file: file({ type: 'document', mime_type: 'application/pdf', name: 'doc.pdf', updated_at_iso: '2026-07-21T10:00:00Z' }),
      },
    });
    await flushPromises();

    expect(wrapper.find('img').exists()).toBe(true);
    expect(apiMock.get).toHaveBeenCalledWith('/disk/f/thumbnail?v=2026-07-21T10%3A00%3A00Z', { responseType: 'blob' });
  });

  it('degrades a PDF to the type glyph when the thumbnail endpoint 404s (no <img>, no toast)', async () => {
    apiMock.get.mockRejectedValue({ response: { status: 404 } });
    const wrapper = mount(DiskThumbnail, {
      props: { file: file({ type: 'document', mime_type: 'application/pdf', name: 'doc.pdf' }) },
    });
    await flushPromises();

    expect(apiMock.get).toHaveBeenCalledWith('/disk/f/thumbnail', { responseType: 'blob' });
    expect(wrapper.find('img').exists()).toBe(false);
    expect(wrapper.find('svg').exists()).toBe(true); // the glyph
  });
});
