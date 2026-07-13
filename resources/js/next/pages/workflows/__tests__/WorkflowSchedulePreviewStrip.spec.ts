// @vitest-environment happy-dom
// WorkflowSchedulePreviewStrip.spec — the upcoming-runs rail (§4.5.4, REV5). Asserts the
// 4 UI states (loading skeletons / warning-empty / quiet-unavailable / the tile rail),
// the HOST-owned `anchor` PROP re-seeding a distinct "previous" (prev-or-at) tile (REV5:
// the jump-to-date trigger moved to the segment row, so the anchor arrives as a prop —
// no in-strip DateTimePicker), and FORWARD paging (re-call with anchor = the last shown
// run, append the rest). The store is mocked; locale is pinned to en for stable copy.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
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

const CONFIG: WorkflowScheduleConfig = { time: { mode: 'at', at: ['08:00'] }, tz: 'UTC' };

function page(occurrences: string[], empty = false) {
  return { occurrences, count: occurrences.length, empty, approximate: false };
}

function mountStrip(config: WorkflowScheduleConfig = CONFIG, anchor: string | null = null) {
  return mount(WorkflowSchedulePreviewStrip, {
    props: { config, tz: 'UTC', anchor },
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

describe('WorkflowSchedulePreviewStrip', () => {
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

  it('LOADING → skeleton tiles (role=status)', async () => {
    schedulePreview.mockReturnValue(new Promise(() => {})); // never resolves
    const wrapper = mountStrip();
    await nextTick();
    expect(wrapper.find('[role="status"]').exists()).toBe(true);
    expect((wrapper.vm as unknown as { loading: boolean }).loading).toBe(true);
    wrapper.unmount();
  });

  it('SUCCESS → the tile rail renders a tile per occurrence', async () => {
    schedulePreview.mockResolvedValue(page(['2026-07-13T08:00:00Z', '2026-07-14T08:00:00Z']));
    const wrapper = mountStrip();
    await flush();
    // Two tiles, time rendered in UTC.
    const text = wrapper.text();
    expect(text).toContain('08:00');
    expect((wrapper.vm as unknown as { empty: boolean }).empty).toBe(false);
    wrapper.unmount();
  });

  it('EMPTY → a warning Alert that blocks (exposes empty=true)', async () => {
    schedulePreview.mockResolvedValue(page([], true));
    const wrapper = mountStrip();
    await flush();
    expect(wrapper.text()).toContain(en.workflows.schedule.preview.empty);
    expect((wrapper.vm as unknown as { empty: boolean }).empty).toBe(true);
    wrapper.unmount();
  });

  it('ERROR → quiet, NON-blocking (empty stays false)', async () => {
    schedulePreview.mockRejectedValue(new Error('network'));
    const wrapper = mountStrip();
    await flush();
    expect(wrapper.text()).toContain(en.workflows.schedule.preview.unavailable);
    expect((wrapper.vm as unknown as { empty: boolean }).empty).toBe(false);
    expect((wrapper.vm as unknown as { loading: boolean }).loading).toBe(false);
    wrapper.unmount();
  });

  it('ANCHOR (host prop) → refetches with the anchor and marks a prev-or-at "previous" tile', async () => {
    schedulePreview.mockResolvedValue(page(['2026-07-13T08:00:00Z']));
    const wrapper = mountStrip();
    await flush();

    // The HOST sets the jump-to anchor via the prop (an absolute instant so prev-or-at
    // is deterministic).
    schedulePreview.mockResolvedValue(page(['2026-07-10T08:00:00Z', '2026-07-11T08:00:00Z']));
    await wrapper.setProps({ anchor: '2026-07-10T12:00:00Z' });
    await flush();

    // The store was called with the anchor.
    expect(schedulePreview).toHaveBeenLastCalledWith(CONFIG, expect.objectContaining({ anchor: '2026-07-10T12:00:00Z' }));
    // The first tile (08:00 ≤ 12:00 anchor) is the "previous" tile — REV5: "poprzednie"
    // lives ONLY in the aria-label (no visible caption), so assert the aria-label.
    const previous = wrapper
      .findAll('[aria-label]')
      .find((el) => (el.attributes('aria-label') ?? '').includes(en.workflows.schedule.preview.previousTile));
    expect(previous).toBeTruthy();
    wrapper.unmount();
  });

  it('PAGING → "Load more" re-calls with anchor = the last run and appends the rest', async () => {
    // A full first page (6) → hasMore → the "Load more" affordance shows.
    const first = ['2026-07-13T08:00:00Z', '2026-07-14T08:00:00Z', '2026-07-15T08:00:00Z', '2026-07-16T08:00:00Z', '2026-07-17T08:00:00Z', '2026-07-18T08:00:00Z'];
    schedulePreview.mockResolvedValueOnce(page(first));
    const wrapper = mountStrip();
    await flush();

    // The next page: occurrences[0] is the prev-or-at of the anchor (= the last shown,
    // dropped as a duplicate), then two fresh runs.
    schedulePreview.mockResolvedValueOnce(page(['2026-07-18T08:00:00Z', '2026-07-19T08:00:00Z', '2026-07-20T08:00:00Z']));
    const loadMore = wrapper.findAll('button').find((b) => b.text().includes(en.workflows.schedule.preview.loadMore));
    expect(loadMore).toBeTruthy();
    await loadMore!.trigger('click');
    await Promise.resolve();
    await nextTick();

    expect(schedulePreview).toHaveBeenLastCalledWith(CONFIG, expect.objectContaining({ anchor: '2026-07-18T08:00:00Z' }));
    // 6 originals + 2 fresh (the duplicate 07-18 is filtered out) = 8.
    expect((wrapper.vm as unknown as { empty: boolean }).empty).toBe(false);
    expect(wrapper.findAll('li').filter((li) => /08:00/.test(li.text())).length).toBe(8);
    wrapper.unmount();
  });
});
