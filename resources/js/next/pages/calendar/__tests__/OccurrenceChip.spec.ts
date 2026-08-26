// @vitest-environment happy-dom
// OccurrenceChip.spec — TWO MARKERS, TWO FACTS, and the one field each of them is allowed to
// read.
//
//   SERIES (`repeat`)  — a fact about the SUBJECT: this square is one of many. Permanent.
//   SAMPLE (`layers`)  — a fact about the ANSWER: the rest of this item is not in this window.
//                        Incidental.
//
// One glyph used to carry both, which taught a reader to ignore it: it appeared on things that
// were fine and on things that were missing data, identically. Splitting them is the whole
// point of this file, and every assertion below guards a way of quietly re-merging them.
//
// FIVE PROPERTIES, each of which fails silently if it slips:
//
//   1. THE SERIES TEST IS `recurring`, NEVER `cadence_label !== null`. The prose is sufficient
//      evidence of a series but NOT necessary — a schedule in fixed-times mode repeats and
//      carries `null` — so branching on the prose calls every one of those squares a one-off,
//      silently, and would go on doing it for any future source shaped the same way.
//
//   2. THE PROSE IS RENDERED VERBATIM AND NEVER COMPOSED HERE. Same doctrine as `badge.label`
//      and the source names: the server sends finished text in the reader's language. When it
//      sends none, the marker says NOTHING — a client-side sentence about a cadence is exactly
//      what makes "a new source needs no frontend change" stop being true.
//
//   3. A GLYPH WITH NO ACCESSIBLE NAME IS NOT ACCEPTABLE. So when there is no prose, the chip's
//      own label still says what the marker MEANS ("occurrence of a series") — which is a name
//      for the glyph, not a sentence about how often anything happens.
//
//   4. ABSENT AND `null` ARE ONE CASE. The keys are always on the wire today, but "always" is a
//      statement about one version of one server.
//
//   5. THE GRID DRAWS AT MOST ONE MARKER, AND THE SAMPLE WINS. An ~11rem cell has room for the
//      title or for a marker, not both — and "you are not seeing everything" is more urgent
//      than "this recurs". Neither fact is lost: both are in the accessible label.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';

import OccurrenceChip from '../OccurrenceChip.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { CalendarOccurrence, OccurrenceVariant } from '../types';

const TIMEZONE = 'Europe/Warsaw';

/** A schedule occurrence — repeating, densified, and carrying a cadence sentence. */
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
    recurring: true,
    occurrence_date: null,
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

type Chip = ReturnType<typeof mountChip>;

function series(wrapper: Chip) {
  return wrapper.find('[data-marker="series"]');
}
function sample(wrapper: Chip) {
  return wrapper.find('[data-marker="sample"]');
}
function label(wrapper: Chip): string {
  return wrapper.find('button').attributes('aria-label') ?? '';
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

describe('OccurrenceChip — the SERIES marker reads `recurring`, not the prose', () => {
  /** Property 1, stated on the exact shape that makes the inference wrong. */
  it('marks a series that has NO cadence sentence — a fixed-times schedule still repeats', () => {
    const wrapper = mountChip({ cadence_label: null, dense: false }, { folded: false, shown: 1 });

    expect(series(wrapper).exists()).toBe(true);
    // No prose invented to fill the badge — the glyph stands alone.
    expect(series(wrapper).text()).toBe('');
    wrapper.unmount();
  });

  it('leaves a one-off square unmarked, even when it is densified', () => {
    // `dense` says "there is more of this than you see" and says NOTHING about repetition.
    const wrapper = mountChip({ recurring: false, cadence_label: null });

    expect(series(wrapper).exists()).toBe(false);
    expect(sample(wrapper).exists()).toBe(true);
    wrapper.unmount();
  });

  it('renders the server’s prose VERBATIM — it is data, not a key', () => {
    // A cadence that exists in no client catalog: nothing here may look it up, prettify it,
    // or fall back because it does not recognise the words.
    const wrapper = mountChip({ cadence_label: 'Co 5 min, 09:00–17:00' });
    expect(series(wrapper).text()).toBe('Co 5 min, 09:00–17:00');

    // Switching the UI language must not touch it.
    setLocale('pl');
    expect(series(wrapper).text()).toBe('Co 5 min, 09:00–17:00');
    wrapper.unmount();
  });

  it('treats a BLANK cadence like no cadence — never a marker trailing an em dash', () => {
    const wrapper = mountChip({ cadence_label: '   ' });
    expect(series(wrapper).text()).toBe('');
    wrapper.unmount();
  });

  /** Property 4. */
  it('treats ABSENT keys exactly like null ones — not as two cases', () => {
    const withNull = mountChip({ cadence_label: null, recurring: false });

    const partial = occurrence();
    delete (partial as Partial<CalendarOccurrence>).cadence_label;
    delete (partial as Partial<CalendarOccurrence>).recurring;
    const withAbsent = mount(OccurrenceChip, {
      props: { occurrence: partial, timezone: TIMEZONE, variant: 'agenda', shown: 64, folded: true },
    });

    expect(series(withAbsent).exists()).toBe(series(withNull).exists());
    expect(label(withAbsent)).toBe(label(withNull));

    withNull.unmount();
    withAbsent.unmount();
  });

  it('shows no marker at all on an ordinary, one-off, unfolded row', () => {
    const wrapper = mountChip(
      { recurring: false, cadence_label: null, dense: false },
      { folded: false, shown: 1 },
    );
    expect(series(wrapper).exists()).toBe(false);
    expect(sample(wrapper).exists()).toBe(false);
    wrapper.unmount();
  });
});

describe('OccurrenceChip — the SAMPLE marker counts only when the count says something', () => {
  it('shows the number when the row stands in for more than one', () => {
    const wrapper = mountChip({}, { shown: 64 });
    expect(sample(wrapper).text()).toBe('64');
    wrapper.unmount();
  });

  /**
   * A folded EVENT series is always exactly one row per day — a calendar series has one hour
   * by construction — so "sample: 1" is the common case, not the exception, and a number
   * there would be a sentence about nothing.
   */
  it('shows NO number when it stands in for exactly one', () => {
    const wrapper = mountChip({ source: 'event' }, { shown: 1 });
    expect(sample(wrapper).exists()).toBe(true);
    expect(sample(wrapper).text()).toBe('');
    wrapper.unmount();
  });
});

describe('OccurrenceChip — the grid draws ONE marker, and the sample wins', () => {
  /** Property 5. */
  it('drops the series glyph when both facts are true in a month cell', () => {
    const wrapper = mountChip({}, { variant: 'grid' });

    expect(sample(wrapper).exists()).toBe(true);
    expect(series(wrapper).exists()).toBe(false);
    // Neither fact is lost — the label carries both.
    expect(label(wrapper)).toContain('Every 5 min');
    expect(label(wrapper)).toContain('repeats more often than the grid can show');
    wrapper.unmount();
  });

  it('keeps the series glyph in a month cell when there is no sample to draw', () => {
    const wrapper = mountChip({ dense: false }, { variant: 'grid', folded: false, shown: 1 });

    expect(series(wrapper).exists()).toBe(true);
    expect(sample(wrapper).exists()).toBe(false);
    // The cell has no room for the sentence, so it goes on the marker's tooltip.
    expect(series(wrapper).attributes('title')).toBe('Every 5 min');
    wrapper.unmount();
  });

  it('draws BOTH in the roomy variants, where there is width for two facts', () => {
    for (const variant of ['agenda', 'list'] as OccurrenceVariant[]) {
      const wrapper = mountChip({}, { variant });
      expect(series(wrapper).exists(), `${variant} lost the series marker`).toBe(true);
      expect(sample(wrapper).exists(), `${variant} lost the sample marker`).toBe(true);
      wrapper.unmount();
    }
  });
});

describe('OccurrenceChip — the accessible label carries what the markers mean', () => {
  it('puts the cadence in the label, in the grid as much as in the agenda', () => {
    for (const variant of ['grid', 'agenda', 'list'] as OccurrenceVariant[]) {
      const wrapper = mountChip({}, { variant });

      expect(label(wrapper), `${variant} drops the cadence`).toContain('Every 5 min');
      expect(label(wrapper)).toContain('repeats more often than the grid can show');
      wrapper.unmount();
    }
  });

  /**
   * Property 3. The fallback NAMES THE GLYPH; it does not describe a cadence. Without it a
   * repeating square with no prose would carry a marker nobody using a screen reader could
   * account for — and composing "repeats weekly" instead would be the client inventing prose.
   */
  it('names the series marker when the server sent no sentence for it', () => {
    for (const variant of ['grid', 'agenda', 'list'] as OccurrenceVariant[]) {
      const wrapper = mountChip({ cadence_label: null, dense: false }, { variant, folded: false, shown: 1 });

      expect(label(wrapper), `${variant} left the glyph unnamed`).toContain('Occurrence of a series');
      // No cadence invented anywhere in the label either.
      expect(label(wrapper)).not.toContain('Every');
      wrapper.unmount();
    }
  });

  it('says the sample sentence before the direction, and keeps the visible number in the name', () => {
    const wrapper = mountChip({}, { variant: 'grid', shown: 64 });
    const text = label(wrapper);

    // The visible text has to be IN the accessible name, or a voice-control user reading "64"
    // finds nothing to activate.
    expect(text).toContain('Showing 64 of this item');
    expect(text.indexOf('Showing 64')).toBeLessThan(text.indexOf('Opens in another module'));
    wrapper.unmount();
  });
});
