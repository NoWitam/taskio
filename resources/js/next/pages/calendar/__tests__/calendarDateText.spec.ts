// calendarDateText.spec — the one date form this module may put after a preposition.
//
// The defect this closes was visible in the module's central dialog and in the banner that
// stands over every scoped edit:
//
//     "Edytujesz wystąpienia od środa, 26 sierpnia 2026"
//
// `Intl` returns weekdays in the NOMINATIVE and offers no option to decline them; Polish
// wants the genitive after "od". Composing one from a seven-entry table of genitives is the
// same mistake the recurrence copy already refuses to make (`recurrence.weeklyDays` is
// written out one key per weekday precisely because a declining language will not accept a
// name dropped into a slot).
//
// So the preposition form drops the weekday. What remains — day-of-month plus month name —
// is genitive in Polish BY CONSTRUCTION, which is why the same pattern already carries the
// yearly cadence label ("Co roku, 25 sierpnia").
//
// Both directions matter. Where the date is the SUBJECT of its sentence, or a value after a
// colon, the nominative with its weekday is correct and must NOT be replaced.
import { describe, it, expect } from 'vitest';

import { prepositionalDateLabel } from '../calendarDateText';
import { fullDateLabel } from '../../../ui/forms/date/dateCore';

// A Wednesday — the weekday whose Polish genitive ("środy") differs from its nominative by
// more than a suffix a naive formatter could stumble into by luck.
const WEDNESDAY = new Date(2026, 7, 26);

describe('prepositionalDateLabel', () => {
  it('gives Polish a form that is already correct after "od"', () => {
    // The nominative the subject form supplies, stated so the contrast is not folklore.
    expect(fullDateLabel(WEDNESDAY, 'pl')).toContain('środa');

    const label = prepositionalDateLabel(WEDNESDAY, 'pl');
    expect(label).not.toContain('środa');
    expect(`Edytujesz wystąpienia od ${label}`).toBe('Edytujesz wystąpienia od 26 sierpnia 2026');
  });

  it('reads naturally in English too — this is not a Polish-only branch', () => {
    expect(prepositionalDateLabel(WEDNESDAY, 'en')).toBe('August 26, 2026');
  });

  it('keeps the YEAR, because a series outlives the month on screen', () => {
    // Dropping the weekday is a grammar fix; dropping the year would make "from 26 August"
    // ambiguous on exactly the series this dialog exists for.
    expect(prepositionalDateLabel(WEDNESDAY, 'pl')).toContain('2026');
    expect(prepositionalDateLabel(WEDNESDAY, 'en')).toContain('2026');
  });

  it('is the shorter string — which is what stops the confirm button overflowing', () => {
    // `SeriesScopeModal` renders in a `size="sm"` panel and `Button` never wraps its label,
    // so "Usuń wystąpienia od wtorek, 2 września 2026" simply overflowed, on a desktop.
    for (const locale of ['pl', 'en']) {
      expect(prepositionalDateLabel(WEDNESDAY, locale).length).toBeLessThan(
        fullDateLabel(WEDNESDAY, locale).length,
      );
    }
  });

  it('does not silently agree with the subject form in ANY supported locale', () => {
    // If these two ever coincide, one of the two call sites is wrong and nothing else here
    // would notice.
    for (const locale of ['pl', 'en']) {
      expect(prepositionalDateLabel(WEDNESDAY, locale)).not.toBe(fullDateLabel(WEDNESDAY, locale));
    }
  });
});
