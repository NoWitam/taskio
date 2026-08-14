// @vitest-environment happy-dom
// AgendaList.spec — the same 42-day window as the grid, read as a list.
//
// The agenda is not "the grid with the boxes removed"; four of its rules are decisions the
// grid does not make, and each is wrong in a way that looks fine on screen:
//
//   • EMPTY DAYS ARE DROPPED. A grid's empty square is meaningful (it is a day); a list's
//     empty heading is noise, and forty of them bury the four real ones.
//   • THE HEADING COUNTS THE DAY, NOT THE ROWS. A folded dense series is ONE row standing for
//     many, so counting rows would make a busy day look quiet — the one number a user scans
//     the column for.
//   • THE HEADING NAMES ITS MONTH. The window spills into the neighbouring months by design
//     (one window feeds both surfaces), so "12" alone would be ambiguous twice a month.
//   • A FOLDED ROW OPENS THE DAY, NOT "THE OCCURRENCE". There is no single occurrence behind
//     it; sending the user to one of the twelve it stands for would be a quiet lie.
//
// Order inside a day is the SERVER's (all-day first, then by time, then id) and is asserted
// here because re-sorting client-side is the easy mistake that silently drops that rule.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';

import AgendaList from '../AgendaList.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { CalendarOccurrence, IsoDay } from '../types';

const TIMEZONE = 'Europe/Warsaw';

function occurrence(id: string, over: Partial<CalendarOccurrence> = {}): CalendarOccurrence {
  return {
    id,
    source: 'event',
    editable: false,
    all_day: true,
    start_date: '2026-08-10',
    starts_at: null,
    ends_at: null,
    title: id,
    color: 'neutral',
    badge: null,
    dense: false,
    cadence_label: null,
    subject: { type: 'calendar_event', id },
    ...over,
  } as CalendarOccurrence;
}

function mountAgenda(
  days: IsoDay[],
  buckets: Record<string, CalendarOccurrence[]>,
  over: Record<string, unknown> = {},
) {
  return mount(AgendaList, {
    props: {
      days,
      buckets: new Map(Object.entries(buckets)),
      timezone: TIMEZONE,
      locale: 'en',
      today: '2026-08-10',
      busy: false,
      sourceLabelOf: (id: string) => `Source ${id}`,
      ...over,
    },
  });
}

beforeEach(() => {
  installBrowserMocks();
  setLocale('en');
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('AgendaList', () => {
  it('drops the empty days — a list is a list of what there IS', () => {
    const wrapper = mountAgenda(['2026-08-09', '2026-08-10', '2026-08-11', '2026-08-12'], {
      '2026-08-10': [occurrence('a')],
      '2026-08-12': [occurrence('b', { start_date: '2026-08-12' })],
    });

    const groups = wrapper.findAll('[role="group"]');
    expect(groups).toHaveLength(2);
    expect(wrapper.text()).not.toContain('9 August');
    expect(wrapper.text()).not.toContain('11 August');
    wrapper.unmount();
  });

  it('renders NOTHING at all when the whole window is empty', () => {
    // The EmptyState belongs to the page (it knows whether filters are on); the list itself
    // must not invent a second, contradictory empty message.
    const wrapper = mountAgenda(['2026-08-09', '2026-08-10'], {});

    expect(wrapper.findAll('[role="group"]')).toHaveLength(0);
    wrapper.unmount();
  });

  it('names the MONTH in every heading — the window spills into its neighbours', () => {
    const wrapper = mountAgenda(['2026-07-31', '2026-08-01'], {
      '2026-07-31': [occurrence('july', { start_date: '2026-07-31' })],
      '2026-08-01': [occurrence('august', { start_date: '2026-08-01' })],
    });

    const headings = wrapper.findAll('h2').map((h) => h.text());
    expect(headings[0]).toMatch(/July/i);
    expect(headings[1]).toMatch(/August/i);
    wrapper.unmount();
  });

  it('marks TODAY with a badge AND aria-current, never colour alone', () => {
    const wrapper = mountAgenda(['2026-08-10', '2026-08-11'], {
      '2026-08-10': [occurrence('today')],
      '2026-08-11': [occurrence('tomorrow', { start_date: '2026-08-11' })],
    });

    const marked = wrapper.findAll('[aria-current="date"]');
    expect(marked).toHaveLength(1);
    expect(marked[0].text()).toContain('Today');
    wrapper.unmount();
  });

  it('keeps the group heading STICKY so the day stays readable while scrolling', () => {
    const wrapper = mountAgenda(['2026-08-10'], { '2026-08-10': [occurrence('a')] });

    expect(wrapper.find('[role="group"]').html()).toContain('sticky');
    wrapper.unmount();
  });

  it('preserves the SERVER order inside a day — all-day before timed, never re-sorted', () => {
    const wrapper = mountAgenda(['2026-08-10'], {
      '2026-08-10': [
        occurrence('deadline', { all_day: true }),
        occurrence('late', {
          all_day: false,
          start_date: null,
          starts_at: '2026-08-10T16:00:00+00:00',
          source: 'workflow_run',
        }),
        occurrence('early', {
          all_day: false,
          start_date: null,
          starts_at: '2026-08-10T07:00:00+00:00',
          source: 'workflow_run',
        }),
      ],
    });

    const titles = wrapper.findAll('.next-occurrence-chip').map((c) => c.attributes('title'));
    expect(titles).toEqual(['deadline', 'late', 'early']);
    wrapper.unmount();
  });

  it('counts the DAY in the heading, not the rows a folded series collapsed to', () => {
    const series = Array.from({ length: 12 }, (_, i) =>
      occurrence(`s${i}`, {
        all_day: false,
        start_date: null,
        starts_at: `2026-08-10T0${i % 9}:00:00+00:00`,
        source: 'workflow_schedule',
        dense: true,
        subject: { type: 'workflow', id: 'wf-1' },
      }),
    );

    const wrapper = mountAgenda(['2026-08-10'], { '2026-08-10': [...series, occurrence('a-deadline')] });

    // One folded row for the series + the deadline = 2 rows…
    expect(wrapper.findAll('.next-occurrence-chip')).toHaveLength(2);
    // …but the day genuinely holds 13, and that is the number the heading shows.
    expect(wrapper.find('[role="group"]').text()).toContain('13');
    wrapper.unmount();
  });

  it('sends a FOLDED row to the day, and an ordinary row to itself', async () => {
    const dense = Array.from({ length: 3 }, (_, i) =>
      occurrence(`d${i}`, {
        all_day: false,
        start_date: null,
        starts_at: `2026-08-10T1${i}:00:00+00:00`,
        source: 'workflow_schedule',
        dense: true,
        subject: { type: 'workflow', id: 'wf-1' },
      }),
    );

    const wrapper = mountAgenda(['2026-08-10'], { '2026-08-10': [...dense, occurrence('single')] });

    const chips = wrapper.findAll('.next-occurrence-chip');

    await chips[0].trigger('click');
    expect(wrapper.emitted('open-day')?.[0]).toEqual(['2026-08-10']);
    expect(wrapper.emitted('select')).toBeUndefined();

    await chips[1].trigger('click');
    expect((wrapper.emitted('select')?.[0]?.[0] as CalendarOccurrence).id).toBe('single');
    wrapper.unmount();
  });

  it('shows the badge and the time RANGE in a row — the space the grid cell does not have', () => {
    const wrapper = mountAgenda(['2026-08-10'], {
      '2026-08-10': [
        occurrence('meeting', {
          all_day: false,
          start_date: null,
          // 07:00Z / 08:00Z read in Europe/Warsaw (+02:00 in August).
          starts_at: '2026-08-10T07:00:00+00:00',
          ends_at: '2026-08-10T08:00:00+00:00',
          badge: { label: 'Scheduled', color: 'info' },
          source: 'workflow_run',
        }),
      ],
    });

    const chip = wrapper.find('.next-occurrence-chip');
    expect(chip.text()).toContain('09:00');
    expect(chip.text()).toContain('10:00');
    // Server prose, rendered verbatim — never looked up in the client catalogue.
    expect(chip.text()).toContain('Scheduled');
    wrapper.unmount();
  });

  it('renders an ALL-DAY row with no clock reading at all', () => {
    const wrapper = mountAgenda(['2026-08-10'], { '2026-08-10': [occurrence('deadline')] });

    const chip = wrapper.find('.next-occurrence-chip');
    expect(chip.text()).toContain('all day');
    expect(chip.text()).not.toMatch(/\d{2}:\d{2}/);
    wrapper.unmount();
  });

  it('dims and marks itself busy while a newer window loads, keeping this one readable', () => {
    const wrapper = mountAgenda(['2026-08-10'], { '2026-08-10': [occurrence('a')] }, { busy: true });

    expect(wrapper.attributes('aria-busy')).toBe('true');
    expect(wrapper.classes().join(' ')).toContain('opacity-60');
    // The old window is STILL on screen — blanking it would make paging months flicker.
    expect(wrapper.findAll('.next-occurrence-chip')).toHaveLength(1);
    wrapper.unmount();
  });
});
