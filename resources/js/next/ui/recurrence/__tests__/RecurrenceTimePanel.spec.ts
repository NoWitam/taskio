// @vitest-environment happy-dom
// RecurrenceTimePanel.spec — the "Czas" tab `at` editor (§4.5.5a, REV5.2). User
// feedback: the times are no longer a row of editable pickers — there is ONE draft
// TimePicker fused with the "Add time" button (a single entry group), and the added
// times render as compact chips on the same wrapping line. Each chip carries its own
// ✕; the LAST chip hides it (the axis requires ≥1 time). A duplicate / empty draft
// disables Add. Mounts the REAL panel + a real TimePicker over an `at` axis.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { setLocale } from '../../../app/i18n';
import { en } from '../../../app/i18n/en';
import RecurrenceTimePanel from '../RecurrenceTimePanel.vue';
import TimePicker from '../../forms/TimePicker.vue';
import { WORKFLOW_SCHEDULE_PROFILE, type TimeAxis } from '../recurrenceAxes';

const SCH = en.recurrenceEditor;

function mountPanel(axis: TimeAxis) {
  const wrapper: VueWrapper = mount(RecurrenceTimePanel, {
    props: {
      // The Workflows profile is the one that shows a time axis at all — the Calendar
      // hides it, so every case below is exercised on the full vocabulary.
      profile: WORKFLOW_SCHEDULE_PROFILE,
      modelValue: axis,
      'onUpdate:modelValue': (v: TimeAxis) => wrapper.setProps({ modelValue: v }),
    },
  });
  return wrapper;
}

const atOf = (w: VueWrapper) => (w.props() as { modelValue: { at: string[] } }).modelValue.at;

const addButton = (w: VueWrapper) =>
  w.findAll('button').find((b) => b.text().includes(SCH.field.addTime))!;

const chipRemove = (w: VueWrapper, time: string) =>
  w.find(`button[aria-label="${SCH.field.removeTime} ${time}"]`);

/** Set the DRAFT picker's value (the single TimePicker bound to `newAtTime`). */
async function setDraft(w: VueWrapper, value: string): Promise<void> {
  w.findComponent(TimePicker).vm.$emit('update:modelValue', value);
  await nextTick();
}

describe('RecurrenceTimePanel — `at` chips editor (REV5.2)', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('en');
  });
  afterEach(() => restoreBrowserMocks());

  it('renders ONE draft picker + a chip per time; each chip has its own ✕', () => {
    const wrapper = mountPanel({ mode: 'at', at: ['09:00', '12:00'] });
    // A single draft TimePicker (the entry group), not one picker per time.
    expect(wrapper.findAllComponents(TimePicker).length).toBe(1);
    // Both times render as chips with their own remove ✕.
    expect(wrapper.text()).toContain('09:00');
    expect(wrapper.text()).toContain('12:00');
    expect(chipRemove(wrapper, '09:00').exists()).toBe(true);
    expect(chipRemove(wrapper, '12:00').exists()).toBe(true);
    wrapper.unmount();
  });

  it('adding: a filled draft + "Add time" appends and clears the draft', async () => {
    const wrapper = mountPanel({ mode: 'at', at: ['09:00'] });
    // An empty draft disables Add.
    expect(addButton(wrapper).attributes('disabled')).toBeDefined();

    await setDraft(wrapper, '14:30');
    expect(addButton(wrapper).attributes('disabled')).toBeUndefined();
    await addButton(wrapper).trigger('click');
    await nextTick();

    expect(atOf(wrapper)).toEqual(['09:00', '14:30']);
    wrapper.unmount();
  });

  it('a DUPLICATE draft disables Add', async () => {
    const wrapper = mountPanel({ mode: 'at', at: ['09:00'] });
    await setDraft(wrapper, '09:00');
    expect(addButton(wrapper).attributes('disabled')).toBeDefined();
    wrapper.unmount();
  });

  it('a chip ✕ removes THAT time when there is more than one', async () => {
    const wrapper = mountPanel({ mode: 'at', at: ['09:00', '12:00'] });
    await chipRemove(wrapper, '09:00').trigger('click');
    await nextTick();
    expect(atOf(wrapper)).toEqual(['12:00']);
    wrapper.unmount();
  });

  it('the LAST chip hides its ✕ (the axis requires at least one time)', () => {
    const wrapper = mountPanel({ mode: 'at', at: ['09:00'] });
    expect(wrapper.text()).toContain('09:00');
    expect(chipRemove(wrapper, '09:00').exists()).toBe(false);
    wrapper.unmount();
  });
});
