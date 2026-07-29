// @vitest-environment happy-dom
// TemplateStoryboardPart.spec — the storyboard authoring (video_script Phase B + the direction layer): an
// OPTIONAL style prompt + the SHARED filter chain (no base) + the OPTIONAL shot cap. Asserts the helper
// caption, that the style prompt binds to `style.markdown` and its edit bubbles the exact wire, that the
// reused TemplateFilterChain appends a filter into `filters`, and — the part with teeth — that `max_shots`
// is emitted ABSENT when cleared (never `0`, which the backend validator 422s) and clamped into 1..8.
// MarkdownEditor is stubbed (the style + any ai_edit prompt editors); TemplateFilterChain is REAL so the
// shared chain reuse is exercised end to end.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import TemplateStoryboardPart from '../TemplateStoryboardPart.vue';
import type { StoryboardContent } from '../types';

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

function mountPart(modelValue: StoryboardContent | null) {
  return mount(TemplateStoryboardPart, {
    props: { modelValue, variables, aiText, ifBlocks, fileSlots: [] },
    global: { stubs },
    attachTo: document.body,
  });
}

function lastEmit(wrapper: ReturnType<typeof mountPart>): StoryboardContent {
  const events = wrapper.emitted('update:modelValue') as unknown[][] | undefined;
  return events![events!.length - 1][0] as StoryboardContent;
}

describe('TemplateStoryboardPart', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders the style + filters helper caption', () => {
    const wrapper = mountPart({ style: { markdown: '' }, filters: [] });
    expect(wrapper.text()).toContain("each shot's on-screen visual becomes the image prompt");
    wrapper.unmount();
  });

  it('binds the style prompt to style.markdown and bubbles the exact wire on edit', async () => {
    const wrapper = mountPart({ style: { markdown: 'cinematic' }, filters: [] });
    const editor = wrapper.findComponent({ name: 'MarkdownEditor' });
    expect(editor.props('modelValue')).toBe('cinematic');
    editor.vm.$emit('update:modelValue', 'warm tones');
    await nextTick();
    expect(lastEmit(wrapper)).toEqual({ style: { markdown: 'warm tones' }, filters: [] });
    wrapper.unmount();
  });

  it('reuses the shared filter chain — "Add filter" appends into filters, style rides through', async () => {
    const wrapper = mountPart({ style: { markdown: 'cinematic' }, filters: [] });
    const addFilter = wrapper.findAll('button').find((b) => b.text().trim() === 'Add filter')!;
    await addFilter.trigger('click');
    const emitted = lastEmit(wrapper);
    expect(emitted.filters).toEqual([{ kind: 'pixel', op: 'grayscale' }]);
    expect(emitted.style).toEqual({ markdown: 'cinematic' });
    wrapper.unmount();
  });

  describe('max_shots (the optional shot cap)', () => {
    /** The one numeric field on the part. */
    function shotsInput(wrapper: ReturnType<typeof mountPart>) {
      return wrapper.get('input[type="number"]');
    }

    /** Type a raw value into the field ('' models clearing it). */
    async function type(wrapper: ReturnType<typeof mountPart>, raw: string) {
      const input = shotsInput(wrapper);
      (input.element as HTMLInputElement).value = raw;
      await input.trigger('input');
    }

    it('renders the labelled, bounded input with its help caption', () => {
      const wrapper = mountPart({ style: { markdown: '' }, filters: [] });
      const input = shotsInput(wrapper);
      expect(input.attributes('min')).toBe('1');
      expect(input.attributes('max')).toBe('8');
      expect(input.attributes('step')).toBe('1');
      expect(wrapper.text()).toContain('Max shots (optional)');
      expect(wrapper.text()).toContain('fewer shots means longer beats');
      wrapper.unmount();
    });

    it('is EMPTY when the recipe authored no cap (absent, not 0)', () => {
      const wrapper = mountPart({ style: { markdown: '' }, filters: [] });
      expect((shotsInput(wrapper).element as HTMLInputElement).value).toBe('');
      wrapper.unmount();
    });

    it('shows the authored cap and emits it into content.storyboard.max_shots', async () => {
      const wrapper = mountPart({ style: { markdown: 'cinematic' }, filters: [], max_shots: 3 });
      expect((shotsInput(wrapper).element as HTMLInputElement).value).toBe('3');

      await type(wrapper, '5');
      expect(lastEmit(wrapper)).toEqual({ style: { markdown: 'cinematic' }, filters: [], max_shots: 5 });
      wrapper.unmount();
    });

    it('CLEARING emits the key ABSENT — never 0 (a 0 would 422 server-side)', async () => {
      const wrapper = mountPart({ style: { markdown: 'cinematic' }, filters: [], max_shots: 4 });
      await type(wrapper, '');

      const emitted = lastEmit(wrapper);
      expect('max_shots' in emitted).toBe(false);
      expect(emitted).not.toHaveProperty('max_shots');
      expect(JSON.parse(JSON.stringify(emitted))).toEqual({ style: { markdown: 'cinematic' }, filters: [] });
      wrapper.unmount();
    });

    it('clamps below the floor up to 1 — a typed 0 never reaches the wire', async () => {
      const wrapper = mountPart({ style: { markdown: '' }, filters: [] });
      await type(wrapper, '0');
      expect(lastEmit(wrapper).max_shots).toBe(1);

      await type(wrapper, '-3');
      expect(lastEmit(wrapper).max_shots).toBe(1);
      wrapper.unmount();
    });

    it('clamps above the platform ceiling down to 8', async () => {
      const wrapper = mountPart({ style: { markdown: '' }, filters: [] });
      await type(wrapper, '99');
      expect(lastEmit(wrapper).max_shots).toBe(8);
      wrapper.unmount();
    });

    it('rounds a fractional value to a whole number of shots', async () => {
      const wrapper = mountPart({ style: { markdown: '' }, filters: [] });
      await type(wrapper, '3.6');
      expect(lastEmit(wrapper).max_shots).toBe(4);
      wrapper.unmount();
    });

    it('carries an authored cap through a STYLE edit (it is not dropped by an unrelated change)', async () => {
      const wrapper = mountPart({ style: { markdown: 'cinematic' }, filters: [], max_shots: 6 });
      wrapper.findComponent({ name: 'MarkdownEditor' }).vm.$emit('update:modelValue', 'warm tones');
      await nextTick();
      expect(lastEmit(wrapper)).toEqual({ style: { markdown: 'warm tones' }, filters: [], max_shots: 6 });
      wrapper.unmount();
    });

    it('carries an authored cap through a FILTER-chain edit', async () => {
      const wrapper = mountPart({ style: { markdown: 'cinematic' }, filters: [], max_shots: 2 });
      const addFilter = wrapper.findAll('button').find((b) => b.text().trim() === 'Add filter')!;
      await addFilter.trigger('click');
      const emitted = lastEmit(wrapper);
      expect(emitted.max_shots).toBe(2);
      expect(emitted.filters).toEqual([{ kind: 'pixel', op: 'grayscale' }]);
      wrapper.unmount();
    });
  });
});
