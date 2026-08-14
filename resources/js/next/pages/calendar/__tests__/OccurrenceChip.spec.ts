// @vitest-environment happy-dom
// OccurrenceChip.spec — the SERIES MARKER, and the one field that makes it worth drawing.
//
// A folded row stands for a series the grid cannot draw. For a long time the only thing the
// marker could say was how many occurrences it had folded — a number about the CHIP, not
// about the thing on it, and one a reader gains nothing from ("Series — showing 64": showing
// 64 of how many?). The contract now carries `cadence_label`: finished prose from the source
// that owns the schedule ("Every 5 min", "Every 2 h, 09:00–17:00"). That is the sentence the
// marker exists to show.
//
// THREE PROPERTIES, each of which fails silently if it slips:
//
//   1. THE PROSE IS RENDERED VERBATIM AND NEVER TRANSLATED HERE. Same doctrine as
//      `badge.label` and the source names: the server sends finished text in the reader's
//      language. A client-side lookup would mean every new source with a cadence needs a
//      frontend change — the opposite of the promise this screen keeps.
//
//   2. ABSENT AND `null` ARE ONE CASE. The field is optional and empty far more often than
//      not (the server labels interval schedules only), so an occurrence whose key is missing
//      and one whose key is null must render the SAME thing. Two branches here would mean a
//      chip that looks different depending on which server version answered.
//
//   3. THE GRID KEEPS ITS NUMBER. An ~11rem cell has room for the title or for a sentence,
//      not both — but the cadence must still REACH a grid reader, so it goes on the marker's
//      tooltip and into the accessible label. Hidden, never lost.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';

import OccurrenceChip from '../OccurrenceChip.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { CalendarOccurrence, OccurrenceVariant } from '../types';

const TIMEZONE = 'Europe/Warsaw';

/** A schedule occurrence — the only kind that carries a cadence today. */
function occurrence(over: Partial<CalendarOccurrence> = {}): CalendarOccurrence {
  return {
    id: 'workflow_schedule:wf-1:2026-08-10T09:00:00Z',
    source: 'workflow_schedule',
    editable: false,
    all_day: false,
    start_date: null,
    starts_at: '2026-08-10T07:00:00Z',
    ends_at: null,
    title: 'Poll the inbox',
    color: 'info',
    badge: null,
    dense: true,
    cadence_label: 'Every 5 min',
    subject: { type: 'workflow', id: 'wf-1' },
    ...over,
  };
}

function mountChip(
  over: Partial<CalendarOccurrence> = {},
  props: { variant?: OccurrenceVariant; shown?: number; folded?: boolean } = {},
) {
  return mount(OccurrenceChip, {
    props: {
      occurrence: occurrence(over),
      timezone: TIMEZONE,
      variant: props.variant ?? 'agenda',
      shown: props.shown ?? 64,
      folded: props.folded ?? true,
    },
  });
}

/** The series marker's own text (the `repeat` badge), or '' when there is no marker. */
function markerText(wrapper: ReturnType<typeof mountChip>): string {
  const badges = wrapper.findAll('.next-badge');
  return badges.length ? badges[0].text() : '';
}

beforeEach(() => {
  installBrowserMocks();
  setLocale('en');
});

afterEach(() => {
  restoreBrowserMocks();
  setLocale('en');
  document.body.innerHTML = '';
});

describe('OccurrenceChip — the series marker says the CADENCE, not the count', () => {
  it('renders the server’s prose on a folded row, in place of the count', () => {
    const wrapper = mountChip();

    expect(markerText(wrapper)).toBe('Every 5 min');
    // The count was the fallback wording, and it is gone the moment there is something
    // better to say — a reader does not need both.
    expect(wrapper.text()).not.toContain('showing 64');
    wrapper.unmount();
  });

  it('renders it VERBATIM — the prose is the server’s, in the reader’s language already', () => {
    // A cadence that exists in no client catalog: nothing here may look it up, prettify it,
    // or fall back because it does not recognise the words.
    const wrapper = mountChip({ cadence_label: 'Co 5 min, 09:00–17:00' });
    expect(markerText(wrapper)).toBe('Co 5 min, 09:00–17:00');

    // Switching the UI language must not touch it: it is data, not a key.
    setLocale('pl');
    expect(markerText(wrapper)).toBe('Co 5 min, 09:00–17:00');
    wrapper.unmount();
  });

  it('falls back to the count when the source has no cadence to state', () => {
    const wrapper = mountChip({ cadence_label: null });
    expect(markerText(wrapper)).toBe('Series — showing 64');
    wrapper.unmount();
  });

  /**
   * Property 2. The key is always on the wire today, but "always" is a statement about one
   * version of one server, and the fallback must not depend on it.
   */
  it('treats an ABSENT field exactly like a null one — not as two cases', () => {
    const withNull = mountChip({ cadence_label: null });

    const partial = occurrence();
    delete (partial as Partial<CalendarOccurrence>).cadence_label;
    const withAbsent = mount(OccurrenceChip, {
      props: { occurrence: partial, timezone: TIMEZONE, variant: 'agenda', shown: 64, folded: true },
    });

    expect(markerText(withAbsent)).toBe(markerText(withNull));
    expect(withAbsent.find('button').attributes('aria-label')).toBe(
      withNull.find('button').attributes('aria-label'),
    );

    withNull.unmount();
    withAbsent.unmount();
  });

  it('treats a BLANK cadence like no cadence — never a marker reading “Series —”', () => {
    const wrapper = mountChip({ cadence_label: '   ' });
    expect(markerText(wrapper)).toBe('Series — showing 64');
    wrapper.unmount();
  });

  it('shows no marker at all on an ordinary, unfolded row', () => {
    const wrapper = mountChip({ dense: false }, { folded: false, shown: 1 });
    expect(wrapper.find('.next-badge').exists()).toBe(false);
    wrapper.unmount();
  });
});

describe('OccurrenceChip — the cadence reaches every variant, including the narrow one', () => {
  /** Property 3: the grid trades the sentence for the width, and pays it back elsewhere. */
  it('keeps the bare number in a month cell, with the cadence on the marker’s tooltip', () => {
    const wrapper = mountChip({}, { variant: 'grid' });

    const marker = wrapper.find('.next-badge');
    expect(marker.text()).toBe('64');
    expect(marker.attributes('title')).toBe('Every 5 min');
    wrapper.unmount();
  });

  it('puts the cadence in the accessible label, in the grid as much as in the agenda', () => {
    for (const variant of ['grid', 'agenda', 'list'] as OccurrenceVariant[]) {
      const wrapper = mountChip({}, { variant });
      const label = wrapper.find('button').attributes('aria-label') ?? '';

      // The exact sentence, plus the "there is more of this" note it refines — a reader who
      // cannot see the chip gets the specific answer, not only the vague one.
      expect(label, `${variant} drops the cadence from its accessible label`).toContain('Every 5 min');
      expect(label).toContain('repeats more often than the grid can show');
      wrapper.unmount();
    }
  });

  it('says nothing about a cadence when there is none — the fallback wording instead', () => {
    for (const variant of ['grid', 'agenda', 'list'] as OccurrenceVariant[]) {
      const wrapper = mountChip({ cadence_label: null }, { variant });
      const label = wrapper.find('button').attributes('aria-label') ?? '';

      expect(label).toContain('repeats more often than the grid can show');
      expect(label).not.toContain('Every');
      // An aria-label REPLACES the content, so the marker's own words have to be in it —
      // including in the grid, where what is drawn is only the number.
      expect(label, `${variant} drops the marker's words from its accessible name`).toContain(
        'Series — showing 64',
      );
      wrapper.unmount();
    }
  });
});
