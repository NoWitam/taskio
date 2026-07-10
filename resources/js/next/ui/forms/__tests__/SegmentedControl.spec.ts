// @vitest-environment happy-dom
// SegmentedControl.spec — the selection-CARD control (single + multiple).
//
// Locks the contract the redesign must preserve and the new multi mode adds:
//   • SINGLE: role="radiogroup" + role="radio" cards + aria-checked; clicking a
//     card selects it; ArrowRight MOVES the selection (single-select keyboard).
//   • MULTIPLE: role="group" + role="checkbox" cards; the model is an ARRAY;
//     clicking toggles a value in options order; ArrowRight moves FOCUS ONLY
//     (no selection change); Space toggles the focused card.
//   • description renders under the label; iconOnly hides the label + indicator.
// Other components pin `[role="radio"]` selectors — those must not break, so the
// single-mode roles are asserted verbatim here.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import SegmentedControl, { type SegmentOption } from '../SegmentedControl.vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const VIEW: SegmentOption[] = [
  { value: 'list', label: 'List' },
  { value: 'board', label: 'Board' },
  { value: 'calendar', label: 'Calendar' },
];

/** The payload of the most recent `update:modelValue` emission (or undefined). */
function lastEmit(events?: unknown[][]): unknown {
  if (!events || events.length === 0) return undefined;
  return events[events.length - 1][0];
}

describe('SegmentedControl — single (radio cards)', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('renders a radiogroup of radio cards with aria-checked on the selected one', () => {
    const wrapper = mount(SegmentedControl, {
      props: { options: VIEW, modelValue: 'board', ariaLabel: 'View mode' },
    });
    expect(wrapper.find('[role="radiogroup"]').exists()).toBe(true);
    const radios = wrapper.findAll('[role="radio"]');
    expect(radios).toHaveLength(3);
    // Only the selected card is aria-checked="true".
    const checked = radios.filter((r) => r.attributes('aria-checked') === 'true');
    expect(checked).toHaveLength(1);
    expect(checked[0].text()).toContain('Board');
  });

  it('clicking a card emits the value on the single v-model', async () => {
    const wrapper = mount(SegmentedControl, {
      props: { options: VIEW, modelValue: 'list' },
    });
    const calendar = wrapper.findAll('[role="radio"]')[2];
    await calendar.trigger('click');
    expect(lastEmit(wrapper.emitted('update:modelValue'))).toBe('calendar');
  });

  it('ArrowRight MOVES the selection (single-select keyboard)', async () => {
    const wrapper = mount(SegmentedControl, {
      attachTo: document.body,
      props: { options: VIEW, modelValue: 'list' },
    });
    await wrapper.find('[role="radiogroup"]').trigger('keydown', { key: 'ArrowRight' });
    expect(lastEmit(wrapper.emitted('update:modelValue'))).toBe('board');
    wrapper.unmount();
  });

  it('renders a description line under the label', () => {
    const opts: SegmentOption[] = [
      { value: 'a', label: 'Alpha', description: 'The first one' },
      { value: 'b', label: 'Beta' },
    ];
    const wrapper = mount(SegmentedControl, { props: { options: opts, modelValue: 'a' } });
    expect(wrapper.text()).toContain('The first one');
  });

  it('iconOnly hides the label text + the indicator (label → aria-label)', () => {
    const opts: SegmentOption[] = [
      { value: 'light', label: 'Light theme', icon: 'sun' },
      { value: 'dark', label: 'Dark theme', icon: 'moon' },
    ];
    const wrapper = mount(SegmentedControl, {
      props: { options: opts, modelValue: 'light', iconOnly: true },
    });
    // The visible label text is gone; it survives as the button's aria-label.
    expect(wrapper.text()).not.toContain('Light theme');
    const first = wrapper.findAll('[role="radio"]')[0];
    expect(first.attributes('aria-label')).toBe('Light theme');
  });

  it('a disabled option is not selectable and is skipped by the keyboard', async () => {
    const opts: SegmentOption[] = [
      { value: 'all', label: 'All' },
      { value: 'active', label: 'Active' },
      { value: 'done', label: 'Done', disabled: true },
    ];
    const wrapper = mount(SegmentedControl, {
      attachTo: document.body,
      props: { options: opts, modelValue: 'active' },
    });
    // Clicking the disabled card emits nothing.
    await wrapper.findAll('[role="radio"]')[2].trigger('click');
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    // ArrowRight wraps past the disabled 'done' back to 'all'.
    await wrapper.find('[role="radiogroup"]').trigger('keydown', { key: 'ArrowRight' });
    expect(lastEmit(wrapper.emitted('update:modelValue'))).toBe('all');
    wrapper.unmount();
  });
});

describe('SegmentedControl — multiple (checkbox cards)', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('renders a group of checkbox cards with the array selection reflected', () => {
    const wrapper = mount(SegmentedControl, {
      props: { options: VIEW, multiple: true, modelValue: ['board'], ariaLabel: 'Views' },
    });
    expect(wrapper.find('[role="group"]').exists()).toBe(true);
    const boxes = wrapper.findAll('[role="checkbox"]');
    expect(boxes).toHaveLength(3);
    expect(boxes[1].attributes('aria-checked')).toBe('true');
    expect(boxes[0].attributes('aria-checked')).toBe('false');
  });

  it('clicking toggles the value in the array (options order preserved)', async () => {
    const wrapper = mount(SegmentedControl, {
      props: { options: VIEW, multiple: true, modelValue: ['board'] },
    });
    // Select 'list' (comes before 'board' in options) → array keeps options order.
    await wrapper.findAll('[role="checkbox"]')[0].trigger('click');
    expect(lastEmit(wrapper.emitted('update:modelValue'))).toEqual(['list', 'board']);
  });

  it('clicking an already-selected card removes it from the array', async () => {
    const wrapper = mount(SegmentedControl, {
      props: { options: VIEW, multiple: true, modelValue: ['list', 'board'] },
    });
    await wrapper.findAll('[role="checkbox"]')[0].trigger('click');
    expect(lastEmit(wrapper.emitted('update:modelValue'))).toEqual(['board']);
  });

  it('ArrowRight moves FOCUS ONLY — no selection change in multi mode', async () => {
    const wrapper = mount(SegmentedControl, {
      attachTo: document.body,
      props: { options: VIEW, multiple: true, modelValue: [] },
    });
    await wrapper.find('[role="group"]').trigger('keydown', { key: 'ArrowRight' });
    // The array is untouched (no emission) even though focus advanced.
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    wrapper.unmount();
  });

  it('Space toggles the focused card', async () => {
    const wrapper = mount(SegmentedControl, {
      attachTo: document.body,
      props: { options: VIEW, multiple: true, modelValue: [] },
    });
    const group = wrapper.find('[role="group"]');
    // Move focus onto 'board' (index 1) then toggle it with Space.
    await group.trigger('keydown', { key: 'ArrowRight' });
    await nextTick();
    await group.trigger('keydown', { key: ' ' });
    expect(lastEmit(wrapper.emitted('update:modelValue'))).toEqual(['board']);
    wrapper.unmount();
  });
});
