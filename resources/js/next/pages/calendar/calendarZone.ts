// calendarZone — the three timezone functions the Calendar screen needs, and nothing else.
//
// WHY THIS IS NOT IN `dateCore.ts`. `dateCore` is deliberately ZONE-FREE ("we never call
// toISOString() on a calendar-day value") and that property is load-bearing for every date
// picker in the app. The Calendar needs the opposite: it must reckon "today", bucket
// instants into day squares, and serialise a wall clock — all in the WORKSPACE's zone,
// which the browser knows nothing about. Mixing the two would give `dateCore` a zone
// argument nobody else wants and would put a browser-local `today()` one prop away from
// this screen. So: a separate, pure, dependency-free module.
//
// Nothing here uses `new Date()` for a decision, and nothing parses a calendar DAY as an
// instant. `Intl.DateTimeFormat.formatToParts` is the whole engine — it is the only
// zone database a browser ships and the only one this project is willing to bundle
// (zero new npm packages).

import type { IsoDay } from './types';

/** A local wall-clock ISO datetime, `yyyy-mm-ddTHH:mm[:ss]` — no zone, no `Z`. */
export type LocalDateTime = string;

/** The pieces an instant decomposes into once a zone is chosen. */
export interface ZonedParts {
  /** The calendar day the instant falls on IN THAT ZONE, `yyyy-mm-dd`. */
  day: IsoDay;
  /** The 24h wall-clock time in that zone, `HH:mm`. */
  time: string;
}

function pad2(n: number): string {
  return String(Math.abs(Math.trunc(n))).padStart(2, '0');
}
function pad4(n: number): string {
  return String(Math.abs(Math.trunc(n))).padStart(4, '0');
}

/**
 * The numeric date/time fields of `instant` as read IN `timeZone`.
 *
 * `hourCycle: 'h23'` rather than `hour12: false`: the latter still yields hour "24" for
 * midnight under some ICU builds, which silently becomes the wrong day when reassembled.
 * The value is normalised anyway, because being right twice costs nothing here.
 */
function zonedFields(
  instant: Date,
  timeZone: string,
): { year: number; month: number; day: number; hour: number; minute: number; second: number } {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(instant);

  const read = (type: Intl.DateTimeFormatPartTypes): number => {
    const found = parts.find((p) => p.type === type)?.value ?? '0';
    return Number(found);
  };

  const hour = read('hour');

  return {
    year: read('year'),
    month: read('month'),
    day: read('day'),
    hour: hour === 24 ? 0 : hour,
    minute: read('minute'),
    second: read('second'),
  };
}

/**
 * The zone's UTC offset, in MILLISECONDS, at a given instant (east of UTC is positive).
 *
 * Derived rather than looked up: read the instant's wall clock in the zone, reassemble it
 * as though it were UTC, and subtract. The difference IS the offset — the standard
 * trick, and the only one available without a tz database.
 */
function zoneOffsetMs(instant: Date, timeZone: string): number {
  const f = zonedFields(instant, timeZone);
  const asUtc = Date.UTC(f.year, f.month - 1, f.day, f.hour, f.minute, f.second);
  // Zero out the sub-second part on both sides so the difference is a clean offset.
  return asUtc - (instant.getTime() - instant.getUTCMilliseconds());
}

/** Format a millisecond offset as an ISO-8601 `±HH:MM` suffix (UTC → `+00:00`). */
function offsetSuffix(offsetMs: number): string {
  const totalMinutes = Math.round(offsetMs / 60000);
  const sign = totalMinutes < 0 ? '-' : '+';
  const abs = Math.abs(totalMinutes);
  return `${sign}${pad2(Math.floor(abs / 60))}:${pad2(abs % 60)}`;
}

/**
 * TODAY, in the WORKSPACE's zone — `yyyy-mm-dd`.
 *
 * This is the only "today" the calendar is allowed to use: the grid highlights the
 * workspace's today, the default month is the workspace's month, and the "Today" button
 * navigates to the workspace's today. A browser-local `new Date()` would put the marker
 * on the wrong square for anyone east or west of the team, on most of the world's days.
 *
 * An unusable zone falls back to the browser's own reckoning rather than throwing — a
 * calendar that renders with a possibly-wrong today beats a calendar that renders nothing.
 */
export function workspaceToday(timeZone: string, now: Date = new Date()): IsoDay {
  try {
    const f = zonedFields(now, timeZone);
    return `${pad4(f.year)}-${pad2(f.month)}-${pad2(f.day)}`;
  } catch {
    return `${pad4(now.getFullYear())}-${pad2(now.getMonth() + 1)}-${pad2(now.getDate())}`;
  }
}

/**
 * Split a UTC instant into the DAY it belongs to and the TIME it reads, both in `timeZone`.
 *
 * Used for exactly two things, and they must agree: bucketing a timed occurrence into a
 * day square, and printing its `HH:mm`. If the bucket and the label came from different
 * reckonings, a 23:30 occurrence would show under the wrong date without anything looking
 * broken.
 *
 * Returns null for an unparseable input rather than a fake time — a missing label is
 * recoverable, an invented one is not.
 */
export function instantToZonedParts(iso: string, timeZone: string): ZonedParts | null {
  if (!iso) return null;
  const instant = new Date(iso);
  if (Number.isNaN(instant.getTime())) return null;

  try {
    const f = zonedFields(instant, timeZone);
    return {
      day: `${pad4(f.year)}-${pad2(f.month)}-${pad2(f.day)}`,
      time: `${pad2(f.hour)}:${pad2(f.minute)}`,
    };
  } catch {
    return null;
  }
}

/**
 * Turn a WALL CLOCK the user typed into an instant carrying an EXPLICIT UTC offset:
 * `'2026-08-09T14:30'` + `'Europe/Warsaw'` → `'2026-08-09T14:30:00+02:00'`.
 *
 * WHY THE EXPLICIT OFFSET IS MANDATORY, WHATEVER THE SERVER DOES. A wall clock alone is not
 * a moment: `'2026-08-09T14:30'` only names one once somebody supplies a zone, and every
 * party that supplies one is a party that can supply a different one. Attaching the offset
 * we computed from `meta.timezone` removes that question from the wire entirely — the value
 * names ONE instant, and it round-trips: what the GET returns is what the user typed,
 * because nothing in between had a zone to guess at.
 *
 * That is why this does not depend on how the backend resolves a zone-less value. Today
 * `StoreCalendarEventRequest` reads one in the WORKSPACE's zone (so a bare string would
 * happen to agree); before that it read it in `config('app.timezone')` = UTC (so a bare
 * string was silently off by the offset, with no error anywhere). An explicit offset was
 * right in both worlds and stays right in the next one — `Carbon::parse` honours an offset
 * carried by the value over any zone handed to it — and, unlike a bare string, it does not
 * quietly change meaning when that resolution does.
 *
 * The DST subtleties below are the price of computing the offset ourselves, and they are the
 * reason this is one function rather than a line at each call site.
 *
 * ALGORITHM. `Date.UTC(fields)` is the wall clock pretended to be UTC; the true instant is
 * that minus the zone's offset — but the offset itself depends on the instant. Two
 * iterations settle it: the first uses the offset at the pretended instant, the second the
 * offset at the corrected one, which is what closes a DST transition.
 *
 * THE TWO DST EDGES, both deterministic and both tested:
 *   • A wall clock that occurs TWICE (autumn fall-back) has two right answers. This picks
 *     one — the LATER instant, the post-transition offset — and always the same one.
 *   • A wall clock that does NOT EXIST (the spring-forward hour) has no right answer at
 *     all. This returns the instant the corrected offset points at, labelled with the
 *     offset it actually used, so the value is real, unambiguous and self-describing
 *     rather than a silent guess dressed as the requested time.
 */
export function zonedWallClockToInstant(local: LocalDateTime, timeZone: string): string {
  const m = String(local ?? '').match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{1,2}):(\d{2})(?::(\d{2}))?/);
  if (!m) return '';

  const year = Number(m[1]);
  const month = Number(m[2]);
  const day = Number(m[3]);
  const hour = Number(m[4]);
  const minute = Number(m[5]);
  const second = m[6] != null ? Number(m[6]) : 0;

  const pretendUtc = Date.UTC(year, month - 1, day, hour, minute, second);

  let offset = 0;
  try {
    offset = zoneOffsetMs(new Date(pretendUtc), timeZone);
    // Second pass: re-read the offset AT the corrected instant. Across a DST boundary the
    // first answer belongs to the other side of the transition.
    offset = zoneOffsetMs(new Date(pretendUtc - offset), timeZone);
  } catch {
    // An unusable zone: emit the wall clock as UTC. Explicit and wrong-by-a-known-amount
    // beats implicit and wrong-by-an-unknown-one.
    offset = 0;
  }

  return (
    `${pad4(year)}-${pad2(month)}-${pad2(day)}` +
    `T${pad2(hour)}:${pad2(minute)}:${pad2(second)}` +
    offsetSuffix(offset)
  );
}

/**
 * Split a stored UTC instant back into the `{day, time}` a form's date + time controls
 * edit — the exact inverse of `zonedWallClockToInstant`, in the same zone. Kept beside it
 * so the round-trip an edit performs (GET → form → PUT) can never use two different
 * reckonings.
 */
export function instantToWallClock(
  iso: string | null,
  timeZone: string,
): { day: IsoDay | null; time: string | null } {
  if (!iso) return { day: null, time: null };
  const parts = instantToZonedParts(iso, timeZone);
  return parts ? { day: parts.day, time: parts.time } : { day: null, time: null };
}

/** Compose the local wall-clock string the two controls hold into one value. */
export function composeWallClock(day: IsoDay | null, time: string | null): LocalDateTime | null {
  if (!day || !time) return null;
  return `${day}T${time.length === 5 ? time : time.slice(0, 5)}`;
}

/**
 * The browser's OWN zone, or null when it cannot be determined. Used for exactly one
 * thing: deciding whether to warn that the calendar's days belong to somebody else's
 * midnight. It never influences what is rendered.
 */
export function browserTimeZone(): string | null {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone ?? null;
  } catch {
    return null;
  }
}

/**
 * `Y-m-d` for a LOCAL `Date` built from explicit fields — the same serialisation
 * `dateCore.toIsoDate` performs, repeated here so this module stays dependency-free and
 * so nothing is tempted to reach for `toISOString()` (which is UTC and would drift).
 */
export function toIsoDay(date: Date): IsoDay {
  return `${pad4(date.getFullYear())}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
}

/**
 * The `YYYY-MM` month a day belongs to, and the inverse — the URL's `?month` contract.
 * String surgery on purpose: a month is not an instant either.
 */
export function monthOf(day: IsoDay): string {
  return String(day).slice(0, 7);
}

/** The FIRST day of a `YYYY-MM` month as a LOCAL `Date` (for `buildMonthWeeks`). */
export function monthStartDate(month: string): Date | null {
  const m = String(month ?? '').match(/^(\d{4})-(\d{2})$/);
  if (!m) return null;
  const year = Number(m[1]);
  const monthIndex = Number(m[2]) - 1;
  if (monthIndex < 0 || monthIndex > 11) return null;
  return new Date(year, monthIndex, 1, 0, 0, 0, 0);
}

/** Shift a `YYYY-MM` month by ±n months. Pure string/number math — no `Date` round-trip. */
export function addMonthsToMonth(month: string, amount: number): string {
  const start = monthStartDate(month);
  if (!start) return month;
  const total = start.getFullYear() * 12 + start.getMonth() + amount;
  const year = Math.floor(total / 12);
  const monthIndex = ((total % 12) + 12) % 12;
  return `${pad4(year)}-${pad2(monthIndex + 1)}`;
}
