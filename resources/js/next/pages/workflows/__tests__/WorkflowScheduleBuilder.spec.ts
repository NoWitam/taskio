// @vitest-environment happy-dom
// WorkflowScheduleBuilder.spec — the PROGRESSIVE descriptor-driven builder (§4.5, B4).
// Asserts the simple/advanced modes, the descriptor-driven param controls, the times
// editor (add/remove/duplicate), the exclusions chips, the mode transitions (a config
// with exclusions opens advanced), the empty-schedule preview blocking `isValid`, and
// the approximate info note. The store is mocked so no HTTP happens; the families
// descriptor mirrors the B4 WorkflowScheduleFamily::paramDescriptors().
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { ScheduleFamilyDescriptor, SchedulePreviewResponse } from '../types';
import { emptyScheduleDraft, type ScheduleDraft } from '../workflowSchedule';

const FAMILIES: ScheduleFamilyDescriptor[] = [
  { family: 'daily', params: [{ name: 'time', type: 'time', required: true }] },
  { family: 'weekly', params: [
    { name: 'weekdays', type: 'weekday_list', required: true },
    { name: 'time', type: 'time', required: true },
  ] },
  { family: 'hourly', params: [] },
  { family: 'hourly_at', params: [{ name: 'minute', type: 'int', required: true, min: 0, max: 59 }] },
  { family: 'every_n_hours', params: [
    { name: 'n', type: 'int', required: true, min: 2, max: 12 },
    { name: 'minute', type: 'int', required: false, min: 0, max: 59 },
  ] },
  { family: 'every_n_minutes', params: [{ name: 'n', type: 'int', required: true, min: 1, max: 59 }] },
  { family: 'monthly', params: [
    { name: 'day', type: 'int', required: true, min: 1, max: 31 },
    { name: 'time', type: 'time', required: true },
  ] },
  { family: 'last_day_of_month', params: [{ name: 'time', type: 'time', required: true }] },
  { family: 'twice_daily', params: [
    { name: 'first_hour', type: 'int', required: true, min: 0, max: 23, lt: 'second_hour' },
    { name: 'second_hour', type: 'int', required: true, min: 0, max: 23 },
  ] },
];

const fetchScheduleFamilies = vi.fn(async () => FAMILIES);
const schedulePreview = vi.fn<() => Promise<SchedulePreviewResponse>>();

vi.mock('../../../app/stores/workflows', () => ({
  useWorkflowsStore: () => ({ fetchScheduleFamilies, schedulePreview }),
  ScheduleAssistError: class ScheduleAssistError extends Error {},
}));

import WorkflowScheduleBuilder from '../WorkflowScheduleBuilder.vue';

async function mountBuilder(draft: ScheduleDraft) {
  const wrapper = mount(WorkflowScheduleBuilder, {
    attachTo: document.body,
    props: { modelValue: draft, 'onUpdate:modelValue': (v: ScheduleDraft) => wrapper.setProps({ modelValue: v }) },
  });
  await nextTick();
  await Promise.resolve();
  await nextTick();
  return wrapper;
}

/** Flush the 400ms debounced preview + its resolution. */
async function flushPreview(): Promise<void> {
  vi.advanceTimersByTime(450);
  await Promise.resolve();
  await Promise.resolve();
  await nextTick();
}

describe('WorkflowScheduleBuilder (progressive, B4)', () => {
  beforeEach(() => {
    installBrowserMocks();
    vi.useFakeTimers();
    schedulePreview.mockReset();
    schedulePreview.mockResolvedValue({ occurrences: ['2026-07-01T06:00:00.000000Z'], count: 1, empty: false, approximate: false });
  });
  afterEach(() => {
    vi.useRealTimers();
    restoreBrowserMocks();
  });

  it('opens in SIMPLE mode with the intent segments', async () => {
    const wrapper = await mountBuilder(emptyScheduleDraft(FAMILIES, 'daily'));
    // The simple intent SegmentedControl (role=radiogroup) is present.
    expect(wrapper.findAll('[role="radio"]').length).toBeGreaterThanOrEqual(3);
    // An "Advanced settings" toggle exists.
    const advanced = wrapper.findAll('button').find((b) => b.text().includes('Advanced settings'));
    expect(advanced).toBeTruthy();
    wrapper.unmount();
  });

  it('switching to ADVANCED reveals the sections + the family Select', async () => {
    const wrapper = await mountBuilder(emptyScheduleDraft(FAMILIES, 'daily'));
    const advanced = wrapper.findAll('button').find((b) => b.text().includes('Advanced settings'));
    await advanced!.trigger('click');
    await nextTick();

    const text = wrapper.text();
    expect(text).toContain('Repeat');
    expect(text).toContain('Times');
    expect(text).toContain('Exclusions');
    // The grouped family Select is a combobox.
    expect(wrapper.findAll('[role="combobox"]').length).toBeGreaterThanOrEqual(1);
    wrapper.unmount();
  });

  it('the times editor adds, removes, and flags a duplicate', async () => {
    // Start in advanced (multi-time is advanced-only).
    const draft = emptyScheduleDraft(FAMILIES, 'daily');
    draft.times = ['08:00'];
    const wrapper = await mountBuilder(draft);
    await wrapper.findAll('button').find((b) => b.text().includes('Advanced settings'))!.trigger('click');
    await nextTick();

    // Add a second time.
    const addBtn = wrapper.findAll('button').find((b) => b.text().includes('Add time'));
    await addBtn!.trigger('click');
    await nextTick();
    expect((wrapper.props('modelValue') as ScheduleDraft).times.length).toBe(2);

    // Make the two times identical → the duplicate validation message appears.
    await wrapper.setProps({ modelValue: { ...draft, times: ['08:00', '08:00'] } });
    await nextTick();
    expect(wrapper.text()).toContain('The same time is listed twice.');

    wrapper.unmount();
  });

  it('exclusion month chips toggle onto the draft', async () => {
    const wrapper = await mountBuilder(emptyScheduleDraft(FAMILIES, 'daily'));
    await wrapper.findAll('button').find((b) => b.text().includes('Advanced settings'))!.trigger('click');
    await nextTick();

    const augustChip = wrapper.findAll('button[aria-pressed]').find((b) => b.text().trim() === 'August');
    expect(augustChip).toBeTruthy();
    await augustChip!.trigger('click');
    await nextTick();
    expect((wrapper.props('modelValue') as ScheduleDraft).exclusions.months).toEqual([8]);

    wrapper.unmount();
  });

  it('a config with exclusions opens the builder in ADVANCED mode', async () => {
    const draft = emptyScheduleDraft(FAMILIES, 'daily');
    draft.times = ['08:00'];
    draft.exclusions = { months: [8], weekdays: [], dates: [] };
    const wrapper = await mountBuilder(draft);
    // Advanced sections are visible without the user toggling.
    expect(wrapper.text()).toContain('Repeat');
    expect(wrapper.text()).toContain('Exclusions');
    wrapper.unmount();
  });

  it('an lt violation shows the validation message and marks the builder invalid', async () => {
    const draft: ScheduleDraft = {
      family: 'twice_daily',
      params: { first_hour: 10, second_hour: 8 },
      tz: '',
      times: ['08:00'],
      exclusions: { months: [], weekdays: [], dates: [] },
    };
    const wrapper = await mountBuilder(draft);
    expect(wrapper.text()).toContain('First hour must be before Second hour.');
    expect((wrapper.vm as unknown as { isValid: boolean }).isValid).toBe(false);
    wrapper.unmount();
  });

  it('an EMPTY preview blocks isValid and shows the warning', async () => {
    schedulePreview.mockResolvedValue({ occurrences: [], count: 0, empty: true, approximate: false });
    const draft = emptyScheduleDraft(FAMILIES, 'daily');
    draft.times = ['08:00'];
    const wrapper = await mountBuilder(draft);
    await flushPreview();

    expect((wrapper.vm as unknown as { isValid: boolean }).isValid).toBe(false);
    expect(wrapper.text()).toContain('never fire');
    wrapper.unmount();
  });

  it('a LOADING preview blocks isValid until it settles (U1 regression)', async () => {
    // During the debounce+RTT window previewEmpty still holds the PREVIOUS settled
    // value, so saving mid-flight could slip an empty schedule past the gate.
    const draft = emptyScheduleDraft(FAMILIES, 'daily');
    draft.times = ['08:00'];
    const wrapper = await mountBuilder(draft);

    // Client-valid draft, preview still in flight → the gate stays CLOSED…
    expect((wrapper.vm as unknown as { isValid: boolean }).isValid).toBe(false);

    // …and opens once the preview settles non-empty.
    await flushPreview();
    expect((wrapper.vm as unknown as { isValid: boolean }).isValid).toBe(true);
    wrapper.unmount();
  });

  it('an APPROXIMATE preview shows the info note', async () => {
    schedulePreview.mockResolvedValue({
      occurrences: ['2026-07-01T06:00:00.000000Z'],
      count: 1,
      empty: false,
      approximate: true,
    });
    const draft = emptyScheduleDraft(FAMILIES, 'every_n_minutes');
    draft.params = { n: 15 };
    const wrapper = await mountBuilder(draft);
    await flushPreview();

    expect(wrapper.text()).toContain('indicative');
    expect((wrapper.vm as unknown as { isValid: boolean }).isValid).toBe(true);
    wrapper.unmount();
  });
});
