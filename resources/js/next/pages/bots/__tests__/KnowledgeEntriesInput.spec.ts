// @vitest-environment happy-dom
// Unit tests for KnowledgeEntriesInput (Batch 6): the repeatable {title, content}
// knowledge editor. Asserts add/remove rows, in-place title/content edits, the
// per-row error surfacing, and that v-model always emits the {title, content}[]
// shape. No real HTTP — a pure component test.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import KnowledgeEntriesInput from '../KnowledgeEntriesInput.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { BotKnowledgeEntry } from '../types';

describe('KnowledgeEntriesInput', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  function lastModel(wrapper: ReturnType<typeof mount>): BotKnowledgeEntry[] {
    const emitted = wrapper.emitted('update:modelValue');
    return (emitted?.[emitted.length - 1]?.[0] ?? []) as BotKnowledgeEntry[];
  }

  it('renders the empty note + an add button when there are no entries', () => {
    const wrapper = mount(KnowledgeEntriesInput, { props: { modelValue: [] } });
    // The add affordance is always available (empty is valid).
    const buttons = wrapper.findAll('button');
    expect(buttons.length).toBeGreaterThanOrEqual(1);
    expect(wrapper.text().length).toBeGreaterThan(0);
  });

  it('add appends a blank {title, content} entry to the model', async () => {
    const wrapper = mount(KnowledgeEntriesInput, { props: { modelValue: [] } });
    // The last button is the "Add entry" action.
    const buttons = wrapper.findAll('button');
    await buttons[buttons.length - 1].trigger('click');
    await nextTick();

    expect(lastModel(wrapper)).toEqual([{ title: '', content: '' }]);
  });

  it('remove drops the row at its index', async () => {
    const wrapper = mount(KnowledgeEntriesInput, {
      props: {
        modelValue: [
          { title: 'A', content: 'a' },
          { title: 'B', content: 'b' },
        ],
      },
    });
    // Each row's first button is its remove control.
    const removeButtons = wrapper.findAll('button').filter((b) => b.text() === '');
    // The very first icon-only button belongs to row 0.
    await wrapper.findAll('[role="group"] button')[0].trigger('click');
    await nextTick();

    expect(lastModel(wrapper)).toEqual([{ title: 'B', content: 'b' }]);
    expect(removeButtons.length).toBeGreaterThanOrEqual(2);
  });

  it('editing a title emits the updated {title, content}[] shape', async () => {
    const wrapper = mount(KnowledgeEntriesInput, {
      props: { modelValue: [{ title: '', content: '' }] },
    });
    const input = wrapper.find('input');
    await input.setValue('Brand voice');
    await nextTick();

    expect(lastModel(wrapper)).toEqual([{ title: 'Brand voice', content: '' }]);
  });

  it('editing content emits the updated shape', async () => {
    const wrapper = mount(KnowledgeEntriesInput, {
      props: { modelValue: [{ title: 'T', content: '' }] },
    });
    const textarea = wrapper.find('textarea');
    await textarea.setValue('Always upbeat.');
    await nextTick();

    expect(lastModel(wrapper)).toEqual([{ title: 'T', content: 'Always upbeat.' }]);
  });

  it('surfaces a per-row title error from entryErrors', () => {
    const wrapper = mount(KnowledgeEntriesInput, {
      props: {
        modelValue: [{ title: '', content: 'x' }],
        entryErrors: { 0: { title: 'A title is required.' } },
      },
    });
    expect(wrapper.text()).toContain('A title is required.');
  });

  it('disables adding when the max cap is reached', () => {
    const entries = Array.from({ length: 2 }, (_, i) => ({ title: `t${i}`, content: 'c' }));
    const wrapper = mount(KnowledgeEntriesInput, {
      props: { modelValue: entries, max: 2 },
    });
    const buttons = wrapper.findAll('button');
    const addBtn = buttons[buttons.length - 1];
    expect(addBtn.attributes('disabled')).toBeDefined();
  });
});
