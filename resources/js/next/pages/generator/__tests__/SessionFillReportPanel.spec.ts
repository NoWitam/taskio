// @vitest-environment happy-dom
// SessionFillReportPanel.spec — the delegation fill-report surface (R2 sub-stage 3). Asserts it lists the
// FILLED inputs, the SKIPPED ones with a localized reason, and the UNFILLED-REQUIRED inputs — and that a
// required FILE slot shows the distinct "needs your file" state and the "Complete inputs" action emits up.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

import SessionFillReportPanel from '../session/SessionFillReportPanel.vue';
import type { TemplateSlot } from '../types';

const slots: TemplateSlot[] = [
  { name: 'topic', descriptor: { base: 'text' } as TemplateSlot['descriptor'] },
  { name: 'hero_image', descriptor: { base: 'file' } as TemplateSlot['descriptor'] },
];

function mountPanel(reportOverrides = {}) {
  return mount(SessionFillReportPanel, {
    props: {
      botName: 'Copy Bot',
      slots,
      report: {
        filled: ['topic'],
        skipped: [{ name: 'mystery', reason: 'unknown_slot' }],
        unfilled_required: ['hero_image'],
        mode: 'gaps',
        nothing_to_fill: false,
        ...reportOverrides,
      },
    },
    attachTo: document.body,
  });
}

describe('SessionFillReportPanel', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('summarizes what the bot filled and lists the filled input names', () => {
    const wrapper = mountPanel();
    expect(wrapper.text()).toContain('Copy Bot filled 1 inputs');
    expect(wrapper.text()).toContain('topic');
    wrapper.unmount();
  });

  it('shows skipped inputs with a localized reason', () => {
    const wrapper = mountPanel();
    expect(wrapper.text()).toContain('mystery');
    expect(wrapper.text()).toContain('unknown input');
    wrapper.unmount();
  });

  it('flags a required FILE slot as "needs your file" (the bot can never fill it)', () => {
    const wrapper = mountPanel();
    expect(wrapper.text()).toContain('hero_image');
    expect(wrapper.text()).toContain('needs your file');
    wrapper.unmount();
  });

  it('a required non-file slot is listed without the file note', () => {
    const wrapper = mountPanel({ unfilled_required: ['topic'] });
    expect(wrapper.text()).toContain('Still needed from you');
    expect(wrapper.text()).not.toContain('needs your file');
    wrapper.unmount();
  });

  it('"Complete inputs" emits completeInputs; the close button emits dismiss', async () => {
    const wrapper = mountPanel();
    const buttons = wrapper.findAll('button');
    const complete = buttons.find((b) => b.text().includes('Complete inputs'));
    await complete?.trigger('click');
    expect(wrapper.emitted('completeInputs')).toBeTruthy();

    const dismiss = wrapper.find('button[aria-label="Dismiss"]');
    await dismiss.trigger('click');
    expect(wrapper.emitted('dismiss')).toBeTruthy();
    wrapper.unmount();
  });

  it('with nothing filled shows the empty note', () => {
    const wrapper = mountPanel({ filled: [], skipped: [], unfilled_required: [] });
    expect(wrapper.text()).toContain('The bot didn’t fill any inputs.');
    wrapper.unmount();
  });

  it('surfaces WHICH mode ran, so "gaps" explains why little changed', () => {
    const wrapper = mountPanel();
    expect(wrapper.text()).toContain('Mode: only the empty inputs');
    wrapper.unmount();
  });

  it('a "fresh" run names that mode instead', () => {
    const wrapper = mountPanel({ mode: 'fresh' });
    expect(wrapper.text()).toContain('Mode: everything fresh');
    wrapper.unmount();
  });

  it('nothing_to_fill renders its OWN honest state (no AI call), not a "filled 0 inputs" panel', () => {
    const wrapper = mountPanel({
      filled: [],
      skipped: [],
      unfilled_required: [],
      mode: 'gaps',
      nothing_to_fill: true,
    });

    expect(wrapper.text()).toContain('Copy Bot had nothing to fill');
    expect(wrapper.text()).toContain('Every input was already filled, so Copy Bot changed nothing.');
    expect(wrapper.text()).toContain('No AI call was made — nothing was spent.');
    expect(wrapper.text()).toContain('Propose everything fresh');
    // The misleading "filled 0" summary + empty note must NOT be rendered.
    expect(wrapper.text()).not.toContain('Copy Bot filled 0 inputs');
    expect(wrapper.text()).not.toContain('The bot didn’t fill any inputs.');
    wrapper.unmount();
  });

  it('a normal empty result is NOT the nothing_to_fill state', () => {
    const wrapper = mountPanel({ filled: [], skipped: [], unfilled_required: [] });
    expect(wrapper.text()).toContain('Copy Bot filled 0 inputs');
    expect(wrapper.text()).toContain('The bot didn’t fill any inputs.');
    expect(wrapper.text()).not.toContain('had nothing to fill');
    expect(wrapper.text()).not.toContain('No AI call was made');
    wrapper.unmount();
  });
});
