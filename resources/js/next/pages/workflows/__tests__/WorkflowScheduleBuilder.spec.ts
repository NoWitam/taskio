// @vitest-environment happy-dom
// WorkflowScheduleBuilder.spec — the v2 THREE-TAB builder (Phase 4b, §4.5). Mounts the
// REAL builder (real panels + window field + Tabs + SegmentedControl + Accordion) over a
// v2 ScheduleDraft. The store is mocked (schedulePreview), the AI modal + the strip's
// DateTimePicker + the exclusions DatePicker are stubbed. Covers: sub-mode switching
// (foreign-field clearing), the window both-or-neither + from<to gate, the LWD lock
// (auto-reset + disabled cards), the at[] limit, the 422→tab mapping, the isValid gate,
// and the modal-apply flow into the v-model.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick, h } from 'vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { setLocale } from '../../../app/i18n';
import { en } from '../../../app/i18n/en';
import { emptyScheduleDraft, type ScheduleDraft } from '../workflowSchedule';

const schedulePreview = vi.fn();
vi.mock('../../../app/stores/workflows', () => ({
  useWorkflowsStore: () => ({ schedulePreview, scheduleAssist: vi.fn() }),
  ScheduleAssistError: class ScheduleAssistError extends Error {},
}));

import WorkflowScheduleBuilder from '../WorkflowScheduleBuilder.vue';

const SCH = en.workflows.schedule;

// Applied by the (stubbed) AI modal → proves apply(draft) flows into the v-model.
const APPLIED: ScheduleDraft = {
  time: { mode: 'at', at: ['08:00'] },
  day: { mode: 'weekdays', weekdays: [3] },
  month: { mode: 'every_month' },
  exclusions: { months: [], weekdays: [], dates: [] },
  tz: '',
};
const AssistModalStub = {
  name: 'WorkflowScheduleAssistModal',
  props: ['open', 'tz'],
  emits: ['apply', 'update:open'],
  setup(_: unknown, { emit }: { emit: (e: string, v?: unknown) => void }) {
    return () => h('button', { class: 'assist-apply', onClick: () => emit('apply', APPLIED) }, 'apply');
  },
};
const InputStub = { props: ['modelValue'], setup: () => () => h('input') };
// A v-model-forwarding stub for the jump-to-date field: a native input carrying the aria-label
// so a test can read/drive the host `anchor` directly (the real DateTimePicker calendar +
// its INNER clear ✕ are covered by its own spec). Setting a value emits update:modelValue;
// clearing (empty value) sends null. It mirrors the `dirty` prop (= !!anchor) onto data-dirty
// so a test can observe the anchor state without the host exposing it.
const AnchorFieldStub = {
  name: 'DateTimePicker',
  props: ['modelValue', 'ariaLabel', 'placeholder', 'clearable', 'dirty', 'size'],
  emits: ['update:modelValue'],
  setup(props: Record<string, unknown>, { emit }: { emit: (e: string, v?: unknown) => void }) {
    return () =>
      h('input', {
        'aria-label': props.ariaLabel as string | undefined,
        'data-dirty': String(!!props.dirty),
        value: (props.modelValue as string | null) ?? '',
        onInput: (e: Event) => emit('update:modelValue', (e.target as HTMLInputElement).value || null),
      });
  },
};

function page(occurrences: string[], empty = false) {
  return { occurrences, count: occurrences.length, empty, approximate: false };
}

function mountBuilder(draft: ScheduleDraft, errors: Record<string, string> = {}) {
  const wrapper = mount(WorkflowScheduleBuilder, {
    props: {
      modelValue: draft,
      errors,
      'onUpdate:modelValue': (v: ScheduleDraft) => wrapper.setProps({ modelValue: v }),
    },
    global: {
      stubs: {
        WorkflowScheduleAssistModal: AssistModalStub,
        DateTimePicker: AnchorFieldStub,
        DatePicker: InputStub,
      },
    },
  });
  return wrapper;
}

type W = VueWrapper;
const draftOf = (w: W) => w.props('modelValue') as ScheduleDraft;
const radio = (w: W, label: string) => w.findAll('[role="radio"]').find((r) => r.text().includes(label));
const tab = (w: W, label: string) => w.findAll('[role="tab"]').find((t) => t.text().includes(label));

async function flushPreview(): Promise<void> {
  vi.advanceTimersByTime(450);
  await Promise.resolve();
  await Promise.resolve();
  await nextTick();
  await nextTick();
}

describe('WorkflowScheduleBuilder (Phase 4b — three-tab builder)', () => {
  beforeEach(() => {
    installBrowserMocks();
    vi.useFakeTimers();
    setLocale('en');
    schedulePreview.mockReset();
    schedulePreview.mockResolvedValue(page(['2026-07-13T09:00:00Z']));
  });
  afterEach(() => {
    vi.useRealTimers();
    restoreBrowserMocks();
  });

  it('renders the summary sentence + the three axis tabs', () => {
    const wrapper = mountBuilder(emptyScheduleDraft());
    expect(wrapper.text()).toContain('Daily at 09:00');
    expect(tab(wrapper, SCH.tab.time)).toBeTruthy();
    expect(tab(wrapper, SCH.tab.day)).toBeTruthy();
    expect(tab(wrapper, SCH.tab.month)).toBeTruthy();
    wrapper.unmount();
  });

  it('switching a Time sub-mode reshapes ONLY the time axis (foreign fields drop)', async () => {
    const wrapper = mountBuilder(emptyScheduleDraft());
    await radio(wrapper, SCH.time.mode.everyMinutes)!.trigger('click');
    await nextTick();
    // `at` is gone; a fresh every_minutes axis (n default 1) with no window.
    expect(draftOf(wrapper).time).toEqual({ mode: 'every_minutes', n: 1 });
    // Day + month + exclusions are preserved untouched.
    expect(draftOf(wrapper).day).toEqual({ mode: 'every_day' });
    wrapper.unmount();
  });

  it('switching Day → weekdays clears foreign fields; a weekday chip toggles membership', async () => {
    const wrapper = mountBuilder(emptyScheduleDraft());
    await radio(wrapper, SCH.day.mode.weekdays)!.trigger('click');
    await nextTick();
    expect(draftOf(wrapper).day).toEqual({ mode: 'weekdays', weekdays: [] });
    // The weekday chip group (scoped by its aria-label, distinct from the exclusions group).
    const group = wrapper.findAll('[role="group"]').find((g) => g.attributes('aria-label') === SCH.day.mode.weekdays)!;
    const mon = group.findAll('button').find((b) => b.text() === SCH.weekday.short['1'])!;
    await mon.trigger('click');
    await nextTick();
    expect((draftOf(wrapper).day as { weekdays: number[] }).weekdays).toEqual([1]);
    wrapper.unmount();
  });

  it('the window is both-or-neither: the toggle switch adds/removes the whole window', async () => {
    const draft = emptyScheduleDraft();
    draft.time = { mode: 'every_minutes', n: 5 };
    const wrapper = mountBuilder(draft);
    // REV5.1: the gate is a bare Switch (role="switch"), not a checkbox; the from/to inputs
    // are always rendered and just flip disabled — toggling still adds/removes the window.
    const sw = wrapper.find('button[role="switch"]');
    expect(sw.exists()).toBe(true);
    await sw.trigger('click');
    await nextTick();
    expect((draftOf(wrapper).time as { window?: unknown }).window).toEqual({ from: '09:00', to: '17:00' });
    await sw.trigger('click');
    await nextTick();
    expect((draftOf(wrapper).time as { window?: unknown }).window).toBeUndefined();
    wrapper.unmount();
  });

  it('a from ≥ to window surfaces the order error and blocks isValid', async () => {
    const draft = emptyScheduleDraft();
    draft.time = { mode: 'every_minutes', n: 5, window: { from: '18:00', to: '09:00' } };
    const wrapper = mountBuilder(draft);
    await flushPreview();
    expect(wrapper.text()).toContain(SCH.validation.windowOrder);
    expect((wrapper.vm as unknown as { isValid: boolean }).isValid).toBe(false);
    wrapper.unmount();
  });

  it('the last_working_day rule LOCKS time to `at` (auto-reset + disabled cards)', async () => {
    const draft = emptyScheduleDraft();
    draft.time = { mode: 'every_minutes', n: 10 };
    const wrapper = mountBuilder(draft);
    await radio(wrapper, SCH.day.mode.lastWorkingDay)!.trigger('click');
    await nextTick();
    // Time auto-reset to `at` (§4.5.5a).
    expect(draftOf(wrapper).time).toEqual({ mode: 'at', at: ['09:00'] });
    // The every_* time cards are disabled + the explanation is shown (never a bare gray-out).
    expect(radio(wrapper, SCH.time.mode.everyMinutes)!.attributes('disabled')).toBeDefined();
    expect(wrapper.text()).toContain(SCH.time.lockedByLastWorkingDay);
    wrapper.unmount();
  });

  it('the at[] list caps at the schedule limit (add disabled at 6)', async () => {
    const wrapper = mountBuilder(emptyScheduleDraft());
    const addBtn = () => wrapper.findAll('button').find((b) => b.text().includes(SCH.field.addTime))!;
    for (let i = 0; i < 5; i += 1) {
      if (addBtn().attributes('disabled') !== undefined) break;
      await addBtn().trigger('click');
      await nextTick();
    }
    expect((draftOf(wrapper).time as { at: string[] }).at.length).toBe(6);
    expect(addBtn().attributes('disabled')).toBeDefined();
    wrapper.unmount();
  });

  it('a 422 under trigger_config.schedule.day.* switches to the Day tab', async () => {
    const wrapper = mountBuilder(emptyScheduleDraft());
    expect(tab(wrapper, SCH.tab.time)!.attributes('aria-selected')).toBe('true');
    await wrapper.setProps({ errors: { 'trigger_config.schedule.day.weekdays': 'bad' } });
    await nextTick();
    expect(tab(wrapper, SCH.tab.day)!.attributes('aria-selected')).toBe('true');
    wrapper.unmount();
  });

  it('a 422 under trigger_config.schedule.exclusions.* opens the exceptions disclosure', async () => {
    const wrapper = mountBuilder(emptyScheduleDraft());
    await wrapper.setProps({ errors: { 'trigger_config.schedule.exclusions.dates': 'bad' } });
    await nextTick();
    const header = wrapper.findAll('button').find((b) => b.text().includes(SCH.exclusions.title))!;
    expect(header.attributes('aria-expanded')).toBe('true');
    wrapper.unmount();
  });

  it('isValid is TRUE for a valid draft + non-empty preview, FALSE for an empty preview', async () => {
    const ok = mountBuilder(emptyScheduleDraft());
    await flushPreview();
    expect((ok.vm as unknown as { isValid: boolean }).isValid).toBe(true);
    ok.unmount();

    schedulePreview.mockResolvedValue(page([], true));
    const blocked = mountBuilder(emptyScheduleDraft());
    await flushPreview();
    expect((blocked.vm as unknown as { isValid: boolean }).isValid).toBe(false);
    blocked.unmount();
  });

  it('applying the AI modal proposal replaces the whole draft', async () => {
    const wrapper = mountBuilder(emptyScheduleDraft());
    await wrapper.find('.assist-apply').trigger('click');
    await nextTick();
    expect(draftOf(wrapper)).toEqual(APPLIED);
    wrapper.unmount();
  });

  it('REV5.1: jump-to-date is a DIRECT field bound to the anchor — no Popover, and clear lives INSIDE the field (no external button)', async () => {
    const wrapper = mountBuilder(emptyScheduleDraft());
    // The live sentence + the "Skocz do daty" FIELD live together in the one segment (the
    // anchor lives with the host). The field is rendered DIRECTLY — no icon+Popover trigger.
    expect(wrapper.text()).toContain('Daily at 09:00');
    const field = () => wrapper.find(`input[aria-label="${SCH.preview.jumpTo}"]`);
    expect(field().exists()).toBe(true);
    // REV5.1: the clear ✕ now lives INSIDE the field (DateTimePicker `clearable`, default on),
    // so there is NO external sibling clear button in the segment — ever. The field's inner ✕
    // behavior is covered by the DateTimePicker spec; here we assert the host wiring only.
    expect(wrapper.find(`button[aria-label="${en.common.clear}"]`).exists()).toBe(false);
    // The anchor starts null → the field is not dirty (its inner ✕ would be hidden)…
    expect(field().attributes('data-dirty')).toBe('false');
    // …setting a date THROUGH the field drives the host anchor (dirty tint on)…
    await field().setValue('2026-07-20T09:00');
    await nextTick();
    expect(field().attributes('data-dirty')).toBe('true');
    // …and emptying it (the field's own clear path) resets the anchor back to null.
    await field().setValue('');
    await nextTick();
    expect(field().attributes('data-dirty')).toBe('false');
    expect(wrapper.find(`button[aria-label="${en.common.clear}"]`).exists()).toBe(false);
    wrapper.unmount();
  });

  it('REV5 fix: `at` times render in ONE horizontal flex-wrap row (left→right, not stacked)', async () => {
    const wrapper = mountBuilder(emptyScheduleDraft());
    // Add a 2nd time so there are two pickers to place side by side.
    const addBtn = wrapper.findAll('button').find((b) => b.text().includes(SCH.field.addTime))!;
    await addBtn.trigger('click');
    await nextTick();
    // The `at` card body (role=group, labelled by the mode title) holds ONE wrapping row…
    const atBody = wrapper.findAll('[role="group"]').find((g) => g.attributes('aria-label') === SCH.time.mode.at)!;
    const row = atBody.find('.flex.flex-wrap');
    expect(row.exists()).toBe(true);
    // …and BOTH time inputs live inside that single wrapping row (horizontal flow, not stacked).
    const times = row.findAll(`input[aria-label^="${SCH.field.times}"]`);
    expect(times.length).toBe(2);
    wrapper.unmount();
  });

  it('REV5: the exceptions disclosure holds skip-DATES only (no weekday/month chips, no tz field)', async () => {
    const wrapper = mountBuilder(emptyScheduleDraft());
    const header = wrapper.findAll('button').find((b) => b.text().includes(SCH.exclusions.title))!;
    await header.trigger('click');
    await nextTick();
    // The dates control is present; the weekday/month exclusion labels + the tz field keys
    // were superseded (removed from the catalog entirely — §4.5.7/§4.5.8).
    expect(wrapper.text()).toContain(SCH.exclusions.datesLabel);
    expect((SCH.exclusions as Record<string, unknown>).weekdaysLabel).toBeUndefined();
    expect((SCH.exclusions as Record<string, unknown>).monthsLabel).toBeUndefined();
    expect((SCH as Record<string, unknown>).tz).toBeUndefined();
    wrapper.unmount();
  });
});
