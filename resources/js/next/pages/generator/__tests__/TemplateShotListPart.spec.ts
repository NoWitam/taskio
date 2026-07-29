// @vitest-environment happy-dom
// TemplateShotListPart.spec — the shot_list creative-BRIEF authoring (video_script Phase B). A shot_list is
// authored as ONE markdown brief (REUSES TemplateBodyPart). Asserts the helper caption, that the brief is
// bound into the editor, and that an editor edit bubbles the exact `{brief:{markdown}}` wire. MarkdownEditor
// is stubbed (TemplateBodyPart is the real child, so the markdown ↔ `{brief:{markdown}}` mapping is exercised).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import TemplateShotListPart from '../TemplateShotListPart.vue';
import type { ShotListContent } from '../types';

const stubs = {
  MarkdownEditor: {
    name: 'MarkdownEditor',
    props: ['modelValue', 'variables', 'ifBlocks', 'aiText', 'disabled', 'placeholder', 'ariaLabel', 'minHeight'],
    template: '<div class="md-stub" />',
  },
};

const variables = { variables: [], operationsCatalog: [], source: () => [], catalog: () => [] };
const aiText = { personas: [{ id: 'neutral', label: 'Neutral' }], labelsEnabled: false };
const ifBlocks = { maxDepth: 3 };

function mountPart(modelValue: ShotListContent | null) {
  return mount(TemplateShotListPart, {
    props: { modelValue, variables, aiText, ifBlocks },
    global: { stubs },
    attachTo: document.body,
  });
}

function lastEmit(wrapper: ReturnType<typeof mountPart>): ShotListContent {
  const events = wrapper.emitted('update:modelValue') as unknown[][] | undefined;
  return events![events!.length - 1][0] as ShotListContent;
}

describe('TemplateShotListPart', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders the brief helper caption (steers the AI shot-list generation)', () => {
    const wrapper = mountPart({ brief: { markdown: '' } });
    expect(wrapper.text()).toContain('steers the AI shot-list generation');
    wrapper.unmount();
  });

  it('binds the brief markdown into the editor and forwards the live variable feed', () => {
    const wrapper = mountPart({ brief: { markdown: 'A video about slots.topic' } });
    const editor = wrapper.findComponent({ name: 'MarkdownEditor' });
    expect(editor.props('modelValue')).toBe('A video about slots.topic');
    const fed = editor.props('variables') as typeof variables;
    expect(fed.source).toBe(variables.source);
    expect(fed.catalog).toBe(variables.catalog);
    wrapper.unmount();
  });

  it('maps an editor edit to the exact `{brief:{markdown}}` wire', async () => {
    const wrapper = mountPart({ brief: { markdown: '' } });
    wrapper.findComponent({ name: 'MarkdownEditor' }).vm.$emit('update:modelValue', 'Punchy 30s promo');
    await nextTick();
    expect(lastEmit(wrapper)).toEqual({ brief: { markdown: 'Punchy 30s promo' } });
    wrapper.unmount();
  });
});
