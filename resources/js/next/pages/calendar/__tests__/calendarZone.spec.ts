// calendarZone.spec — the timezone arithmetic the whole Calendar rests on.
//
// Every assertion here stands for a defect that would ship SILENTLY: an event an hour off,
// a deadline on the wrong square, a "today" that is somebody else's. None of them throws,
// none of them logs, and all of them look like data.
//
// Runs in `node` (no DOM needed) — `Intl` is the only dependency, and it is built in.
import { describe, it, expect } from 'vitest';
import {
  addMonthsToMonth,
  composeWallClock,
  instantToWallClock,
  instantToZonedParts,
  monthOf,
  monthStartDate,
  workspaceToday,
  zonedWallClockToInstant,
} from '../calendarZone';

describe('workspaceToday', () => {
  it('reckons the day in the WORKSPACE zone, not the browser one', () => {
    // One instant, two zones, two different calendar days. This is the entire reason the
    // function exists: `new Date()` would answer with whichever machine happens to run it.
    const instant = new Date('2026-08-09T22:30:00Z');
    expect(workspaceToday('Pacific/Kiritimati', instant)).toBe('2026-08-10'); // UTC+14
    expect(workspaceToday('Pacific/Niue', instant)).toBe('2026-08-09'); // UTC-11
    expect(workspaceToday('UTC', instant)).toBe('2026-08-09');
  });

  it('pads a single-digit month and day', () => {
    expect(workspaceToday('UTC', new Date('2026-01-05T12:00:00Z'))).toBe('2026-01-05');
  });

  it('falls back to the browser reckoning rather than throwing on a bad zone', () => {
    // A calendar that renders with a possibly-wrong today beats one that renders nothing.
    expect(workspaceToday('Not/AZone', new Date('2026-08-09T12:00:00Z'))).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });
});

describe('instantToZonedParts', () => {
  it('buckets an instant into the day it falls on IN THAT ZONE', () => {
    const parts = instantToZonedParts('2026-08-09T22:30:00Z', 'Pacific/Kiritimati');
    expect(parts).toEqual({ day: '2026-08-10', time: '12:30' });
  });

  it('reads midnight as 00:00 on the NEW day, never 24:00 on the old one', () => {
    // Some ICU builds answer "24" for midnight under `hour12:false`; reassembled naively
    // that silently moves the occurrence to the previous day.
    expect(instantToZonedParts('2026-08-09T00:00:00Z', 'UTC')).toEqual({ day: '2026-08-09', time: '00:00' });
  });

  it('reads the last minute of a day without rolling over', () => {
    expect(instantToZonedParts('2026-08-09T23:59:00Z', 'UTC')).toEqual({ day: '2026-08-09', time: '23:59' });
  });

  it('returns null for an unparseable value instead of inventing a time', () => {
    expect(instantToZonedParts('not-a-date', 'UTC')).toBeNull();
    expect(instantToZonedParts('', 'UTC')).toBeNull();
  });
});

describe('zonedWallClockToInstant', () => {
  it('always emits an EXPLICIT offset — never a zone-less value', () => {
    // A zone-less wall clock is not a moment: it names one only once some layer supplies a
    // zone, and any layer that supplies one could supply a different one. The offset makes
    // the value name a single instant and round-trip unchanged, whatever the server does
    // with a bare string.
    const out = zonedWallClockToInstant('2026-08-09T14:30', 'Europe/Warsaw');
    expect(out).toBe('2026-08-09T14:30:00+02:00');
    expect(out).toMatch(/[+-]\d{2}:\d{2}$/);
  });

  it('uses the zone offset in force ON THAT DATE, not a fixed one', () => {
    // Same zone, same wall clock, six months apart → two different offsets.
    expect(zonedWallClockToInstant('2026-01-09T14:30', 'Europe/Warsaw')).toBe('2026-01-09T14:30:00+01:00');
    expect(zonedWallClockToInstant('2026-08-09T14:30', 'Europe/Warsaw')).toBe('2026-08-09T14:30:00+02:00');
  });

  it('handles a zone WEST of UTC (a negative offset)', () => {
    expect(zonedWallClockToInstant('2026-08-09T14:30', 'America/New_York')).toBe('2026-08-09T14:30:00-04:00');
    expect(zonedWallClockToInstant('2026-01-09T14:30', 'America/New_York')).toBe('2026-01-09T14:30:00-05:00');
  });

  it('emits +00:00 for UTC rather than a bare value', () => {
    expect(zonedWallClockToInstant('2026-08-09T14:30', 'UTC')).toBe('2026-08-09T14:30:00+00:00');
  });

  it('handles a half-hour zone', () => {
    expect(zonedWallClockToInstant('2026-08-09T14:30', 'Asia/Kolkata')).toBe('2026-08-09T14:30:00+05:30');
  });

  // ── DST: the two hours a year that break naive conversions ────────────────
  it('resolves the DOUBLED autumn hour deterministically, to the post-transition offset', () => {
    // Europe/Warsaw falls back on 2026-10-25: 02:30 local happens twice (CEST +02:00 and
    // CET +01:00). Either is defensible; what matters is that the answer never wobbles.
    const out = zonedWallClockToInstant('2026-10-25T02:30', 'Europe/Warsaw');
    expect(out).toBe('2026-10-25T02:30:00+01:00');
    expect(zonedWallClockToInstant('2026-10-25T02:30', 'Europe/Warsaw')).toBe(out);
  });

  it('turns the NON-EXISTENT spring hour into a real, self-describing instant', () => {
    // Europe/Warsaw springs forward on 2026-03-29: 02:00 → 03:00, so 02:30 never happens.
    // There is no honest answer; there must still be a VALID one, labelled with the offset
    // actually used, so a caller can see what it got.
    const out = zonedWallClockToInstant('2026-03-29T02:30', 'Europe/Warsaw');
    expect(out).toBe('2026-03-29T02:30:00+01:00');
    expect(Number.isNaN(new Date(out).getTime())).toBe(false);
  });

  it('round-trips an ordinary moment through the parts function', () => {
    const iso = zonedWallClockToInstant('2026-08-09T14:30', 'Europe/Warsaw');
    expect(instantToZonedParts(new Date(iso).toISOString(), 'Europe/Warsaw')).toEqual({
      day: '2026-08-09',
      time: '14:30',
    });
  });

  it('accepts seconds and a space separator, and rejects nonsense with an empty string', () => {
    expect(zonedWallClockToInstant('2026-08-09T14:30:45', 'UTC')).toBe('2026-08-09T14:30:45+00:00');
    expect(zonedWallClockToInstant('2026-08-09 14:30', 'UTC')).toBe('2026-08-09T14:30:00+00:00');
    expect(zonedWallClockToInstant('nope', 'UTC')).toBe('');
  });
});

describe('instantToWallClock / composeWallClock', () => {
  it('decomposes and recomposes the same moment in the same zone', () => {
    const { day, time } = instantToWallClock('2026-08-09T12:30:00Z', 'Europe/Warsaw');
    expect({ day, time }).toEqual({ day: '2026-08-09', time: '14:30' });
    expect(composeWallClock(day, time)).toBe('2026-08-09T14:30');
  });

  it('reports a missing instant as a missing pair, not as zeros', () => {
    expect(instantToWallClock(null, 'UTC')).toEqual({ day: null, time: null });
    expect(composeWallClock('2026-08-09', null)).toBeNull();
    expect(composeWallClock(null, '14:30')).toBeNull();
  });

  it('trims seconds off a time when composing', () => {
    expect(composeWallClock('2026-08-09', '14:30:45')).toBe('2026-08-09T14:30');
  });
});

describe('month helpers', () => {
  it('reads a month off a day by string surgery — a month is not an instant either', () => {
    expect(monthOf('2026-08-09')).toBe('2026-08');
  });

  it('builds the first of a month as a LOCAL date', () => {
    const start = monthStartDate('2026-08');
    expect(start?.getFullYear()).toBe(2026);
    expect(start?.getMonth()).toBe(7);
    expect(start?.getDate()).toBe(1);
  });

  it('rejects a malformed month rather than guessing', () => {
    expect(monthStartDate('2026-13')).toBeNull();
    expect(monthStartDate('nope')).toBeNull();
  });

  it('shifts months across year boundaries in both directions', () => {
    expect(addMonthsToMonth('2026-12', 1)).toBe('2027-01');
    expect(addMonthsToMonth('2026-01', -1)).toBe('2025-12');
    expect(addMonthsToMonth('2026-08', 12)).toBe('2027-08');
    expect(addMonthsToMonth('2026-08', -12)).toBe('2025-08');
  });
});
