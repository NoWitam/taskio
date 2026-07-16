// @vitest-environment happy-dom
// Tabs.spec.ts — roving keyboard nav (←/→/Home/End skipping disabled), selection
// + aria-selected, and controlled vs uncontrolled behavior.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import Tabs from '../Tabs.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const ITEMS = [
  { value: 'a', label: 'Alpha' },
  { value: 'b', label: 'Beta', disabled: true },
  { value: 'c', label: 'Gamma' },
  { value: 'd', label: 'Delta' },
];

function tabButtons(wrapper: ReturnType<typeof mount>) {
  return wrapper.findAll('[role="tab"]');
}

describe('Tabs', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('mounts and selects the first enabled tab when uncontrolled', () => {
    const wrapper = mount(Tabs, { props: { items: ITEMS, ariaLabel: 'Sections' } });
    const tabs = tabButtons(wrapper);
    expect(tabs).toHaveLength(4);
    expect(tabs[0].attributes('aria-selected')).toBe('true'); // first enabled
    expect(tabs[0].attributes('tabindex')).toBe('0'); // roving: active is the stop
    expect(tabs[2].attributes('tabindex')).toBe('-1');
  });

  it('clicking a tab selects it and updates aria-selected (uncontrolled)', async () => {
    const wrapper = mount(Tabs, { props: { items: ITEMS } });
    await tabButtons(wrapper)[2].trigger('click'); // Gamma
    expect(tabButtons(wrapper)[2].attributes('aria-selected')).toBe('true');
    expect(tabButtons(wrapper)[0].attributes('aria-selected')).toBe('false');
  });

  it('does not select a disabled tab on click', async () => {
    const wrapper = mount(Tabs, { props: { items: ITEMS } });
    await tabButtons(wrapper)[1].trigger('click'); // Beta (disabled)
    expect(tabButtons(wrapper)[0].attributes('aria-selected')).toBe('true'); // unchanged
  });

  it('ArrowRight moves to the next ENABLED tab (skips disabled)', async () => {
    const wrapper = mount(Tabs, { props: { items: ITEMS } });
    const list = wrapper.get('[role="tablist"]');
    await list.trigger('keydown', { key: 'ArrowRight' });
    await nextTick();
    // From 'a' → skip disabled 'b' → 'c'.
    expect(tabButtons(wrapper)[2].attributes('aria-selected')).toBe('true');
  });

  it('ArrowLeft wraps around to the last tab', async () => {
    const wrapper = mount(Tabs, { props: { items: ITEMS } });
    const list = wrapper.get('[role="tablist"]');
    await list.trigger('keydown', { key: 'ArrowLeft' });
    await nextTick();
    expect(tabButtons(wrapper)[3].attributes('aria-selected')).toBe('true'); // wrap to 'd'
  });

  it('Home selects first enabled, End selects last', async () => {
    const wrapper = mount(Tabs, { props: { items: ITEMS } });
    const list = wrapper.get('[role="tablist"]');
    await list.trigger('keydown', { key: 'End' });
    await nextTick();
    expect(tabButtons(wrapper)[3].attributes('aria-selected')).toBe('true');
    await list.trigger('keydown', { key: 'Home' });
    await nextTick();
    expect(tabButtons(wrapper)[0].attributes('aria-selected')).toBe('true');
  });

  it('manual activation does NOT select on arrow-move (only focus moves)', async () => {
    const wrapper = mount(Tabs, { props: { items: ITEMS, activation: 'manual' } });
    const list = wrapper.get('[role="tablist"]');
    await list.trigger('keydown', { key: 'ArrowRight' });
    await nextTick();
    // In manual mode arrow keys move focus but must NOT change the selection.
    expect(tabButtons(wrapper)[0].attributes('aria-selected')).toBe('true');
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    // A direct click still selects in manual mode.
    await tabButtons(wrapper)[2].trigger('click');
    expect(tabButtons(wrapper)[2].attributes('aria-selected')).toBe('true');
  });

  it('respects a controlled v-model and emits updates', async () => {
    const wrapper = mount(Tabs, {
      props: {
        items: ITEMS,
        modelValue: 'c',
        'onUpdate:modelValue': (v: string) => wrapper.setProps({ modelValue: v }),
      },
    });
    expect(tabButtons(wrapper)[2].attributes('aria-selected')).toBe('true');
    await tabButtons(wrapper)[3].trigger('click');
    const emits = wrapper.emitted('update:modelValue');
    expect(emits?.[emits.length - 1]).toEqual(['d']);
  });

  it('renders the active panel via the scoped #panel slot', () => {
    const wrapper = mount(Tabs, {
      props: { items: ITEMS },
      slots: { panel: `<template #panel="{ value }">Panel-{{ value }}</template>` },
    });
    const panels = wrapper.findAll('[role="tabpanel"]');
    const active = panels.find((p) => p.attributes('hidden') === undefined);
    expect(active?.text()).toBe('Panel-a');
  });
});
