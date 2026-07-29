// @vitest-environment happy-dom
// TemplateEditorDrawer.spec — the DATA-DRIVEN create / edit form. Asserts:
//  (A) the editor FETCHES the live catalog and FEEDS `slots.<name>` + operations to the shared
//      MarkdownEditor (through the body part),
//  (B) it passes the chosen content type + content + draft slots to the preview pane,
//  (C) create STEP 1 is the content-type picker, and picking a type renders sections BY KIND (an
//      image_plan part → the image-plan builder, never a hard-coded layout).
// MarkdownEditor / TemplatePreview / the image + scene parts are stubbed so each test targets the wiring.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const POST_TYPE = {
  id: 'post',
  label: 'Post',
  parts: [{ key: 'body', kind: 'text_body', label: 'Post body', required: true, config: {} }],
};
const IMAGE_TYPE = {
  id: 'custom_image',
  label: 'Image only',
  parts: [{ key: 'img', kind: 'image_plan', label: 'Image', required: true, config: {} }],
};

const h = vi.hoisted(() => ({
  store: {
    fetchContentTypes: vi.fn(),
    fetchCatalog: vi.fn().mockResolvedValue({
      variables: [
        { source: 'slots', path: 'slots.topic', name: 'topic', type: 'text', descriptor: { base: 'text', nullable: false, array: false } },
      ],
      operations: [{ id: 'fn:abc', input: 'text', output: 'text', args: [], label: 'My Function' }],
      types: [],
    }),
    preview: vi.fn().mockResolvedValue({ parts: {} }),
  },
}));

vi.mock('../../../app/stores/templates', () => ({ useTemplatesStore: () => h.store }));

import TemplateEditorDrawer from '../TemplateEditorDrawer.vue';
import ContentTypePicker from '../ContentTypePicker.vue';

const MarkdownEditorStub = {
  name: 'MarkdownEditor',
  props: ['variables', 'modelValue', 'ifBlocks', 'aiText', 'placeholder', 'ariaLabel', 'minHeight', 'disabled'],
  template: '<div class="md-stub" />',
};
const TemplatePreviewStub = {
  name: 'TemplatePreview',
  props: ['contentType', 'content', 'parts', 'slots'],
  template: '<div class="preview-stub" />',
};
const ImagePartStub = { name: 'TemplateImagePlanPart', props: ['modelValue', 'fileSlots', 'variables', 'submitting'], template: '<div class="image-part-stub" />' };
const ScenePartStub = { name: 'TemplateScenePlanPart', props: ['modelValue', 'variables', 'aiText', 'ifBlocks', 'fileSlots', 'submitting'], template: '<div class="scene-part-stub" />' };

const stubs = {
  MarkdownEditor: MarkdownEditorStub,
  TemplatePreview: TemplatePreviewStub,
  TemplateImagePlanPart: ImagePartStub,
  TemplateScenePlanPart: ScenePartStub,
};

const editTemplate = {
  id: 't1',
  name: 'Launch',
  description: null,
  content_type: 'post',
  slots: [{ name: 'topic', descriptor: { base: 'text', nullable: false, array: false } }],
  content: { body: { markdown: 'Body text' } },
  creator: null,
  is_owner: true,
  can_be_edited: true,
  can_be_deleted: true,
  created_at: null,
  updated_at: null,
};

describe('TemplateEditorDrawer', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
    h.store.fetchContentTypes.mockResolvedValue([POST_TYPE, IMAGE_TYPE]);
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('fetches the catalog on open and feeds slots.<name> + operations to MarkdownEditor', async () => {
    const wrapper = mount(TemplateEditorDrawer, {
      props: { open: true, template: editTemplate },
      global: { stubs },
      attachTo: document.body,
    });

    await flushPromises();
    await nextTick();

    expect(h.store.fetchContentTypes).toHaveBeenCalled();
    expect(h.store.fetchCatalog).toHaveBeenCalled();

    const feature = wrapper.getComponent(MarkdownEditorStub).props('variables') as {
      variables: Array<{ id: string }>;
      operationsCatalog: Array<{ id: string; label: string }>;
      source: () => Array<{ path: string; source: string }>;
    };

    expect(feature.variables.some((d) => d.id === 'slots.topic')).toBe(true);
    expect(feature.source().find((v) => v.path === 'slots.topic')?.source).toBe('slots');
    expect(feature.operationsCatalog.some((op) => op.id === 'fn:abc' && op.label === 'My Function')).toBe(true);

    wrapper.unmount();
  });

  it('passes the content type, content and draft slots to the preview pane', async () => {
    const wrapper = mount(TemplateEditorDrawer, {
      props: { open: true, template: editTemplate },
      global: { stubs },
      attachTo: document.body,
    });

    await flushPromises();
    await nextTick();

    const preview = wrapper.getComponent(TemplatePreviewStub);
    expect(preview.props('contentType')).toBe('post');
    expect(preview.props('parts')).toHaveLength(1);
    expect(preview.props('slots')).toEqual([{ name: 'topic', descriptor: { base: 'text', nullable: false, array: false } }]);

    wrapper.unmount();
  });

  it('shows the content-type picker on create, then renders sections BY KIND', async () => {
    const wrapper = mount(TemplateEditorDrawer, {
      props: { open: true, template: null },
      global: { stubs },
      attachTo: document.body,
    });

    await flushPromises();
    await nextTick();

    // STEP 1: the picker is shown, no body editor yet.
    const picker = wrapper.getComponent(ContentTypePicker);
    expect(picker.props('contentTypes')).toHaveLength(2);
    expect(wrapper.findComponent(MarkdownEditorStub).exists()).toBe(false);

    // Pick the image-only type → the image_plan section (the builder) renders, keyed by KIND.
    picker.vm.$emit('select', 'custom_image');
    await nextTick();

    expect(wrapper.findComponent(ImagePartStub).exists()).toBe(true);
    expect(wrapper.findComponent(ContentTypePicker).exists()).toBe(false);

    wrapper.unmount();
  });
});
