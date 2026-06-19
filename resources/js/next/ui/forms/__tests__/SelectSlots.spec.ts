// @vitest-environment happy-dom
// SelectSlots.spec.ts — the custom-render slots added for the global wrappers:
// #option / #chip / #value. Asserts the DEFAULT (slot-less) rendering is
// unchanged AND that a provided slot replaces it.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import Select from '../Select.vue';
import { installBrowserMocks, restoreBrowserMocks, installSyncRaf } from '../../../__tests__/helpers/dom';

const OPTIONS = [
  { value: 'a', label: 'Alpha' },
  { value: 'b', label: 'Beta' },
  { value: 'c', label: 'Gamma' },
];

describe('Select custom-render slots', () => {
  beforeEach(() => {
    installBrowserMocks();
    installSyncRaf();
  });
  afterEach(() => restoreBrowserMocks());

  it('renders default removable Badge chips when no #chip slot is given', () => {
    const wrapper = mount(Select, {
      props: { multiple: true, options: OPTIONS, values: ['a', 'b'] },
    });
    // The default chips are Badges; each shows its label text.
    expect(wrapper.text()).toContain('Alpha');
    expect(wrapper.text()).toContain('Beta');
    // Default chips carry the shared next-badge class.
    expect(wrapper.find('.next-badge').exists()).toBe(true);
  });

  it('renders #chip slot content instead of the default Badge', () => {
    const wrapper = mount(Select, {
      props: { multiple: true, options: OPTIONS, values: ['a'] },
      slots: {
        chip: `<template #chip="{ option }"><span class="custom-chip">{{ option.label }}!</span></template>`,
      },
    });
    const custom = wrapper.find('.custom-chip');
    expect(custom.exists()).toBe(true);
    expect(custom.text()).toBe('Alpha!');
  });

  it('renders #value slot content for the single-select trigger', () => {
    const wrapper = mount(Select, {
      props: { options: OPTIONS, modelValue: 'c' },
      slots: {
        value: `<template #value="{ option }"><span class="custom-value">{{ option.label }}#</span></template>`,
      },
    });
    const custom = wrapper.find('.custom-value');
    expect(custom.exists()).toBe(true);
    expect(custom.text()).toBe('Gamma#');
  });

  it('renders #option slot content in the open listbox', async () => {
    const wrapper = mount(Select, {
      attachTo: document.body,
      props: { options: OPTIONS },
      slots: {
        option: `<template #option="{ option }"><span class="custom-option">[{{ option.label }}]</span></template>`,
      },
    });
    // Open the popover.
    await wrapper.find('[role="combobox"]').trigger('click');
    await nextTick();
    const opts = document.body.querySelectorAll('.custom-option');
    expect(opts.length).toBe(3);
    expect(opts[0].textContent).toBe('[Alpha]');
    wrapper.unmount();
  });
});
