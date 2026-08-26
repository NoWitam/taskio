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
import Icon from '../../../ui/primitives/Icon.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { CALENDAR_COLORS, type CalendarOccurrence, type OccurrenceVariant } from '../types';

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

/**
 * A chip that dissolves into what it is drawn on.
 *
 * `colorTokens('primary').surface` is `bg-next-primary-subtle` — and so is the "today"
 * cell's tint, in BOTH themes. Not similar: the same token. The event source, the only one
 * this module owns, stamps `primary` on every occurrence it emits, so the collision landed
 * on the module's own data, on the square that is on screen every day. The near-misses of
 * the same family are `neutral`-on-`neutral` (`bg-next-muted` twice, badge on chip) and
 * `warning`-on-`primary` in dark, where the two subtle surfaces share a lightness and
 * differ only in hue.
 *
 * The remedy is one hairline, on the CHIP. Putting it on the cell would have fixed exactly
 * one pairing and left the others; putting it on the chip separates it from ANY surface,
 * including ones nothing has drawn yet.
 */
describe('OccurrenceChip — its own edge, because its surface is also somebody else’s', () => {
  it('carries an INSET hairline for every colour of the closed vocabulary', () => {
    for (const color of CALENDAR_COLORS) {
      const wrapper = mountChip({ color });
      const classes = wrapper.find('button').classes();

      expect(classes, `${color} lost its edge`).toContain('ring-1');
      // Inset, so the box does not grow and the 1-unit colour rail keeps its exact width.
      expect(classes).toContain('ring-inset');
      // THE APP'S BORDER TOKEN FOR AN INTERACTIVE CONTROL, BARE. An opacity modifier here
      // would be a light-mode-only fix for a both-themes defect: Tailwind v4 resolves a
      // themed colour plus a modifier at BUILD time and emits a flattened hex, and the dark
      // overrides in `next.css` are ordinary CSS rather than a second `@theme`, so the dark
      // value never reaches the baked rule. A bare token compiles to `var(--color-next-*)`
      // and is resolved by the browser, which is what makes it follow the theme swap.
      expect(classes).toContain('ring-next-input');
      expect(classes.some((c) => c.startsWith('ring-') && c.includes('/'))).toBe(false);
      wrapper.unmount();
    }
  });

  /**
   * The offset had to go WITH the inset ring, and this pins why: `ring-inset` is on the
   * base, so it applies to the focus ring too, and an offset drawn inside the chip is a
   * band of `--tw-ring-offset-color` (white) between the surface and the ring — invisible
   * in light, a bright line in dark. The focus ring itself is unharmed: 2px of `next-ring`,
   * inset, which is the treatment `DayCell` already uses one level up.
   */
  it('keeps a focus ring, and drops an offset that would now be drawn INSIDE it', () => {
    const wrapper = mountChip();
    const classes = wrapper.find('button').classes();

    expect(classes).toContain('focus-visible:ring-2');
    expect(classes).toContain('focus-visible:ring-next-ring');
    expect(classes).not.toContain('focus-visible:ring-offset-1');
    wrapper.unmount();
  });

  /** The badge-on-chip half of the same family — `neutral` on `neutral` is one token too. */
  it('gives the markers it stacks on itself the same hairline', () => {
    const wrapper = mountChip({ color: 'neutral', badge: { label: 'Open', color: 'neutral' } });
    for (const marker of [sample(wrapper), wrapper.find('.next-badge:last-of-type')]) {
      expect(marker.classes()).toContain('ring-1');
      expect(marker.classes()).toContain('ring-inset');
    }
    wrapper.unmount();
  });
});

/**
 * THE SECOND AXIS IN A MONTH CELL.
 *
 * For `task`, colour is PRIORITY and the badge is STATUS — independent axes — and rule 3
 * hides the badge in the grid. So "urgent, done" and "urgent, not done" drew as the same
 * red square; and because past days dim, an overdue urgent deadline drew FAINTER than a
 * future low-priority one. The dimming was never the defect (it says "this was",
 * truthfully) — the missing axis was.
 *
 * The dot appears exactly where the two axes disagree. Anywhere else it would restate the
 * surface it sits on, which is noise on the one surface with none to spare.
 */
describe('OccurrenceChip — the grid’s status dot', () => {
  const DONE = { label: 'Done', color: 'success' as const };

  function statusDot(wrapper: Chip) {
    return wrapper.find('[data-marker="status"]');
  }

  it('draws a dot when the badge’s colour says something the chip’s colour does not', () => {
    // Urgent (danger) and finished (success): one square, two facts, previously one colour.
    const wrapper = mountChip(
      { source: 'task', color: 'danger', badge: DONE, dense: false, recurring: false },
      { variant: 'grid', folded: false, shown: 1 },
    );

    expect(statusDot(wrapper).exists()).toBe(true);
    // Solid token, so it reads against the chip's subtle surface without shouting.
    expect(statusDot(wrapper).classes()).toContain('bg-next-success');
    // The words are not lost: the chip's own name still carries them, which is why the dot
    // is hidden from the accessibility tree rather than announced twice.
    expect(label(wrapper)).toContain('Done');
    expect(statusDot(wrapper).attributes('aria-hidden')).toBe('true');
    wrapper.unmount();
  });

  it('draws NOTHING when the badge merely restates the chip’s colour', () => {
    const wrapper = mountChip(
      { source: 'task', color: 'success', badge: DONE, dense: false, recurring: false },
      { variant: 'grid', folded: false, shown: 1 },
    );
    expect(statusDot(wrapper).exists()).toBe(false);
    wrapper.unmount();
  });

  /** An unknown colour normalises to `neutral` on BOTH sides — a gap, not a difference. */
  it('treats an unrecognised badge colour like the neutral it renders as', () => {
    const wrapper = mountChip(
      {
        source: 'task',
        color: 'neutral',
        badge: { label: 'Backlog', color: 'chartreuse' as unknown as 'neutral' },
        dense: false,
        recurring: false,
      },
      { variant: 'grid', folded: false, shown: 1 },
    );
    expect(statusDot(wrapper).exists()).toBe(false);
    wrapper.unmount();
  });

  it('leaves the roomy variants to the badge itself — the dot is the grid’s compromise', () => {
    for (const variant of ['agenda', 'list'] as OccurrenceVariant[]) {
      const wrapper = mountChip(
        { source: 'task', color: 'danger', badge: DONE, dense: false, recurring: false },
        { variant, folded: false, shown: 1 },
      );
      expect(statusDot(wrapper).exists(), `${variant} fell back to a dot`).toBe(false);
      expect(wrapper.text()).toContain('Done');
      wrapper.unmount();
    }
  });

  it('says nothing at all when the occurrence carries no badge', () => {
    const wrapper = mountChip({ badge: null }, { variant: 'grid' });
    expect(statusDot(wrapper).exists()).toBe(false);
    wrapper.unmount();
  });
});

/**
 * THE AGENDA IS THE PHONE'S ONLY SURFACE (§17), and on one line it lost the title first.
 *
 * The cadence badge carries unbounded server prose ("Every year, on the last day of
 * August"), took its full width before the flexible title took any, and the row came out
 * under the 44px a finger needs. Two lines below `next-sm`, a bounded badge, and a row that
 * is a touch target before it is a line of text.
 */
describe('OccurrenceChip — the agenda row on a narrow screen', () => {
  it('breaks into two lines below next-sm and stays one line above it', () => {
    const wrapper = mountChip({}, { variant: 'agenda' });
    // The body is a column that becomes a row at the breakpoint. BOTH spellings have to be
    // there: the first is what saves the title on a phone, the second is what keeps a
    // desktop agenda from growing to two lines it does not need.
    const body = wrapper.find('[data-part="body"]');
    expect(body.classes()).toContain('flex-col');
    expect(body.classes()).toContain('next-sm:flex-row');
    wrapper.unmount();
  });

  it('is a touch target before it is a line of text — but only where it owns the width', () => {
    const agenda = mountChip({}, { variant: 'agenda' });
    expect(agenda.find('button').classes()).toContain('min-h-[2.75rem]');
    agenda.unmount();

    // A month cell fits three of these; a 44px row there would fit one.
    const grid = mountChip({}, { variant: 'grid' });
    expect(grid.find('button').classes()).not.toContain('min-h-[2.75rem]');
    grid.unmount();
  });

  it('bounds the cadence badge, and keeps the whole sentence reachable', () => {
    const long = 'Every year, on the last day of August';
    const wrapper = mountChip({ cadence_label: long }, { variant: 'agenda', folded: false, shown: 1 });
    const marker = series(wrapper);

    // Truncation is the design system's own affordance for exactly this (`Badge truncate`).
    expect(marker.classes()).toContain('max-w-[12ch]');
    // Nothing is lost: the tooltip and the accessible name still carry all of it.
    expect(marker.attributes('title')).toBe(long);
    expect(label(wrapper)).toContain(long);
    wrapper.unmount();
  });

  it('never lets the marker line exist when there are no markers to put on it', () => {
    // An empty second line is a gap under every ordinary row.
    const wrapper = mountChip(
      { recurring: false, cadence_label: null, dense: false, badge: null },
      { variant: 'agenda', folded: false, shown: 1 },
    );
    expect(series(wrapper).exists()).toBe(false);
    expect(sample(wrapper).exists()).toBe(false);
    expect(wrapper.find('[data-part="markers"]').exists()).toBe(false);
    wrapper.unmount();
  });
});

describe('OccurrenceChip — the trailing glyph names a DIRECTION, never a verb', () => {
  function trailing(wrapper: Chip): string {
    const icons = wrapper.findAllComponents(Icon);
    return icons[icons.length - 1].props('name');
  }

  /**
   * A `pencil` stood in the `editable` slot and promised an edit. The click opens a READ
   * surface, and the pencil that genuinely edits lives inside it, two clicks further in.
   * One glyph meaning two different things on one screen is how a reader learns to
   * distrust it.
   */
  it('points INTO the screen for something that opens here', () => {
    const wrapper = mountChip({ editable: true }, { variant: 'agenda', folded: false, shown: 1 });
    expect(trailing(wrapper)).toBe('chevron-right');
    expect(trailing(wrapper)).not.toBe('pencil');
    wrapper.unmount();
  });

  it('points OUT of it for something that opens elsewhere', () => {
    const wrapper = mountChip({ editable: false }, { variant: 'agenda', folded: false, shown: 1 });
    expect(trailing(wrapper)).toBe('external-link');
    wrapper.unmount();
  });
});

/**
 * A repeating subject is allowed to have no cadence sentence — a schedule in fixed-times
 * mode is exactly that — and the client may not compose one. What it may not do EITHER is
 * render the badge anyway: `Badge` lays out `[icon][gap][label]`, so an empty label leaves
 * a pill with the glyph pushed off its own centre by a gap holding nothing.
 */
describe('OccurrenceChip — a series with nothing to say about its cadence', () => {
  it('shows the bare glyph rather than an empty pill, in the roomy variants too', () => {
    for (const variant of ['agenda', 'list'] as OccurrenceVariant[]) {
      const wrapper = mountChip(
        { cadence_label: null, dense: false },
        { variant, folded: false, shown: 1 },
      );
      const marker = series(wrapper);

      expect(marker.exists(), `${variant} lost the series marker`).toBe(true);
      expect(marker.text()).toBe('');
      expect(marker.classes(), `${variant} still renders a pill`).not.toContain('next-badge');
      // Still reachable with a mouse, and still named in the chip's own label.
      expect(marker.attributes('title')).toBe('Occurrence of a series');
      expect(label(wrapper)).toContain('Occurrence of a series');
      wrapper.unmount();
    }
  });

  it('keeps the badge when the server DID send a sentence', () => {
    const wrapper = mountChip({ dense: false }, { variant: 'agenda', folded: false, shown: 1 });
    expect(series(wrapper).classes()).toContain('next-badge');
    expect(series(wrapper).text()).toBe('Every 5 min');
    wrapper.unmount();
  });
});
