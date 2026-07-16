// @vitest-environment happy-dom
// WorkflowScheduleAssistModal.spec — the AI assist MODAL (Phase 4b, §4.5.9). Replaces the
// Phase 4a inline-composer spec. Asserts the six response states over the v2 config, the
// NO-auto-apply rule (even a feasible result is committed via "Zastosuj"), the v2 draft
// emitted on apply, cancel discarding, Zastosuj gated until a proposal exists, and that
// all model text renders as PLAIN TEXT (no v-html injection). The Modal teleports to
// <body>, so content is queried there. The store + toast are mocked.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { setLocale } from '../../../app/i18n';
import { en } from '../../../app/i18n/en';
import type { ScheduleAssistEnvelope } from '../types';
import type { ScheduleDraft } from '../workflowSchedule';

const scheduleAssist = vi.fn();
const schedulePreview = vi.fn();
vi.mock('../../../app/stores/workflows', () => {
  class ScheduleAssistError extends Error {
    constructor(public readonly kind: 'throttled' | 'failed') {
      super(kind);
    }
  }
  return { useWorkflowsStore: () => ({ scheduleAssist, schedulePreview }), ScheduleAssistError };
});
vi.mock('../../../app/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), info: vi.fn(), danger: vi.fn() }),
}));

import WorkflowScheduleAssistModal from '../WorkflowScheduleAssistModal.vue';
import { ScheduleAssistError } from '../../../app/stores/workflows';

const A = en.workflows.schedule.assist;

function feasibleEnvelope(): ScheduleAssistEnvelope {
  return {
    feasible: true,
    config: { time: { mode: 'at', at: ['09:00'] }, tz: 'Europe/Warsaw' },
    unsupported: [],
    alternative: null,
    explanation: 'Runs daily at 9am.',
  };
}

const bodyText = () => document.body.textContent ?? '';
function bodyButton(label: string): HTMLButtonElement | undefined {
  return Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.includes(label));
}
function clickBody(label: string): void {
  bodyButton(label)?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
}

async function openModal() {
  const wrapper = mount(WorkflowScheduleAssistModal, {
    attachTo: document.body,
    props: { open: false, 'onUpdate:open': (v: boolean) => wrapper.setProps({ open: v }) },
  });
  await wrapper.setProps({ open: true });
  await nextTick();
  return wrapper;
}

async function submit(text = 'every day at 9am'): Promise<void> {
  const ta = document.body.querySelector('textarea') as HTMLTextAreaElement;
  ta.value = text;
  ta.dispatchEvent(new Event('input'));
  await nextTick();
  clickBody(A.run);
  await nextTick();
  await Promise.resolve();
  await nextTick();
}

describe('WorkflowScheduleAssistModal (Phase 4b — AI modal)', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('en');
    scheduleAssist.mockReset();
    schedulePreview.mockReset();
    schedulePreview.mockResolvedValue({ occurrences: ['2026-07-13T09:00:00Z'], count: 1, empty: false, approximate: false });
  });
  afterEach(() => restoreBrowserMocks());

  it('focuses the composer + Zastosuj is DISABLED until a proposal exists', async () => {
    const wrapper = await openModal();
    expect(document.activeElement?.tagName.toLowerCase()).toBe('textarea');
    expect(bodyButton(A.apply)?.disabled).toBe(true);
    wrapper.unmount();
  });

  it('(a) feasible → explanation + proposal sentence, NO auto-apply; Zastosuj emits the v2 draft', async () => {
    scheduleAssist.mockResolvedValue(feasibleEnvelope());
    const wrapper = await openModal();
    await submit();

    expect(bodyText()).toContain('Runs daily at 9am.');
    expect(bodyText()).toContain('Daily at 09:00'); // the deterministic proposal sentence
    expect(wrapper.emitted('apply')).toBeFalsy(); // no auto-apply

    expect(bodyButton(A.apply)?.disabled).toBe(false);
    clickBody(A.apply);
    await nextTick();
    expect(wrapper.emitted('apply')?.[0]?.[0]).toEqual<ScheduleDraft>({
      time: { mode: 'at', at: ['09:00'] },
      day: { mode: 'every_day' },
      month: { mode: 'every_month' },
      exclusions: { months: [], weekdays: [], dates: [] },
      tz: 'Europe/Warsaw',
    });
    wrapper.unmount();
  });

  it('(b) infeasible + alternative → warning + unsupported + note; Zastosuj emits the alt draft', async () => {
    scheduleAssist.mockResolvedValue({
      feasible: false,
      config: null,
      unsupported: ['sub-minute cadence'],
      alternative: { config: { time: { mode: 'every_minutes', minutes: 1 } }, note: 'Closest supported cadence.' },
      explanation: 'Can’t run every 10 seconds.',
    } satisfies ScheduleAssistEnvelope);
    const wrapper = await openModal();
    await submit();

    expect(bodyText()).toContain('Can’t run every 10 seconds.');
    expect(bodyText()).toContain('sub-minute cadence');
    expect(bodyText()).toContain('Closest supported cadence.');
    expect(wrapper.emitted('apply')).toBeFalsy();

    clickBody(A.apply);
    await nextTick();
    expect((wrapper.emitted('apply')?.[0]?.[0] as ScheduleDraft).time).toEqual({ mode: 'every_minutes', n: 1 });
    wrapper.unmount();
  });

  it('(c) infeasible, no alternative → warning + unsupported; Zastosuj stays disabled, no apply', async () => {
    scheduleAssist.mockResolvedValue({
      feasible: false,
      config: null,
      unsupported: ['random times'],
      alternative: null,
      explanation: 'That isn’t a fixed cadence.',
    } satisfies ScheduleAssistEnvelope);
    const wrapper = await openModal();
    await submit();

    expect(bodyText()).toContain('That isn’t a fixed cadence.');
    expect(bodyText()).toContain('random times');
    expect(bodyButton(A.apply)?.disabled).toBe(true);
    clickBody(A.apply);
    await nextTick();
    expect(wrapper.emitted('apply')).toBeFalsy();
    wrapper.unmount();
  });

  it('(d) throttled → FE-owned copy, never the raw backend message', async () => {
    scheduleAssist.mockRejectedValue(new ScheduleAssistError('throttled'));
    const wrapper = await openModal();
    await submit();
    expect(bodyText()).toContain(A.throttled);
    expect(bodyButton(A.apply)?.disabled).toBe(true);
    wrapper.unmount();
  });

  it('Anuluj closes the modal without applying', async () => {
    scheduleAssist.mockResolvedValue(feasibleEnvelope());
    const wrapper = await openModal();
    await submit();
    clickBody(en.workflows.editor.cancel);
    await nextTick();
    expect(wrapper.emitted('apply')).toBeFalsy();
    expect(wrapper.props('open')).toBe(false);
    wrapper.unmount();
  });

  it('renders model explanation as PLAIN TEXT (no HTML injection)', async () => {
    scheduleAssist.mockResolvedValue({ ...feasibleEnvelope(), explanation: '<img src=x onerror=alert(1)>daily' });
    const wrapper = await openModal();
    await submit();
    expect(bodyText()).toContain('<img src=x onerror=alert(1)>daily');
    expect(document.body.querySelector('img')).toBeNull();
    wrapper.unmount();
  });
});
