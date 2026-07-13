// @vitest-environment happy-dom
// WorkflowSchedulePreviewStrip.axisReset.spec — pins the strip's RESET-on-config
// behavior (§4.5.4). The rail pages FORWARD by re-calling with `anchor` = the last
// shown run; when the schedule config changes (e.g. the builder toggles a time
// sub-mode) the rail must NOT keep paging from that stale last-run anchor. Instead it
// resets: the next call is a fresh FIRST-load (anchor = the null jump anchor, i.e. the
// new base) and the occurrence list is REPLACED, never appended onto the previous
// axis's runs. The sibling WorkflowSchedulePreviewStrip.spec covers the 4 UI states +
// paging; this file isolates the axis-change reset so it is not left implicit.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, h } from 'vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { setLocale } from '../../../app/i18n';
import { en } from '../../../app/i18n/en';
import type { WorkflowScheduleConfig } from '../types';

const schedulePreview = vi.fn();
vi.mock('../../../app/stores/workflows', () => ({
  useWorkflowsStore: () => ({ schedulePreview }),
  ScheduleAssistError: class ScheduleAssistError extends Error {},
}));

import WorkflowSchedulePreviewStrip from '../WorkflowSchedulePreviewStrip.vue';

// The jump-to-date anchor is stubbed to a native input; this spec never sets it, so the
// jump anchor stays null (the "base") throughout.
const DateTimePickerStub = {
  name: 'DateTimePicker',
  props: ['modelValue', 'ariaLabel'],
  setup: () => () => h('input', { class: 'anchor-stub' }),
};

// Two DISTINCT axis configs — a `at` time vs an `every_hours` time — so a config change
// is a real axis change (a new object with different content).
const CONFIG_A: WorkflowScheduleConfig = { time: { mode: 'at', at: ['08:00'] }, tz: 'UTC' };
const CONFIG_B: WorkflowScheduleConfig = { time: { mode: 'every_hours', hours: 3, minute: 0 }, tz: 'UTC' };

function page(occurrences: string[], empty = false) {
  return { occurrences, count: occurrences.length, empty, approximate: false };
}

function mountStrip(config: WorkflowScheduleConfig = CONFIG_A) {
  return mount(WorkflowSchedulePreviewStrip, {
    props: { config, tz: 'UTC' },
    global: { stubs: { DateTimePicker: DateTimePickerStub } },
  });
}

/** Flush the 400ms debounced first-load + its resolution. */
async function flush(): Promise<void> {
  vi.advanceTimersByTime(450);
  await Promise.resolve();
  await Promise.resolve();
  await nextTick();
  await nextTick();
}

const tilesWith = (wrapper: ReturnType<typeof mountStrip>, re: RegExp) =>
  wrapper.findAll('li').filter((li) => re.test(li.text())).length;

describe('WorkflowSchedulePreviewStrip — reset on config (axis) change', () => {
  beforeEach(() => {
    installBrowserMocks();
    vi.useFakeTimers();
    setLocale('en');
    schedulePreview.mockReset();
  });
  afterEach(() => {
    vi.useRealTimers();
    restoreBrowserMocks();
  });

  it('a config change resets the rail: a fresh first-load (no stale paging anchor) that REPLACES the list', async () => {
    // A FULL first page (6) under config A → paging becomes available.
    const first = [
      '2026-07-13T08:00:00Z', '2026-07-14T08:00:00Z', '2026-07-15T08:00:00Z',
      '2026-07-16T08:00:00Z', '2026-07-17T08:00:00Z', '2026-07-18T08:00:00Z',
    ];
    schedulePreview.mockResolvedValueOnce(page(first));
    const wrapper = mountStrip();
    await flush();
    expect(tilesWith(wrapper, /08:00/)).toBe(6);

    // Page once so a stale paging anchor (the last shown run = 07-18) is in play.
    schedulePreview.mockResolvedValueOnce(page(['2026-07-18T08:00:00Z', '2026-07-19T08:00:00Z', '2026-07-20T08:00:00Z']));
    const loadMore = wrapper.findAll('button').find((b) => b.text().includes(en.workflows.schedule.preview.loadMore))!;
    await loadMore.trigger('click');
    await Promise.resolve();
    await nextTick();
    expect(schedulePreview).toHaveBeenLastCalledWith(CONFIG_A, expect.objectContaining({ anchor: '2026-07-18T08:00:00Z' }));
    expect(tilesWith(wrapper, /08:00/)).toBe(8);

    // Change the config (an axis change). The next fetch must be a fresh first-load.
    schedulePreview.mockReset();
    schedulePreview.mockResolvedValueOnce(page(['2026-07-13T00:00:00Z', '2026-07-13T03:00:00Z']));
    await wrapper.setProps({ config: CONFIG_B });
    await flush();

    // Exactly ONE call, for the NEW config, with the null jump anchor (the base) — NOT
    // the stale 07-18 paging anchor.
    expect(schedulePreview).toHaveBeenCalledTimes(1);
    expect(schedulePreview).toHaveBeenLastCalledWith(CONFIG_B, { count: 6, anchor: null });
    // The list is REPLACED by the 2 fresh runs, not appended onto the 8 stale A-runs.
    expect(tilesWith(wrapper, /00:00|03:00/)).toBe(2);
    expect(tilesWith(wrapper, /08:00/)).toBe(0);
    wrapper.unmount();
  });
});
