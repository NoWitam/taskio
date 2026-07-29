// @vitest-environment happy-dom
// TemplatePreview.spec — the FAITHFUL, PER-PART preview pane. Asserts it (debounced) calls the server
// preview with {content_type, content, slots, slot_values} — the sample values seeded per slot descriptor
// — and renders each PART's result by kind: text → MarkdownViewer, image → a plan card, scene → a
// per-scene summary. The store is mocked; the debounce runs on fake timers.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const h = vi.hoisted(() => ({
  store: { preview: vi.fn().mockResolvedValue({ parts: {} }) },
}));

vi.mock('../../../app/stores/templates', () => ({ useTemplatesStore: () => h.store }));

import TemplatePreview from '../TemplatePreview.vue';

const MarkdownViewerStub = { name: 'MarkdownViewer', props: ['source'], template: '<div class="mv">{{ source }}</div>' };

const BODY_PART = { key: 'body', kind: 'text_body' as const, label: 'Post body', required: true, config: {} };
const IMAGE_PART = { key: 'image', kind: 'image_plan' as const, label: 'Image', required: false, config: {} };
const SCENE_PART = { key: 'scene_plan', kind: 'scene_plan' as const, label: 'Scene plan', required: false, config: {} };

describe('TemplatePreview', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('debounced-calls the server preview with the draft + sample values, then renders the text part', async () => {
    h.store.preview.mockResolvedValue({ parts: { body: { rendered: 'Rendered: Cats' } } });
    const wrapper = mount(TemplatePreview, {
      props: {
        contentType: 'post',
        content: { body: { markdown: 'Write about @[variable]("{}")' } },
        parts: [BODY_PART],
        slots: [{ name: 'topic', descriptor: { base: 'text', nullable: false, array: false } }],
      },
      global: { stubs: { MarkdownViewer: MarkdownViewerStub } },
      attachTo: document.body,
    });

    await vi.advanceTimersByTimeAsync(600);
    await nextTick();

    expect(h.store.preview).toHaveBeenCalledTimes(1);
    const request = h.store.preview.mock.calls[0][0];
    expect(request.content_type).toBe('post');
    expect(request.content).toEqual({ body: { markdown: 'Write about @[variable]("{}")' } });
    expect(request.slots).toEqual([{ name: 'topic', descriptor: { base: 'text', nullable: false, array: false } }]);
    expect(request.slot_values).toHaveProperty('topic');

    expect(wrapper.find('.mv').text()).toContain('Rendered: Cats');

    wrapper.unmount();
  });

  it('renders a nested typed input per OBJECT field and posts the nested {field: value} sample', async () => {
    const wrapper = mount(TemplatePreview, {
      props: {
        contentType: 'post',
        content: {},
        parts: [BODY_PART],
        slots: [
          {
            name: 'product',
            descriptor: {
              base: 'object',
              nullable: false,
              array: false,
              fields: [
                { key: 'name', label: 'Name', descriptor: { base: 'text', nullable: false, array: false } },
                { key: 'price', label: 'Price', descriptor: { base: 'number', nullable: false, array: false } },
              ],
            },
          },
        ],
      },
      global: { stubs: { MarkdownViewer: MarkdownViewerStub } },
      attachTo: document.body,
    });

    await vi.advanceTimersByTimeAsync(600);
    await nextTick();

    expect(wrapper.text()).toContain('Name');
    expect(wrapper.text()).toContain('Price');

    const request = h.store.preview.mock.calls[0][0];
    expect(request.slot_values.product).toEqual({ name: '', price: null });

    wrapper.unmount();
  });

  it('renders the fixed FILE subfields and posts an {id,name,type,size,url} snapshot', async () => {
    const wrapper = mount(TemplatePreview, {
      props: {
        contentType: 'post',
        content: {},
        parts: [BODY_PART],
        slots: [
          {
            name: 'attachment',
            descriptor: {
              base: 'file',
              nullable: false,
              array: false,
              fields: [
                { key: 'id', label: 'id', descriptor: { base: 'text', nullable: false, array: false } },
                { key: 'name', label: 'name', descriptor: { base: 'text', nullable: false, array: false } },
                { key: 'type', label: 'type', descriptor: { base: 'text', nullable: false, array: false } },
                { key: 'size', label: 'size', descriptor: { base: 'number', nullable: false, array: false } },
                { key: 'url', label: 'url', descriptor: { base: 'text', nullable: false, array: false } },
              ],
            },
          },
        ],
      },
      global: { stubs: { MarkdownViewer: MarkdownViewerStub } },
      attachTo: document.body,
    });

    await vi.advanceTimersByTimeAsync(600);
    await nextTick();

    expect(wrapper.text()).toContain('Size');
    expect(wrapper.text()).toContain('URL');

    const request = h.store.preview.mock.calls[0][0];
    expect(request.slot_values.attachment).toEqual({ id: '', name: '', type: '', size: null, url: '' });

    wrapper.unmount();
  });

  it('renders an image-plan PLAN card (base + chain) and a scene summary', async () => {
    h.store.preview.mockResolvedValue({
      parts: {
        image: {
          plan: {
            base: { kind: 'from_slot', label: 'From slot: hero', slot: 'hero' },
            filters: [{ kind: 'pixel', op: 'grayscale', label: 'grayscale' }],
          },
        },
        scene_plan: {
          plan: { scenes: [{ narration: 'Scene one narration', image: null }] },
        },
      },
    });

    const wrapper = mount(TemplatePreview, {
      props: {
        contentType: 'video_script',
        content: {},
        parts: [IMAGE_PART, SCENE_PART],
        slots: [],
      },
      global: { stubs: { MarkdownViewer: MarkdownViewerStub } },
      attachTo: document.body,
    });

    await vi.advanceTimersByTimeAsync(600);
    await nextTick();

    // The image plan card localizes the base kind + shows the slot ref, and the pixel op label.
    expect(wrapper.text()).toContain('slots.hero');
    expect(wrapper.text()).toContain('Grayscale');
    // The scene narration renders through the viewer.
    expect(wrapper.text()).toContain('Scene one narration');

    wrapper.unmount();
  });

  it('shows the ai-text "generated at run time" legend', () => {
    const wrapper = mount(TemplatePreview, {
      props: { contentType: 'post', content: {}, parts: [], slots: [] },
      global: { stubs: { MarkdownViewer: MarkdownViewerStub } },
      attachTo: document.body,
    });

    expect(wrapper.text()).toContain('generated at run time');

    wrapper.unmount();
  });
});
