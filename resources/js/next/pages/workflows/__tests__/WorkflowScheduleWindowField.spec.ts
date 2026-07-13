// @vitest-environment happy-dom
// WorkflowScheduleWindowField.spec — the shared "od–do" window pattern (§4.5.6). REV5.1
// (user feedback): a bare Switch (accessible name ONLY, no visible text) toggles `enabled`;
// the "from {from} to {to}" fragment is ALWAYS rendered and the two woven inputs receive a
// `disabled` slot-prop (= !enabled) instead of being mounted/unmounted. The order error
// still shows only while enabled.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h } from 'vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { setLocale } from '../../../app/i18n';
import { en } from '../../../app/i18n/en';
import WorkflowScheduleWindowField from '../WorkflowScheduleWindowField.vue';

const W = en.workflows.schedule.window;
// The window text is a SLOTTED template ("from {from} to {to}") — the fragment weaves the
// two inputs at {from}/{to}.
const WINDOW_TEMPLATE = en.workflows.schedule.time.card.everyMinutes.window;

function mountField(props: Record<string, unknown>) {
  return mount(WorkflowScheduleWindowField, {
    props: { toggleLabel: W.toggle.time, windowTemplate: WINDOW_TEMPLATE, ...props },
    slots: {
      // Reflect the `disabled` slot-prop onto a data-attr so the wiring is observable.
      from: (p: { disabled: boolean }) =>
        h('input', { class: 'from-input', 'data-disabled': String(p.disabled) }),
      to: (p: { disabled: boolean }) =>
        h('input', { class: 'to-input', 'data-disabled': String(p.disabled) }),
    },
  });
}

describe('WorkflowScheduleWindowField', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('en');
  });
  afterEach(() => restoreBrowserMocks());

  it('the toggle is a bare Switch whose accessible name is the toggle string (no visible text)', () => {
    const wrapper = mountField({ enabled: false });
    const sw = wrapper.find('button[role="switch"]');
    expect(sw.exists()).toBe(true);
    expect(sw.attributes('aria-label')).toBe(W.toggle.time);
    // The reworded toggle string is the ACCESSIBLE name only — never visible sentence text.
    expect(wrapper.text()).not.toContain(W.toggle.time);
    wrapper.unmount();
  });

  it('is OFF by default: the switch is unchecked but the from/to pair is STILL rendered + disabled', () => {
    const wrapper = mountField({ enabled: false });
    expect(wrapper.find('button[role="switch"]').attributes('aria-checked')).toBe('false');
    // REV5.1: the fragment is always in the DOM — the inputs exist even while off…
    expect(wrapper.find('.from-input').exists()).toBe(true);
    expect(wrapper.find('.to-input').exists()).toBe(true);
    // …and both are disabled (the `disabled` slot-prop resolves to !enabled).
    expect(wrapper.find('.from-input').attributes('data-disabled')).toBe('true');
    expect(wrapper.find('.to-input').attributes('data-disabled')).toBe('true');
    // Inline "od"/"do" literals render regardless of state.
    expect(wrapper.text()).toContain(W.from);
    expect(wrapper.text()).toContain(W.to);
    wrapper.unmount();
  });

  it('when enabled, the switch is checked and the from/to inputs are ENABLED', () => {
    const wrapper = mountField({ enabled: true });
    expect(wrapper.find('button[role="switch"]').attributes('aria-checked')).toBe('true');
    expect(wrapper.find('.from-input').attributes('data-disabled')).toBe('false');
    expect(wrapper.find('.to-input').attributes('data-disabled')).toBe('false');
    wrapper.unmount();
  });

  it('toggling the switch emits `toggle` with the new boolean', async () => {
    const wrapper = mountField({ enabled: false });
    await wrapper.find('button[role="switch"]').trigger('click');
    expect(wrapper.emitted('toggle')?.[0]).toEqual([true]);
    wrapper.unmount();
  });

  it('shows the order error only while enabled', () => {
    const err = en.workflows.schedule.validation.windowOrder;
    const off = mountField({ enabled: false, error: err });
    expect(off.text()).not.toContain(err);
    off.unmount();

    const on = mountField({ enabled: true, error: err });
    expect(on.text()).toContain(err);
    on.unmount();
  });
});
