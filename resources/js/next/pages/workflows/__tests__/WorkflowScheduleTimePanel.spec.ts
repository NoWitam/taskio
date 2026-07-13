// @vitest-environment happy-dom
// WorkflowScheduleTimePanel.spec — the "Czas" tab `at` list (§4.5.5a, REV5.1). User
// feedback: the per-time ✕ must live INSIDE the field, not as an external sibling Button.
// So each `at` TimePicker uses its OWN `clearable` ✕ (a descendant of the picker's field
// shell); clearing a time (the picker emits null) removes THAT row, and with a single time
// the picker is not clearable so the last time can't be removed. Mounts the REAL panel +
// real TimePickers over an `at` axis.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { setLocale } from '../../../app/i18n';
import { en } from '../../../app/i18n/en';
import WorkflowScheduleTimePanel from '../WorkflowScheduleTimePanel.vue';
import type { TimeAxis } from '../workflowSchedule';

const SCH = en.workflows.schedule;
const CLEAR_TIME = en.pickers.clearTime;

function mountPanel(axis: TimeAxis) {
  const wrapper: VueWrapper = mount(WorkflowScheduleTimePanel, {
    props: {
      modelValue: axis,
      'onUpdate:modelValue': (v: TimeAxis) => wrapper.setProps({ modelValue: v }),
    },
  });
  return wrapper;
}

const atOf = (w: VueWrapper) => (w.props('modelValue') as { at: string[] }).at;

describe('WorkflowScheduleTimePanel — `at` list clear (REV5.1)', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('en');
  });
  afterEach(() => restoreBrowserMocks());

  it('each `at` clear ✕ lives INSIDE the field (a picker-shell descendant), with no external remove Button', () => {
    const wrapper = mountPanel({ mode: 'at', at: ['09:00', '12:00'] });
    // The old external per-row remove Button (labelled `field.removeTime`) is gone…
    expect(wrapper.find(`button[aria-label="${SCH.field.removeTime}"]`).exists()).toBe(false);
    // …replaced by the picker's OWN clear ✕ — one per field, each a `.next-field-shell` descendant.
    const clears = wrapper.findAll(`button[aria-label="${CLEAR_TIME}"]`);
    expect(clears.length).toBe(2);
    clears.forEach((c) => expect(c.element.closest('.next-field-shell')).not.toBeNull());
    wrapper.unmount();
  });

  it('clearing a time (the picker emits null) removes THAT row when there is more than one', async () => {
    const wrapper = mountPanel({ mode: 'at', at: ['09:00', '12:00'] });
    const clears = wrapper.findAll(`button[aria-label="${CLEAR_TIME}"]`);
    expect(clears.length).toBe(2);
    await clears[0].trigger('click');
    await nextTick();
    // The first row is removed (null ⇒ removeAt), leaving the second.
    expect(atOf(wrapper)).toEqual(['12:00']);
    wrapper.unmount();
  });

  it('with a single time the picker is NOT clearable, so the last time cannot be removed', () => {
    const wrapper = mountPanel({ mode: 'at', at: ['09:00'] });
    expect(wrapper.find(`button[aria-label="${CLEAR_TIME}"]`).exists()).toBe(false);
    wrapper.unmount();
  });
});
