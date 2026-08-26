// recurrencePresets.spec — the pure half of the repeat control, where the expensive mistakes
// live.
//
// Everything asserted here fails SILENTLY in production if it slips: the payload still saves,
// or it 422s on a field the user does not connect with the control they touched.
//
//   • THE ANCHOR ALWAYS SATISFIES ITS OWN RULE. The server refuses otherwise, ON THE START
//     FIELD (`anchor_not_an_occurrence`). The sweep below is what turns that refusal from
//     "unlikely" into "unreachable from this UI".
//   • A RULE THE CONTROL CANNOT PRODUCE IS NEVER REWRITTEN INTO ONE IT CAN. `presetOf` says
//     `null` and the descriptor is echoed back byte for byte — otherwise editing the TITLE of
//     a series created through the API would quietly reshape its cadence.
//   • "DOES NOT REPEAT" IS THE ABSENCE OF THE KEY, NOT AN EMPTY BLOCK. `{day: null}` is
//     `filled()` server-side and compiles to "every day, forever" — a series nobody asked for.
//   • `until` AND `count` NEVER TRAVEL TOGETHER (422 `end_is_one_thing`), and nothing here
//     keeps a counter: a count is a way of saying a date, and only the date ever comes back.
import { describe, it, expect } from 'vitest';

import {
  descriptorOf,
  emptyRecurrenceState,
  presetLabel,
  presetOf,
  presetsFor,
  recurrenceStateFrom,
  recurrenceStateToWire,
  remapPreset,
  type RecurrencePreset,
} from '../recurrencePresets';
import { daysInMonth, fromIsoDate, toIsoDate } from '../../../ui/forms/date/dateCore';
import { translate } from '../../../app/i18n';
import type { CalendarRecurrenceRule } from '../types';

/** The real catalogue, in English — the labels are part of what this file guards. */
const t = (key: string, def?: string, params?: Record<string, string | number>): string =>
  translate(key, def, params);

function labelsFor(day: string): string[] {
  return presetsFor(day).map((preset) => presetLabel(preset, t, 'en'));
}

describe('presetsFor — the presets a date can legally anchor', () => {
  // 25 August 2026 is a Tuesday and the FOURTH Tuesday of its month — the worked example the
  // UX spec uses, chosen because it exercises the weekday, day-of-month and ordinal shapes at
  // once without tripping the fifth-weekday swap.
  it('offers the four everyday shapes for a plain mid-month Tuesday', () => {
    const date = fromIsoDate('2026-08-25');
    expect(date?.getDay()).toBe(2); // Tuesday, in the 0 = Sunday convention

    expect(labelsFor('2026-08-25')).toEqual([
      'Every day',
      'Every Tuesday',
      'Monthly, on day 25',
      'Monthly: the fourth Tuesday',
      'Every year, on August 25',
    ]);
  });

  /**
   * "Monthly on day 31" is a true rule that simply does not fire in eleven months of the year.
   * It is offered — with a permanent note — rather than hidden, and the honest alternative
   * stands beside it, because for a date that IS its month's last both readings are plausible
   * and only the user knows which they meant.
   */
  it('offers the last-day rule beside "day 31", and flags the months it skips', () => {
    const presets = presetsFor('2026-03-31');

    expect(presets.map((preset) => preset.id)).toContain('monthlyLastDay');
    const byDay = presets.find((preset) => preset.id === 'monthlyDay');
    expect(byDay?.skipsShortMonths).toBe(true);
    expect(t('calendar.recurrence.shortMonthsNote', '', { day: 31 })).toBe(
      'Months that have no day 31 will be skipped.',
    );
  });

  it('does NOT offer the last-day rule for a date that is not its month’s last', () => {
    // Anchoring "every month, on the last day" on the 30th of a 31-day month is a guaranteed
    // 422 on the START field. Not offering it is what makes that unreachable.
    expect(presetsFor('2026-08-30').map((preset) => preset.id)).not.toContain('monthlyLastDay');
  });

  /**
   * A SWAP, never an extra option. "Monthly on the 5th Tuesday" looks monthly and fires in
   * roughly four months a year; somebody pointing at the last Tuesday of a month almost
   * certainly meant the LAST one, and that rule is inside the accepted subset.
   */
  it('replaces the fifth-weekday rule with "the last <weekday>", never offering both', () => {
    // 2026-12-29 is the fifth Tuesday of December 2026.
    const ids = presetsFor('2026-12-29').map((preset) => preset.id);
    expect(ids).toContain('monthlyLastWeekday');
    expect(ids).not.toContain('monthlyNth');
    expect(labelsFor('2026-12-29')).toContain('Monthly: the last Tuesday');
  });

  it('offers nothing for a day it cannot read, rather than guessing one', () => {
    expect(presetsFor(null)).toEqual([]);
    expect(presetsFor('not-a-day')).toEqual([]);
  });
});

/**
 * THE INVARIANT THE WHOLE CONTROL RESTS ON. Every preset offered for a day must have that day
 * as one of its own occurrences — checked structurally here, against the same arithmetic the
 * shared engine uses, for every single day of a year.
 */
describe('presetsFor — every offered preset is satisfied by its own anchor', () => {
  function anchorSatisfies(preset: RecurrencePreset, day: string): boolean {
    const date = fromIsoDate(day);
    if (!date) return false;
    const dayOfMonth = date.getDate();
    const length = daysInMonth(date.getFullYear(), date.getMonth());
    switch (preset.id) {
      case 'daily':
        return true;
      case 'weekly':
        return preset.weekday === date.getDay();
      case 'monthlyDay':
        return preset.dayOfMonth === dayOfMonth;
      case 'monthlyNth':
        return (
          preset.weekday === date.getDay() &&
          preset.ordinal === Math.floor((dayOfMonth - 1) / 7) + 1
        );
      case 'monthlyLastDay':
        return dayOfMonth === length;
      case 'monthlyLastWeekday':
        return preset.weekday === date.getDay() && dayOfMonth + 7 > length;
      case 'yearly':
        return preset.dayOfMonth === dayOfMonth && preset.month === date.getMonth() + 1;
    }
  }

  it('holds for every day of 2026 — including the month ends and the fifth weekdays', () => {
    const offenders: string[] = [];
    for (let cursor = new Date(2026, 0, 1); cursor.getFullYear() === 2026; cursor.setDate(cursor.getDate() + 1)) {
      const day = toIsoDate(cursor);
      for (const preset of presetsFor(day)) {
        if (!anchorSatisfies(preset, day)) offenders.push(`${day} → ${preset.id}`);
      }
    }
    expect(offenders).toEqual([]);
  });
});

describe('remapPreset — the choice follows the date, visibly', () => {
  /**
   * Without this, picking "every Tuesday" and then moving the date to a Wednesday is refused
   * on the START field — a control the user does not associate with the rule at all.
   */
  it('moves a weekly rule onto the new weekday', () => {
    const wednesday = remapPreset('weekly', '2026-08-26');
    expect(wednesday?.id).toBe('weekly');
    expect(descriptorOf(wednesday!).day).toEqual({ mode: 'weekdays', weekdays: [3] });
    expect(presetLabel(wednesday!, t, 'en')).toBe('Every Wednesday');
  });

  it('moves a day-of-month rule onto the new day, and a yearly one onto the new date', () => {
    expect(descriptorOf(remapPreset('monthlyDay', '2026-09-03')!).day).toEqual({
      mode: 'month_days',
      days: [3],
    });
    expect(descriptorOf(remapPreset('yearly', '2026-09-03')!)).toEqual({
      day: { mode: 'month_days', days: [3] },
      month: { mode: 'months', months: [9] },
    });
  });

  /** The two month-shaped pairs trade places rather than vanishing. */
  it('turns an nth-weekday rule into a last-weekday one when the new date is a fifth', () => {
    expect(remapPreset('monthlyNth', '2026-12-29')?.id).toBe('monthlyLastWeekday');
    expect(remapPreset('monthlyLastWeekday', '2026-08-25')?.id).toBe('monthlyNth');
  });

  it('turns "the last day" into "day N" when the new date is not a month’s last', () => {
    const remapped = remapPreset('monthlyLastDay', '2026-08-14');
    expect(remapped?.id).toBe('monthlyDay');
    expect(remapped?.dayOfMonth).toBe(14);
  });

  it('leaves a rule it cannot re-derive alone rather than dropping it', () => {
    expect(remapPreset('weekly', null)).toBeNull();
  });
});

describe('presetOf — recognising a stored rule, and refusing to guess', () => {
  function rule(over: Partial<CalendarRecurrenceRule> = {}): CalendarRecurrenceRule {
    return { day: null, month: null, exclusions: null, until: null, ...over };
  }

  it('recognises each preset it can produce for that day', () => {
    expect(presetOf(rule({ day: { mode: 'weekdays', weekdays: [2] } }), '2026-08-25')?.id).toBe('weekly');
    expect(presetOf(rule({ day: { mode: 'month_days', days: [25] } }), '2026-08-25')?.id).toBe('monthlyDay');
    expect(
      presetOf(
        rule({
          day: { mode: 'month_days', days: [25] },
          month: { mode: 'months', months: [8] },
        }),
        '2026-08-25',
      )?.id,
    ).toBe('yearly');
  });

  it('reads an ABSENT axis as "every day" / "every month", the way the server does', () => {
    expect(presetOf(rule(), '2026-08-25')?.id).toBe('daily');
    expect(presetOf(rule({ day: { mode: 'every_day' } }), '2026-08-25')?.id).toBe('daily');
  });

  it('compares lists by VALUE — a stringified weekday is the same rule', () => {
    const stringy = rule({ day: { mode: 'weekdays', weekdays: ['2' as unknown as number] } });
    expect(presetOf(stringy, '2026-08-25')?.id).toBe('weekly');
  });

  /**
   * The API accepts several weekdays; this control offers one. Saying `null` is what keeps a
   * title edit from silently narrowing "Mondays and Wednesdays" to "Mondays".
   */
  it('says null for a rule outside the control’s vocabulary', () => {
    expect(presetOf(rule({ day: { mode: 'weekdays', weekdays: [1, 3] } }), '2026-08-25')).toBeNull();
    expect(presetOf(rule({ day: { mode: 'month_days', days: [1, 15] } }), '2026-08-25')).toBeNull();
  });
});

describe('recurrenceStateFrom / recurrenceStateToWire — the round trip', () => {
  function rule(over: Partial<CalendarRecurrenceRule> = {}): CalendarRecurrenceRule {
    return { day: null, month: null, exclusions: null, until: null, ...over };
  }

  it('emits NOTHING for "does not repeat" — the key must be absent, not empty', () => {
    expect(recurrenceStateToWire(emptyRecurrenceState(), '2026-08-25')).toBeNull();
  });

  it('echoes a rule outside the vocabulary byte for byte', () => {
    const stored = rule({
      day: { mode: 'weekdays', weekdays: [1, 3] },
      exclusions: { dates: ['2026-09-02'] },
      until: '2026-12-31',
    });
    const state = recurrenceStateFrom(stored, '2026-08-25');
    expect(state.selection).toBe('other');

    const wire = recurrenceStateToWire(state, '2026-08-25');
    expect(wire?.day).toEqual({ mode: 'weekdays', weekdays: [1, 3] });
    expect(wire?.month).toBeNull();
    // Carried, never authored: dropping it would resurrect a day somebody deleted.
    expect(wire?.exclusions).toEqual({ dates: ['2026-09-02'] });
    expect(wire?.until).toBe('2026-12-31');
  });

  it('never brings a count back — a stored series can only end on a DATE', () => {
    const state = recurrenceStateFrom(rule({ day: { mode: 'every_day' }, until: '2026-09-30' }), '2026-08-25');
    expect(state.endMode).toBe('until');
    expect(state.count).toBeNull();
  });

  it('sends `count` alone, and `until` alone — never both', () => {
    const base = recurrenceStateFrom(rule({ day: { mode: 'every_day' } }), '2026-08-25');

    const counted = recurrenceStateToWire({ ...base, endMode: 'count', count: 10 }, '2026-08-25');
    expect(counted?.count).toBe(10);
    expect(counted).not.toHaveProperty('until');

    const dated = recurrenceStateToWire({ ...base, endMode: 'until', until: '2026-10-01' }, '2026-08-25');
    expect(dated?.until).toBe('2026-10-01');
    expect(dated).not.toHaveProperty('count');
  });

  it('clears a previous end explicitly when the user chooses "never"', () => {
    const state = recurrenceStateFrom(rule({ day: { mode: 'every_day' }, until: '2026-09-30' }), '2026-08-25');
    const wire = recurrenceStateToWire({ ...state, endMode: 'never' }, '2026-08-25');
    // Explicit null rather than omission: a whole-event write rebuilds the descriptor, so the
    // null is what actually removes the end.
    expect(wire?.until).toBeNull();
  });

  it('re-derives the axes for the day the FORM holds, not the one it was read with', () => {
    const state = recurrenceStateFrom(rule({ day: { mode: 'weekdays', weekdays: [2] } }), '2026-08-25');
    expect(state.selection).toBe('weekly');

    // The user moved the start to a Wednesday; the rule follows rather than being refused.
    const wire = recurrenceStateToWire(state, '2026-08-26');
    expect(wire?.day).toEqual({ mode: 'weekdays', weekdays: [3] });
  });
});
