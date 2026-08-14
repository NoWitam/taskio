// @vitest-environment happy-dom
// MonthGrid.spec — the month surface: ARIA structure, the all-day invariant end to end,
// folding, the honest overflow counter, and the keyboard contract.
//
// This mounts the real DayCell and OccurrenceChip underneath, so it also serves as the
// smoke test for the three components a user actually looks at.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('../../../app/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

import MonthGrid from '../MonthGrid.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { buildMonthWeeks } from '../../../ui/forms/date/dateCore';
import { bucketByDay } from '../occurrenceGroups';
import type { CalendarOccurrence } from '../types';

const AUGUST_2026 = new Date(2026, 7, 1);

function occurrence(over: Partial<CalendarOccurrence> & { id: string }): CalendarOccurrence {
  return {
    source: 'task',
    editable: false,
    all_day: true,
    start_date: '2026-08-09',
    starts_at: null,
    ends_at: null,
    title: over.id,
    color: 'neutral',
    badge: null,
    dense: false,
    cadence_label: null,
    subject: { type: 'task', id: over.id },
    ...over,
  };
}

function mountGrid(occurrences: CalendarOccurrence[], timezone = 'Europe/Warsaw', chipLimit = 3) {
  return mount(MonthGrid, {
    props: {
      weeks: buildMonthWeeks(AUGUST_2026, 1),
      buckets: bucketByDay(occurrences, timezone),
      timezone,
      locale: 'en',
      today: '2026-08-09',
      monthLabel: 'August 2026',
      chipLimit,
      busy: false,
      sourceLabelOf: (id: string) => `Label:${id}`,
    },
  });
}

describe('MonthGrid', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('en');
  });
  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
  });

  it('renders a real ARIA grid: six rows of seven cells, named by its month', () => {
    const wrapper = mountGrid([]);
    const grid = wrapper.find('[role="grid"]');
    expect(grid.attributes('aria-label')).toBe('August 2026');
    expect(wrapper.findAll('[role="row"]')).toHaveLength(6);
    expect(wrapper.findAll('[role="gridcell"]')).toHaveLength(42);
    wrapper.unmount();
  });

  it('marks TODAY with aria-current, not with colour alone', () => {
    const wrapper = mountGrid([]);
    const todayCells = wrapper.findAll('[aria-current="date"]');
    expect(todayCells).toHaveLength(1);
    expect(todayCells[0].attributes('data-iso')).toBe('2026-08-09');
    wrapper.unmount();
  });

  it('keeps exactly ONE tab stop in the whole grid (roving tabindex)', () => {
    // 41 extra tab stops would turn crossing this screen into a journey; the day sheet is
    // the keyboard route to individual chips instead.
    const wrapper = mountGrid([occurrence({ id: 'a' })]);
    const stops = wrapper.findAll('[role="gridcell"][tabindex="0"]');
    expect(stops).toHaveLength(1);
    expect(stops[0].attributes('data-iso')).toBe('2026-08-09'); // seeded on today
    // And the chips inside are explicitly NOT tab stops.
    const chip = wrapper.find('.next-occurrence-chip');
    expect(chip.attributes('tabindex')).toBe('-1');
    wrapper.unmount();
  });

  it('puts an ALL-DAY occurrence on the day it names, even in an extreme zone', () => {
    // The regression this whole module is arranged against: `start_date` is a zone-less
    // calendar day. Parsed as an instant and rendered at UTC+14 it would move a square.
    const wrapper = mountGrid([occurrence({ id: 'deadline', start_date: '2026-08-09' })], 'Pacific/Kiritimati');
    const cell = wrapper.find('[data-iso="2026-08-09"]');
    expect(cell.text()).toContain('deadline');
    expect(wrapper.find('[data-iso="2026-08-08"]').text()).not.toContain('deadline');
    wrapper.unmount();
  });

  it('renders NO time for an all-day chip — not 00:00, not a dash', () => {
    const wrapper = mountGrid([occurrence({ id: 'deadline', title: 'Report due' })]);
    const chip = wrapper.find('.next-occurrence-chip');
    expect(chip.text()).toContain('Report due');
    expect(chip.text()).not.toMatch(/\d{2}:\d{2}/);
    wrapper.unmount();
  });

  it('renders the time for a TIMED chip, read in the WORKSPACE zone', () => {
    const timed = occurrence({
      id: 'run',
      title: 'Campaign',
      all_day: false,
      start_date: null,
      starts_at: '2026-08-09T12:30:00Z',
      source: 'workflow_run',
    });
    const wrapper = mountGrid([timed]);
    // 12:30 UTC is 14:30 in Europe/Warsaw — the team's clock, not the browser's.
    expect(wrapper.find('[data-iso="2026-08-09"]').text()).toContain('14:30');
    wrapper.unmount();
  });

  it('folds a dense series to one chip and counts the overflow AFTER folding', () => {
    // Five raw occurrences on one day: a deadline, a 3-strong dense series, and one more.
    // Folded that is three rows; with room for two, the counter must say "+1".
    const series = [1, 2, 3].map((n) =>
      occurrence({
        id: `s${n}`,
        title: 'Automation',
        all_day: false,
        start_date: null,
        starts_at: `2026-08-09T0${n}:00:00Z`,
        dense: true,
        source: 'workflow_schedule',
        subject: { type: 'workflow', id: 'wf-1' },
      }),
    );
    const wrapper = mountGrid(
      [occurrence({ id: 'deadline' }), ...series, occurrence({ id: 'other' })],
      'Europe/Warsaw',
      2,
    );
    const cell = wrapper.find('[data-iso="2026-08-09"]');
    expect(cell.findAll('.next-occurrence-chip')).toHaveLength(2);
    expect(cell.text()).toContain('+1');
    wrapper.unmount();
  });

  it('announces a day’s REAL total, not its folded row count', () => {
    const series = [1, 2, 3].map((n) =>
      occurrence({
        id: `s${n}`,
        dense: true,
        subject: { type: 'workflow', id: 'wf-1' },
      }),
    );
    const wrapper = mountGrid(series);
    expect(wrapper.find('[data-iso="2026-08-09"]').attributes('aria-label')).toContain('3');
    wrapper.unmount();
  });

  it('opens the day sheet on Enter over a day that has something, and creates on an empty one', async () => {
    const wrapper = mountGrid([occurrence({ id: 'deadline' })]);
    const grid = wrapper.find('[role="grid"]');

    await grid.trigger('keydown', { key: 'Enter' });
    expect(wrapper.emitted('open-day')?.[0]).toEqual(['2026-08-09']);

    // Move to the next (empty) day, then Enter.
    await grid.trigger('keydown', { key: 'ArrowRight' });
    await grid.trigger('keydown', { key: 'Enter' });
    expect(wrapper.emitted('create')?.[0]).toEqual(['2026-08-10']);
    wrapper.unmount();
  });

  it('pages the month with PageUp / PageDown, and a year with Shift', async () => {
    const wrapper = mountGrid([]);
    const grid = wrapper.find('[role="grid"]');
    await grid.trigger('keydown', { key: 'PageDown' });
    await grid.trigger('keydown', { key: 'PageUp' });
    await grid.trigger('keydown', { key: 'PageDown', shiftKey: true });
    expect(wrapper.emitted('shift-month')).toEqual([[1], [-1], [12]]);
    wrapper.unmount();
  });

  it('asks the parent to page when an arrow walks off the loaded window', async () => {
    const wrapper = mountGrid([]);
    const grid = wrapper.find('[role="grid"]');
    // The window is 2026-07-27 … 2026-09-06. Walk left off the front edge.
    for (let i = 0; i < 14; i += 1) await grid.trigger('keydown', { key: 'ArrowLeft' });
    expect(wrapper.emitted('shift-month')).toBeTruthy();
    wrapper.unmount();
  });

  it('dims the grid and marks it busy while a newer window loads, keeping the old one visible', async () => {
    const wrapper = mountGrid([occurrence({ id: 'deadline' })]);
    await wrapper.setProps({ busy: true });
    const grid = wrapper.find('[role="grid"]');
    expect(grid.attributes('aria-busy')).toBe('true');
    // The previous window is still on screen — paging months must never blank the grid.
    expect(grid.text()).toContain('deadline');
    wrapper.unmount();
  });

  it('COMPACT mode replaces chips with dots and makes the whole day one target', () => {
    const wrapper = mountGrid([occurrence({ id: 'deadline' })], 'Europe/Warsaw', 0);
    const cell = wrapper.find('[data-iso="2026-08-09"]');
    expect(cell.findAll('.next-occurrence-chip')).toHaveLength(0);
    expect(cell.text()).toContain('1'); // the count beside the day number
    wrapper.unmount();
  });

  it('shows an UNKNOWN source with the fallback glyph instead of failing to render', () => {
    // The R4 case: a source id this build has never heard of must still draw.
    const wrapper = mountGrid([occurrence({ id: 'pub', source: 'publication', title: 'Post' })]);
    expect(wrapper.find('[data-iso="2026-08-09"]').text()).toContain('Post');
    wrapper.unmount();
  });
});
