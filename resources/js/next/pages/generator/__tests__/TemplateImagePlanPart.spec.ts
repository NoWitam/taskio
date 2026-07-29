// @vitest-environment happy-dom
// TemplateImagePlanPart.spec — the base picker + ordered FILTER-CHAIN builder. Asserts the base kinds
// (with ai_generate now a LIVE option — sub-stage 6), that selecting ai_generate reveals a variable-fed
// prompt editor bound to `base.prompt`, and that adding / reordering pixel + ai_edit steps emits the EXACT
// backend wire. MarkdownEditor (the ai_generate base + ai_edit prompts) + DiskFilePickerModal are stubbed.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import TemplateImagePlanPart from '../TemplateImagePlanPart.vue';
import Select from '../../../ui/forms/Select.vue';
import type { ImagePlanContent } from '../types';

const stubs = {
  MarkdownEditor: {
    name: 'MarkdownEditor',
    props: ['modelValue', 'variables', 'ifBlocks', 'aiText'],
    template: '<div class="md-stub" />',
  },
  DiskFilePickerModal: { name: 'DiskFilePickerModal', props: ['open'], template: '<div />' },
};

const variables = { variables: [], operationsCatalog: [], source: () => [], catalog: () => [] };
const ifBlocks = { maxDepth: 3 };
const aiText = { personas: [{ id: 'neutral', label: 'Neutral' }], labelsEnabled: false };

function mountPart(modelValue: ImagePlanContent | null, fileSlots: string[] = []) {
  return mount(TemplateImagePlanPart, {
    props: { modelValue, fileSlots, variables, ifBlocks, aiText },
    global: { stubs },
    attachTo: document.body,
  });
}

function lastEmit(wrapper: ReturnType<typeof mountPart>): ImagePlanContent {
  const events = wrapper.emitted('update:modelValue') as unknown[][] | undefined;
  return events![events!.length - 1][0] as ImagePlanContent;
}

function addButton(wrapper: ReturnType<typeof mountPart>, label: string) {
  return wrapper.findAll('button').find((b) => b.text().trim() === label)!;
}

describe('TemplateImagePlanPart', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('offers the three base kinds with ai_generate a LIVE (enabled) option', () => {
    const wrapper = mountPart({ base: null, filters: [] });
    const options = wrapper.findAllComponents(Select)[0].props('options') as Array<{ value: string; disabled?: boolean }>;
    expect(options.map((o) => o.value)).toEqual(['disk_file', 'from_slot', 'ai_generate']);
    expect(options.find((o) => o.value === 'ai_generate')?.disabled).toBeFalsy();
    wrapper.unmount();
  });

  it('picks an ai_generate base, seeding an empty prompt (the exact wire)', async () => {
    const wrapper = mountPart({ base: null, filters: [] });
    // No prompt editor until ai_generate is the chosen base.
    expect(wrapper.findComponent({ name: 'MarkdownEditor' }).exists()).toBe(false);

    wrapper.findAllComponents(Select)[0].vm.$emit('update:modelValue', 'ai_generate');
    await nextTick();
    expect(lastEmit(wrapper).base).toEqual({ kind: 'ai_generate', prompt: '' });
    wrapper.unmount();
  });

  it('reveals a variable-fed prompt editor for ai_generate, forwarding the LIVE feed', async () => {
    const wrapper = mountPart({ base: { kind: 'ai_generate', prompt: 'A wide shot of slots.topic' }, filters: [] });
    const editor = wrapper.findComponent({ name: 'MarkdownEditor' });
    expect(editor.exists()).toBe(true);
    // Bound to base.prompt, and fed the SAME variable feature — crucially its source()/catalog() LIVE
    // getters (the AiTextChip fix pattern), so the prompt offers the template's SLOTS, not just globals.
    expect(editor.props('modelValue')).toBe('A wide shot of slots.topic');
    const fed = editor.props('variables') as typeof variables;
    expect(fed.source).toBe(variables.source);
    expect(fed.catalog).toBe(variables.catalog);
    wrapper.unmount();
  });

  it('unlocks the if-block + ai-text features on the ai_generate prompt editor (like the body)', () => {
    const wrapper = mountPart({ base: { kind: 'ai_generate', prompt: '' }, filters: [] });
    const editor = wrapper.findComponent({ name: 'MarkdownEditor' });
    expect(editor.props('ifBlocks')).toEqual(ifBlocks);
    expect(editor.props('aiText')).toEqual(aiText);
    wrapper.unmount();
  });

  it('unlocks the if-block + ai-text features on the ai_edit filter prompt editor (like the body)', () => {
    const wrapper = mountPart(
      { base: { kind: 'from_slot', slot: 'hero' }, filters: [{ kind: 'ai_edit', prompt: '' }] },
      ['hero'],
    );
    const editor = wrapper.findComponent({ name: 'MarkdownEditor' });
    expect(editor.props('ifBlocks')).toEqual(ifBlocks);
    expect(editor.props('aiText')).toEqual(aiText);
    wrapper.unmount();
  });

  it('binds the ai_generate prompt editor to base.prompt', async () => {
    const wrapper = mountPart({ base: { kind: 'ai_generate', prompt: '' }, filters: [] });
    wrapper.findComponent({ name: 'MarkdownEditor' }).vm.$emit('update:modelValue', 'A cat in slots.style');
    await nextTick();
    expect(lastEmit(wrapper).base).toEqual({ kind: 'ai_generate', prompt: 'A cat in slots.style' });
    wrapper.unmount();
  });

  it('picks a from_slot base, emitting the exact wire', async () => {
    const wrapper = mountPart({ base: null, filters: [] }, ['hero']);
    wrapper.findAllComponents(Select)[0].vm.$emit('update:modelValue', 'from_slot');
    await nextTick();
    expect(lastEmit(wrapper).base).toEqual({ kind: 'from_slot', slot: null });
    wrapper.unmount();
  });

  it('adds a pixel op then an ai_edit, emitting the exact ordered chain', async () => {
    const wrapper = mountPart({ base: { kind: 'from_slot', slot: 'hero' }, filters: [] }, ['hero']);

    // Default op-to-add is grayscale — "Add filter" appends it.
    await addButton(wrapper, 'Add filter').trigger('click');
    let plan = lastEmit(wrapper);
    expect(plan.filters).toEqual([{ kind: 'pixel', op: 'grayscale' }]);

    // Feed the emit back (v-model), then add an AI edit.
    await wrapper.setProps({ modelValue: plan });
    await addButton(wrapper, 'Add AI edit').trigger('click');
    plan = lastEmit(wrapper);
    expect(plan.filters).toEqual([{ kind: 'pixel', op: 'grayscale' }, { kind: 'ai_edit', prompt: '' }]);
    // The base rode through untouched.
    expect(plan.base).toEqual({ kind: 'from_slot', slot: 'hero' });

    wrapper.unmount();
  });

  it('reorders the chain (move down)', async () => {
    const wrapper = mountPart({
      base: { kind: 'from_slot', slot: 'hero' },
      filters: [{ kind: 'pixel', op: 'grayscale' }, { kind: 'pixel', op: 'invert' }],
    }, ['hero']);

    await wrapper.find('button[aria-label="Move down"]').trigger('click');
    const plan = lastEmit(wrapper);
    expect(plan.filters).toEqual([{ kind: 'pixel', op: 'invert' }, { kind: 'pixel', op: 'grayscale' }]);

    wrapper.unmount();
  });
});
