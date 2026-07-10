// @vitest-environment happy-dom
// WorkflowScheduleAssist.spec — the AI schedule assist composer + its FOUR response
// states (§4.5.5). Asserts each envelope state from a MOCKED store:
//   (a) feasible          → apply emits configToDraft(config) + a success Alert.
//   (b) infeasible + alt  → warning Alert; "use alternative" emits the alt draft.
//   (c) infeasible, no alt→ warning Alert; no apply.
//   (d) throttled/failed  → FE-owned copy (never the raw backend message).
// Also asserts model text renders as PLAIN TEXT (no v-html injection). The store is
// mocked so no HTTP happens; ScheduleAssistError is a real class so the kind branch
// works.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { ScheduleAssistEnvelope } from '../types';
import type { ScheduleDraft } from '../workflowSchedule';

const scheduleAssist = vi.fn();
const schedulePreview = vi.fn();

vi.mock('../../../app/stores/workflows', () => {
  // Defined INSIDE the factory (the vi.mock call is hoisted above module scope).
  class ScheduleAssistError extends Error {
    constructor(public readonly kind: 'throttled' | 'failed') {
      super(kind);
    }
  }
  return {
    useWorkflowsStore: () => ({ scheduleAssist, schedulePreview }),
    ScheduleAssistError,
  };
});

import WorkflowScheduleAssist from '../WorkflowScheduleAssist.vue';
// The mocked ScheduleAssistError (same class instance the component's instanceof sees).
import { ScheduleAssistError } from '../../../app/stores/workflows';

function feasibleEnvelope(): ScheduleAssistEnvelope {
  return {
    feasible: true,
    config: { family: 'daily', params: { time: '09:00' }, tz: 'Europe/Warsaw' },
    unsupported: [],
    alternative: null,
    explanation: 'Runs daily at 9am.',
  };
}

async function openAndSubmit(wrapper: ReturnType<typeof mount>): Promise<void> {
  await wrapper.get('button').trigger('click'); // open the composer
  await nextTick();
  await wrapper.get('textarea').setValue('every day at 9am');
  // The submit Button is the primary one carrying the run label.
  const submit = wrapper.findAll('button').find((b) => b.text().includes('Suggest a schedule'));
  await submit!.trigger('click');
  await nextTick();
  await Promise.resolve();
  await nextTick();
  // A second flush so the alternative-preview promise chain (state (b)) settles.
  await Promise.resolve();
  await nextTick();
}

describe('WorkflowScheduleAssist', () => {
  beforeEach(() => {
    installBrowserMocks();
    scheduleAssist.mockReset();
    schedulePreview.mockReset();
    // A benign default so the alternative preview never throws when unexercised.
    schedulePreview.mockResolvedValue({ occurrences: [], count: 0, empty: false, approximate: false });
  });
  afterEach(() => restoreBrowserMocks());

  it('(a) feasible → emits apply(configToDraft) + shows the success explanation', async () => {
    scheduleAssist.mockResolvedValue(feasibleEnvelope());
    const wrapper = mount(WorkflowScheduleAssist, { attachTo: document.body });

    await openAndSubmit(wrapper);

    const applied = wrapper.emitted('apply');
    expect(applied).toBeTruthy();
    expect(applied?.[0]?.[0]).toEqual<ScheduleDraft>({
      family: 'daily',
      params: {},
      tz: 'Europe/Warsaw',
      times: ['09:00'],
      exclusions: { months: [], weekdays: [], dates: [] },
    });
    expect(wrapper.text()).toContain('Runs daily at 9am.');

    wrapper.unmount();
  });

  it('(b) infeasible + alternative → previews the alt (sentence + note + next runs) BEFORE apply', async () => {
    schedulePreview.mockResolvedValue({
      occurrences: ['2026-07-10T10:00:00Z', '2026-07-10T11:00:00Z'],
      count: 2,
      empty: false,
      approximate: false,
    });
    scheduleAssist.mockResolvedValue({
      feasible: false,
      config: null,
      unsupported: ['sub-minute cadence'],
      alternative: { config: { family: 'hourly', params: {}, tz: null }, note: 'Closest hourly run.' },
      explanation: 'Can’t run every 10 seconds.',
    } satisfies ScheduleAssistEnvelope);
    const wrapper = mount(WorkflowScheduleAssist, { attachTo: document.body });

    await openAndSubmit(wrapper);

    // No apply yet (it is opt-in via the alternative button).
    expect(wrapper.emitted('apply')).toBeFalsy();
    expect(wrapper.text()).toContain('Can’t run every 10 seconds.');
    expect(wrapper.text()).toContain('sub-minute cadence');

    // The alternative is DESCRIBED before applying: the deterministic sentence…
    expect(wrapper.text()).toContain('Suggested alternative');
    expect(wrapper.text()).toContain('Hourly (UTC)');
    // …the model note (plain text)…
    expect(wrapper.text()).toContain('Closest hourly run.');
    // …and its next runs (fetched with the ALT config + count 4).
    expect(schedulePreview).toHaveBeenCalledWith({ family: 'hourly', params: {}, tz: null }, 4);
    // Two occurrence rows rendered from the mock.
    const occRows = wrapper.findAll('ul li.tabular-nums, ul li').filter((li) => /\d{2}:\d{2}/.test(li.text()));
    expect(occRows.length).toBeGreaterThanOrEqual(2);

    const altBtn = wrapper.findAll('button').find((b) => b.text().includes('Use the suggested alternative'));
    await altBtn!.trigger('click');

    const applied = wrapper.emitted('apply');
    expect(applied?.[0]?.[0]).toEqual<ScheduleDraft>({
      family: 'hourly',
      params: {},
      tz: '',
      times: [],
      exclusions: { months: [], weekdays: [], dates: [] },
    });
    expect(wrapper.text()).toContain('Closest hourly run.');

    wrapper.unmount();
  });

  it('(c) infeasible, no alternative → warning + unsupported list, no apply', async () => {
    scheduleAssist.mockResolvedValue({
      feasible: false,
      config: null,
      unsupported: ['random times'],
      alternative: null,
      explanation: 'That isn’t a fixed cadence.',
    } satisfies ScheduleAssistEnvelope);
    const wrapper = mount(WorkflowScheduleAssist, { attachTo: document.body });

    await openAndSubmit(wrapper);

    expect(wrapper.emitted('apply')).toBeFalsy();
    expect(wrapper.text()).toContain('That isn’t a fixed cadence.');
    expect(wrapper.text()).toContain('random times');

    wrapper.unmount();
  });

  it('(d) throttled → FE-owned copy, NEVER the raw backend message', async () => {
    scheduleAssist.mockRejectedValue(new ScheduleAssistError('throttled'));
    const wrapper = mount(WorkflowScheduleAssist, { attachTo: document.body });

    await openAndSubmit(wrapper);

    expect(wrapper.text()).toContain('Too many attempts — try again in a moment.');
    expect(wrapper.text()).not.toContain('throttled'); // no raw error surfaced
    expect(wrapper.emitted('apply')).toBeFalsy();

    wrapper.unmount();
  });

  it('(d) generic failure → the FE fallback copy', async () => {
    scheduleAssist.mockRejectedValue(new ScheduleAssistError('failed'));
    const wrapper = mount(WorkflowScheduleAssist, { attachTo: document.body });

    await openAndSubmit(wrapper);

    expect(wrapper.text()).toContain('Couldn’t process that — set the schedule manually below.');

    wrapper.unmount();
  });

  it('renders model explanation text as PLAIN TEXT (no HTML injection)', async () => {
    scheduleAssist.mockResolvedValue({
      ...feasibleEnvelope(),
      explanation: '<img src=x onerror=alert(1)>daily',
    });
    const wrapper = mount(WorkflowScheduleAssist, { attachTo: document.body });

    await openAndSubmit(wrapper);

    // The raw markup is shown as text; no <img> element is injected into the DOM.
    expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>daily');
    expect(wrapper.element.querySelector('img')).toBeNull();

    wrapper.unmount();
  });

  it('renders the alternative note as PLAIN TEXT before apply (no HTML injection)', async () => {
    scheduleAssist.mockResolvedValue({
      feasible: false,
      config: null,
      unsupported: [],
      alternative: {
        config: { family: 'hourly', params: {}, tz: null },
        note: '<img src=x onerror=alert(1)>closest',
      },
      explanation: 'Can’t do that exactly.',
    } satisfies ScheduleAssistEnvelope);
    const wrapper = mount(WorkflowScheduleAssist, { attachTo: document.body });

    await openAndSubmit(wrapper);

    // The note markup is shown as text (BEFORE any apply click); no <img> injected.
    expect(wrapper.emitted('apply')).toBeFalsy();
    expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>closest');
    expect(wrapper.element.querySelector('img')).toBeNull();

    wrapper.unmount();
  });
});
