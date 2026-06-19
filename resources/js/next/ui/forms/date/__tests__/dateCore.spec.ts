// dateCore.spec.ts — the timezone-safe date/time engine contract. Pure functions,
// no DOM required (runs under the default node env). The headline guarantee is
// "no UTC drift": every calendar-day serialization must use the LOCAL y/m/d, never
// toISOString().
import { describe, it, expect } from 'vitest';
import {
  pad2,
  makeDate,
  addDays,
  addMonths,
  addYears,
  daysInMonth,
  startOfMonth,
  endOfMonth,
  isSameDay,
  isSameMonth,
  compareDay,
  isWithinRange,
  clampDate,
  isDateDisabled,
  weekdayOffset,
  buildMonthGrid,
  buildMonthWeeks,
  weekdayNames,
  monthNames,
  toIsoDate,
  fromIsoDate,
  toIsoTime,
  fromIsoTime,
  toIsoDateTime,
  fromIsoDateTime,
  parseDateInput,
  formatDate,
  maskDateInput,
  parseTimeInput,
  formatTime,
  to12Hour,
  from12Hour,
  snapMinuteToStep,
} from '../dateCore';

describe('dateCore · parseDateInput', () => {
  it('parses a full dd.mm.yyyy with dots', () => {
    const d = parseDateInput('12.06.2026', 'dd.mm.yyyy')!;
    expect(d.getFullYear()).toBe(2026);
    expect(d.getMonth()).toBe(5); // June (0-based)
    expect(d.getDate()).toBe(12);
  });

  it('accepts alternate separators and bare digits (separators ignored)', () => {
    for (const input of ['12062026', '12/06/2026', '12-06-2026', '12 06 2026']) {
      const d = parseDateInput(input, 'dd.mm.yyyy')!;
      expect(toIsoDate(d)).toBe('2026-06-12');
    }
  });

  it('honors the token ORDER of the format (mm/dd/yyyy)', () => {
    const d = parseDateInput('06/12/2026', 'mm/dd/yyyy')!;
    expect(toIsoDate(d)).toBe('2026-06-12');
  });

  it('returns null for partial input (never guesses a year)', () => {
    expect(parseDateInput('12.06', 'dd.mm.yyyy')).toBeNull();
    expect(parseDateInput('1206', 'dd.mm.yyyy')).toBeNull();
    expect(parseDateInput('', 'dd.mm.yyyy')).toBeNull();
  });

  it('returns null for impossible calendar days', () => {
    expect(parseDateInput('31.02.2026', 'dd.mm.yyyy')).toBeNull(); // Feb 31
    expect(parseDateInput('00.06.2026', 'dd.mm.yyyy')).toBeNull(); // day 0
    expect(parseDateInput('12.13.2026', 'dd.mm.yyyy')).toBeNull(); // month 13
  });

  it('maps a 2-digit year to 2000-2099', () => {
    const d = parseDateInput('12.06.26', 'dd.mm.yy')!;
    expect(d.getFullYear()).toBe(2026);
  });

  it('needs 8 digits for a 4-digit-year format, 6 for a 2-digit one', () => {
    expect(parseDateInput('120626', 'dd.mm.yyyy')).toBeNull(); // too short for yyyy
    expect(parseDateInput('120626', 'dd.mm.yy')).not.toBeNull();
  });
});

describe('dateCore · parseTimeInput', () => {
  it('parses bare HHmm (0930)', () => {
    expect(parseTimeInput('0930')).toEqual({ hours: 9, minutes: 30, seconds: 0 });
  });

  it('parses the colon form 9:5', () => {
    expect(parseTimeInput('9:5')).toEqual({ hours: 9, minutes: 5, seconds: 0 });
  });

  it('rejects out-of-range hours/minutes (25:00)', () => {
    expect(parseTimeInput('25:00')).toBeNull();
    expect(parseTimeInput('12:99')).toBeNull();
  });

  it('supports seconds when requested', () => {
    expect(parseTimeInput('093015', true)).toEqual({ hours: 9, minutes: 30, seconds: 15 });
    expect(parseTimeInput('09:30:15')).toEqual({ hours: 9, minutes: 30, seconds: 15 });
    expect(parseTimeInput('09:30:99')).toBeNull();
  });

  it('returns null for empty input', () => {
    expect(parseTimeInput('')).toBeNull();
  });
});

describe('dateCore · 12h <-> 24h helpers', () => {
  it('formats hour12 AM/PM correctly across the boundaries', () => {
    expect(formatTime({ hours: 0, minutes: 0, seconds: 0 }, { hour12: true })).toBe('12:00 AM');
    expect(formatTime({ hours: 12, minutes: 0, seconds: 0 }, { hour12: true })).toBe('12:00 PM');
    expect(formatTime({ hours: 13, minutes: 5, seconds: 0 }, { hour12: true })).toBe('1:05 PM');
    expect(formatTime({ hours: 9, minutes: 30, seconds: 45 }, { hour12: true, withSeconds: true })).toBe(
      '9:30:45 AM',
    );
  });

  it('formats 24h with/without seconds', () => {
    expect(formatTime({ hours: 9, minutes: 5, seconds: 0 })).toBe('09:05');
    expect(formatTime({ hours: 9, minutes: 5, seconds: 7 }, { withSeconds: true })).toBe('09:05:07');
    expect(formatTime(null)).toBe('');
  });

  it('to12Hour / from12Hour round-trip', () => {
    expect(to12Hour(0)).toEqual({ hour: 12, period: 'AM' });
    expect(to12Hour(12)).toEqual({ hour: 12, period: 'PM' });
    expect(to12Hour(23)).toEqual({ hour: 11, period: 'PM' });
    expect(from12Hour(12, 'AM')).toBe(0);
    expect(from12Hour(12, 'PM')).toBe(12);
    expect(from12Hour(11, 'PM')).toBe(23);
    for (let h = 0; h < 24; h += 1) {
      const { hour, period } = to12Hour(h);
      expect(from12Hour(hour, period)).toBe(h);
    }
  });

  it('snapMinuteToStep rounds to the nearest step', () => {
    expect(snapMinuteToStep(7, 15)).toBe(0);
    expect(snapMinuteToStep(8, 15)).toBe(15);
    expect(snapMinuteToStep(53, 15)).toBe(0); // rounds to 60 -> 0
    expect(snapMinuteToStep(31, 1)).toBe(31); // step <= 1 is identity
  });
});

describe('dateCore · formatDate + maskDateInput', () => {
  it('formats a date through token replacements', () => {
    const d = makeDate(2026, 5, 9); // 2026-06-09
    expect(formatDate(d, 'dd.mm.yyyy')).toBe('09.06.2026');
    expect(formatDate(d, 'yyyy-mm-dd')).toBe('2026-06-09');
    expect(formatDate(d, 'd/m/yy')).toBe('9/6/26');
    expect(formatDate(null)).toBe('');
  });

  it('masks digits with the format separator as the user types', () => {
    expect(maskDateInput('1', 'dd.mm.yyyy')).toBe('1');
    expect(maskDateInput('1206', 'dd.mm.yyyy')).toBe('12.06');
    expect(maskDateInput('12062026', 'dd.mm.yyyy')).toBe('12.06.2026');
    expect(maskDateInput('120620269999', 'dd.mm.yyyy')).toBe('12.06.2026'); // clamps width
    expect(maskDateInput('06122026', 'mm/dd/yyyy')).toBe('06/12/2026');
  });

  it('pad2 pads single digits', () => {
    expect(pad2(3)).toBe('03');
    expect(pad2(12)).toBe('12');
  });
});

describe('dateCore · grid building & weekStartsOn', () => {
  it('buildMonthGrid always yields 42 cells starting on weekStartsOn', () => {
    const cells = buildMonthGrid(makeDate(2026, 5, 1), 1); // June 2026, Monday start
    expect(cells).toHaveLength(42);
    // 2026-06-01 is a Monday; with Monday start the first cell is June 1.
    expect(cells[0].iso).toBe('2026-06-01');
    expect(cells[0].inCurrentMonth).toBe(true);
  });

  it('weekStartsOn=0 (Sunday) shifts the lead-in', () => {
    // June 2026 starts on Monday → with Sunday start, the grid leads with May 31.
    const cells = buildMonthGrid(makeDate(2026, 5, 1), 0);
    expect(cells[0].iso).toBe('2026-05-31');
    expect(cells[0].inCurrentMonth).toBe(false);
  });

  it('buildMonthWeeks groups into 6 rows of 7', () => {
    const weeks = buildMonthWeeks(makeDate(2026, 5, 1), 1);
    expect(weeks).toHaveLength(6);
    weeks.forEach((w) => expect(w).toHaveLength(7));
  });

  it('weekdayOffset is relative to weekStartsOn', () => {
    const monday = makeDate(2026, 5, 1);
    expect(weekdayOffset(monday, 1)).toBe(0); // Monday start
    expect(weekdayOffset(monday, 0)).toBe(1); // Sunday start
  });
});

describe('dateCore · addMonths day-clamping & math', () => {
  it('clamps Jan 31 + 1 month to Feb 28 (non-leap)', () => {
    const d = addMonths(makeDate(2025, 0, 31), 1); // Jan 31 2025
    expect(toIsoDate(d)).toBe('2025-02-28');
  });

  it('clamps into a leap February', () => {
    const d = addMonths(makeDate(2024, 0, 31), 1); // Jan 31 2024 (leap)
    expect(toIsoDate(d)).toBe('2024-02-29');
  });

  it('handles negative + cross-year addMonths', () => {
    expect(toIsoDate(addMonths(makeDate(2026, 0, 15), -1))).toBe('2025-12-15');
    expect(toIsoDate(addMonths(makeDate(2026, 11, 15), 2))).toBe('2027-02-15');
  });

  it('addDays / addYears / start/endOfMonth / daysInMonth', () => {
    expect(toIsoDate(addDays(makeDate(2026, 5, 30), 1))).toBe('2026-07-01');
    expect(toIsoDate(addYears(makeDate(2024, 1, 29), 1))).toBe('2025-02-28'); // leap clamp
    expect(toIsoDate(startOfMonth(makeDate(2026, 5, 17)))).toBe('2026-06-01');
    expect(toIsoDate(endOfMonth(makeDate(2026, 5, 17)))).toBe('2026-06-30');
    expect(daysInMonth(2024, 1)).toBe(29);
    expect(daysInMonth(2025, 1)).toBe(28);
  });
});

describe('dateCore · range / clamp / disabled (order-tolerant)', () => {
  const a = makeDate(2026, 5, 10);
  const b = makeDate(2026, 5, 20);
  const mid = makeDate(2026, 5, 15);

  it('isWithinRange tolerates reversed endpoints', () => {
    expect(isWithinRange(mid, a, b)).toBe(true);
    expect(isWithinRange(mid, b, a)).toBe(true); // reversed → same result
    expect(isWithinRange(a, a, b)).toBe(true); // inclusive
    expect(isWithinRange(makeDate(2026, 5, 21), a, b)).toBe(false);
    expect(isWithinRange(mid, null, b)).toBe(false); // missing endpoint
  });

  it('clampDate pulls into [min,max]', () => {
    expect(toIsoDate(clampDate(makeDate(2026, 5, 5), a, b))).toBe('2026-06-10');
    expect(toIsoDate(clampDate(makeDate(2026, 5, 25), a, b))).toBe('2026-06-20');
    expect(toIsoDate(clampDate(mid, a, b))).toBe('2026-06-15');
    expect(toIsoDate(clampDate(mid, null, null))).toBe('2026-06-15');
  });

  it('isDateDisabled combines min/max + predicate', () => {
    expect(isDateDisabled(makeDate(2026, 5, 5), { min: a })).toBe(true);
    expect(isDateDisabled(makeDate(2026, 5, 25), { max: b })).toBe(true);
    expect(isDateDisabled(mid, { min: a, max: b })).toBe(false);
    expect(isDateDisabled(mid, { disabledDate: (d) => d.getDate() === 15 })).toBe(true);
  });

  it('isSameDay / isSameMonth / compareDay', () => {
    expect(isSameDay(a, makeDate(2026, 5, 10))).toBe(true);
    expect(isSameDay(a, b)).toBe(false);
    expect(isSameDay(a, null)).toBe(false);
    expect(isSameMonth(a, b)).toBe(true);
    expect(isSameMonth(a, makeDate(2026, 6, 1))).toBe(false);
    expect(compareDay(a, b)).toBe(-1);
    expect(compareDay(b, a)).toBe(1);
    expect(compareDay(a, makeDate(2026, 5, 10))).toBe(0);
  });
});

describe('dateCore · Intl names for pl + en-US', () => {
  it('weekday short names start at weekStartsOn for both locales', () => {
    const pl = weekdayNames('pl', 1, 'long');
    expect(pl[0].toLowerCase()).toContain('poniedziałek'); // Monday in Polish
    const en = weekdayNames('en-US', 0, 'long');
    expect(en[0]).toBe('Sunday');
    expect(en).toHaveLength(7);
  });

  it('month names localize', () => {
    expect(monthNames('en-US')[5]).toBe('June');
    expect(monthNames('pl')[5].toLowerCase()).toContain('czerwiec');
  });
});

describe('dateCore · ISO round-trips (NO UTC drift)', () => {
  it('toIsoDate uses LOCAL y/m/d, not toISOString()', () => {
    // Build a date at local midnight. toISOString() would shift the day under any
    // negative-offset zone (e.g. America/*). The contract demands the local day.
    const d = makeDate(2026, 0, 1); // 2026-01-01 local midnight
    expect(toIsoDate(d)).toBe('2026-01-01');
    // Guard: the function must NOT equal the UTC date-portion when they differ.
    const utcDatePortion = d.toISOString().slice(0, 10);
    if (d.getTimezoneOffset() > 0) {
      // West-of-UTC: local midnight is the *previous* day in UTC; ensure we kept local.
      expect(toIsoDate(d)).not.toBe(utcDatePortion);
    }
    expect(toIsoDate(d)).toBe(
      `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`,
    );
  });

  it('fromIsoDate parses to LOCAL midnight and rejects bad values', () => {
    const d = fromIsoDate('2026-06-12')!;
    expect(d.getHours()).toBe(0);
    expect(toIsoDate(d)).toBe('2026-06-12');
    expect(fromIsoDate('2024-02-31')).toBeNull(); // impossible day
    expect(fromIsoDate('nope')).toBeNull();
    expect(fromIsoDate(null)).toBeNull();
  });

  it('date round-trips for many days across DST-ish boundaries', () => {
    for (const iso of ['2026-01-01', '2026-03-29', '2026-10-25', '2026-12-31', '2024-02-29']) {
      expect(toIsoDate(fromIsoDate(iso)!)).toBe(iso);
    }
  });

  it('toIsoTime / fromIsoTime round-trip', () => {
    expect(toIsoTime({ hours: 9, minutes: 5, seconds: 0 })).toBe('09:05');
    expect(toIsoTime({ hours: 9, minutes: 5, seconds: 7 }, true)).toBe('09:05:07');
    expect(fromIsoTime('09:05')).toEqual({ hours: 9, minutes: 5, seconds: 0 });
    expect(fromIsoTime('23:59:59')).toEqual({ hours: 23, minutes: 59, seconds: 59 });
    expect(fromIsoTime('24:00')).toBeNull();
  });

  it('toIsoDateTime composes a local ISO datetime WITHOUT a Z suffix', () => {
    const dt = toIsoDateTime(makeDate(2026, 5, 12), { hours: 14, minutes: 30, seconds: 0 });
    expect(dt).toBe('2026-06-12T14:30');
    expect(dt).not.toContain('Z');
    const withSec = toIsoDateTime(makeDate(2026, 5, 12), { hours: 14, minutes: 30, seconds: 5 }, true);
    expect(withSec).toBe('2026-06-12T14:30:05');
  });

  it('fromIsoDateTime splits into local date + time pieces', () => {
    const { date, time } = fromIsoDateTime('2026-06-12T14:30');
    expect(toIsoDate(date!)).toBe('2026-06-12');
    expect(time).toEqual({ hours: 14, minutes: 30, seconds: 0 });
    expect(fromIsoDateTime(null)).toEqual({ date: null, time: null });
  });
});
