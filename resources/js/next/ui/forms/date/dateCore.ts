// dateCore — the shared, framework-free date/time engine for the "next" date &
// time input family (DatePicker, TimePicker, DateTimePicker, DateRangePicker,
// MonthPicker). It owns ALL the math, parsing, formatting and (de)serialization
// so the Vue components stay thin and so the tricky parts (calendar grids, ISO
// (de)serialization, tolerant parsing, min/max clamping) are pure + unit-testable.
//
// ── MODEL-SERIALIZATION CONTRACT ───────────────────────────────────────────
// Values are carried in v-model as ISO **strings**, never `Date` objects, to dodge
// timezone surprises (a `Date` is a UTC instant; rendering it back to a local
// calendar day can drift across the date line / DST). The contract is:
//
//   • DatePicker / MonthPicker / DateRangePicker endpoints → `yyyy-mm-dd`
//     (a *plain calendar day*, no time, no zone). MonthPicker uses day `01`.
//   • TimePicker                                           → `HH:mm` or `HH:mm:ss`
//     (a *wall-clock time of day*, 24h, no zone).
//   • DateTimePicker                                       → `yyyy-mm-ddTHH:mm`
//     (or `…:ss`) — a *local* ISO 8601 datetime WITHOUT a `Z`/offset suffix, i.e.
//     "this wall-clock moment in the user's calendar". The backend decides the
//     zone; the UI never invents one.
//
// All internal math uses LOCAL `Date` objects built from explicit y/m/d fields
// (`new Date(y, m, d)`) and read back with the local getters, so a round-trip
// through a `Date` and back to the `yyyy-mm-dd` string is stable for any zone:
// we never call `toISOString()` (which is UTC) on a calendar-day value.
//
// Locale defaults are Polish (Monday week start, `dd.mm.yyyy`, 24h, `pl` names),
// every one overridable. Month/weekday names come from `Intl.DateTimeFormat`.

// ── Types ──────────────────────────────────────────────────────────────────

/** ISO calendar day, `yyyy-mm-dd`. */
export type IsoDate = string;
/** ISO wall-clock time, `HH:mm` or `HH:mm:ss`. */
export type IsoTime = string;
/** Local ISO datetime, `yyyy-mm-ddTHH:mm[:ss]` (no zone). */
export type IsoDateTime = string;

/** Day-of-week index, 0 = Sunday … 6 = Saturday (matches `Date.getDay()`). */
export type WeekDay = 0 | 1 | 2 | 3 | 4 | 5 | 6;

/** Decomposed wall-clock time. */
export interface TimeParts {
  hours: number;
  minutes: number;
  seconds: number;
}

/** One rendered calendar cell in the 6×7 month grid. */
export interface CalendarCell {
  /** The local date this cell represents. */
  date: Date;
  /** `yyyy-mm-dd` for this cell (stable lookup key). */
  iso: IsoDate;
  /** Day number 1..31. */
  day: number;
  /** True when the cell belongs to the displayed month (not a spill day). */
  inCurrentMonth: boolean;
}

/** Predicate deciding whether a given day is selectable. */
export type DisabledDatePredicate = (date: Date) => boolean;

// ── Padding / primitives ────────────────────────────────────────────────────

export function pad2(n: number): string {
  return String(Math.abs(Math.trunc(n))).padStart(2, '0');
}
function pad4(n: number): string {
  return String(Math.abs(Math.trunc(n))).padStart(4, '0');
}

/** Build a LOCAL date at midnight from explicit fields (month is 0-based). */
export function makeDate(year: number, month: number, day: number): Date {
  return new Date(year, month, day, 0, 0, 0, 0);
}

/** Today as a LOCAL midnight `Date`. */
export function today(): Date {
  const n = new Date();
  return makeDate(n.getFullYear(), n.getMonth(), n.getDate());
}

// ── Date math ────────────────────────────────────────────────────────────────

export function addDays(date: Date, amount: number): Date {
  return makeDate(date.getFullYear(), date.getMonth(), date.getDate() + amount);
}
export function addMonths(date: Date, amount: number): Date {
  // Clamp the day so e.g. Jan 31 + 1 month → Feb 28/29, not March 2/3.
  const year = date.getFullYear();
  const month = date.getMonth() + amount;
  const targetYear = year + Math.floor(month / 12);
  const targetMonth = ((month % 12) + 12) % 12;
  const maxDay = daysInMonth(targetYear, targetMonth);
  return makeDate(targetYear, targetMonth, Math.min(date.getDate(), maxDay));
}
export function addYears(date: Date, amount: number): Date {
  return addMonths(date, amount * 12);
}

export function startOfMonth(date: Date): Date {
  return makeDate(date.getFullYear(), date.getMonth(), 1);
}
export function endOfMonth(date: Date): Date {
  return makeDate(date.getFullYear(), date.getMonth() + 1, 0);
}
export function daysInMonth(year: number, month: number): number {
  return new Date(year, month + 1, 0).getDate();
}

/** Stable comparison: only the calendar day matters (time/zone ignored). */
export function isSameDay(a: Date | null, b: Date | null): boolean {
  if (!a || !b) return false;
  return (
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  );
}
export function isSameMonth(a: Date | null, b: Date | null): boolean {
  if (!a || !b) return false;
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth();
}

/** -1 / 0 / 1 comparing only the calendar day. */
export function compareDay(a: Date, b: Date): number {
  const av = makeDate(a.getFullYear(), a.getMonth(), a.getDate()).getTime();
  const bv = makeDate(b.getFullYear(), b.getMonth(), b.getDate()).getTime();
  return av === bv ? 0 : av < bv ? -1 : 1;
}

/** Inclusive in-range check on the day granularity (endpoints order-tolerant). */
export function isWithinRange(
  date: Date,
  start: Date | null,
  end: Date | null,
): boolean {
  if (!start || !end) return false;
  const lo = compareDay(start, end) <= 0 ? start : end;
  const hi = compareDay(start, end) <= 0 ? end : start;
  return compareDay(date, lo) >= 0 && compareDay(date, hi) <= 0;
}

/** Clamp a date to the [min,max] calendar-day window (either bound optional). */
export function clampDate(
  date: Date,
  min: Date | null,
  max: Date | null,
): Date {
  if (min && compareDay(date, min) < 0) return makeDate(min.getFullYear(), min.getMonth(), min.getDate());
  if (max && compareDay(date, max) > 0) return makeDate(max.getFullYear(), max.getMonth(), max.getDate());
  return date;
}

/**
 * Whether a day is disabled, combining the min/max window with an optional
 * caller predicate. Pure so it can be reused by grid building + selection.
 */
export function isDateDisabled(
  date: Date,
  options: {
    min?: Date | null;
    max?: Date | null;
    disabledDate?: DisabledDatePredicate | null;
  } = {},
): boolean {
  const { min, max, disabledDate } = options;
  if (min && compareDay(date, min) < 0) return true;
  if (max && compareDay(date, max) > 0) return true;
  if (disabledDate && disabledDate(date)) return true;
  return false;
}

// ── Week / grid building ─────────────────────────────────────────────────────

/**
 * Offset (0..6) of `date`'s weekday from `weekStartsOn`. With Monday start
 * (1), Monday→0 … Sunday→6; with Sunday start (0), Sunday→0 … Saturday→6.
 */
export function weekdayOffset(date: Date, weekStartsOn: WeekDay): number {
  return (date.getDay() - weekStartsOn + 7) % 7;
}

/**
 * Build the 6×7 (42-cell) calendar grid for the month containing `viewDate`,
 * honoring `weekStartsOn`. Always 6 rows so the popover never changes height
 * month-to-month. Spill days (prev/next month) are flagged `inCurrentMonth:false`.
 */
export function buildMonthGrid(
  viewDate: Date,
  weekStartsOn: WeekDay = 1,
): CalendarCell[] {
  const first = startOfMonth(viewDate);
  const lead = weekdayOffset(first, weekStartsOn);
  const gridStart = addDays(first, -lead);
  const cells: CalendarCell[] = [];
  for (let i = 0; i < 42; i += 1) {
    const date = addDays(gridStart, i);
    cells.push({
      date,
      iso: toIsoDate(date),
      day: date.getDate(),
      inCurrentMonth: date.getMonth() === viewDate.getMonth(),
    });
  }
  return cells;
}

/** The grid as 6 week-rows (for `role="row"` rendering). */
export function buildMonthWeeks(
  viewDate: Date,
  weekStartsOn: WeekDay = 1,
): CalendarCell[][] {
  const flat = buildMonthGrid(viewDate, weekStartsOn);
  const weeks: CalendarCell[][] = [];
  for (let i = 0; i < flat.length; i += 7) weeks.push(flat.slice(i, i + 7));
  return weeks;
}

// ── Intl names ───────────────────────────────────────────────────────────────

/**
 * Localized weekday short names ordered to start at `weekStartsOn`. Built via
 * `Intl.DateTimeFormat` so `pl` (and any locale) is correct without bundled data.
 * 2024-01-01 is a Monday, so we can anchor name generation deterministically.
 */
export function weekdayNames(
  locale: string,
  weekStartsOn: WeekDay = 1,
  format: 'long' | 'short' | 'narrow' = 'short',
): string[] {
  const fmt = new Intl.DateTimeFormat(locale, { weekday: format });
  const names: string[] = [];
  // 2024-01-07 is a Sunday → index 0 maps to Sunday cleanly.
  const sunday = makeDate(2024, 0, 7);
  for (let i = 0; i < 7; i += 1) {
    const day = ((weekStartsOn + i) % 7) as WeekDay;
    names.push(fmt.format(addDays(sunday, day)));
  }
  return names;
}

/** Localized month names (`long` by default). Index 0 = January. */
export function monthNames(
  locale: string,
  format: 'long' | 'short' | 'narrow' = 'long',
): string[] {
  const fmt = new Intl.DateTimeFormat(locale, { month: format });
  return Array.from({ length: 12 }, (_, m) => fmt.format(makeDate(2024, m, 1)));
}

/** Localized "month year" heading, e.g. "czerwiec 2026". */
export function monthYearLabel(date: Date, locale: string): string {
  return new Intl.DateTimeFormat(locale, {
    month: 'long',
    year: 'numeric',
  }).format(date);
}

/** Full localized day label for announcements, e.g. "12 czerwca 2026". */
export function fullDateLabel(date: Date, locale: string): string {
  return new Intl.DateTimeFormat(locale, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(date);
}

// ── ISO (de)serialization ────────────────────────────────────────────────────

/** Serialize a LOCAL date → `yyyy-mm-dd` (NOT `toISOString`, which is UTC). */
export function toIsoDate(date: Date): IsoDate {
  return `${pad4(date.getFullYear())}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
}

/** Parse `yyyy-mm-dd` (lenient about a trailing time) → LOCAL `Date` or null. */
export function fromIsoDate(value: string | null | undefined): Date | null {
  if (!value) return null;
  const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m) return null;
  const year = Number(m[1]);
  const month = Number(m[2]) - 1;
  const day = Number(m[3]);
  if (month < 0 || month > 11 || day < 1 || day > 31) return null;
  const d = makeDate(year, month, day);
  // Reject impossible days (e.g. 2024-02-31 rolled into March).
  if (d.getMonth() !== month || d.getDate() !== day) return null;
  return d;
}

/** Serialize a `Date`'s wall-clock time → `HH:mm` (or `HH:mm:ss` with seconds). */
export function toIsoTime(parts: TimeParts, withSeconds = false): IsoTime {
  const base = `${pad2(parts.hours)}:${pad2(parts.minutes)}`;
  return withSeconds ? `${base}:${pad2(parts.seconds)}` : base;
}

/** Parse `HH:mm[:ss]` (lenient: `H`, `H:m`, …) → TimeParts or null. */
export function fromIsoTime(value: string | null | undefined): TimeParts | null {
  if (!value) return null;
  const m = String(value).match(/^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?/);
  if (!m) return null;
  const hours = Number(m[1]);
  const minutes = Number(m[2]);
  const seconds = m[3] != null ? Number(m[3]) : 0;
  if (hours > 23 || minutes > 59 || seconds > 59) return null;
  return { hours, minutes, seconds };
}

/** Compose a LOCAL ISO datetime (`yyyy-mm-ddTHH:mm[:ss]`, no zone). */
export function toIsoDateTime(
  date: Date,
  parts: TimeParts,
  withSeconds = false,
): IsoDateTime {
  return `${toIsoDate(date)}T${toIsoTime(parts, withSeconds)}`;
}

/** Split a local ISO datetime into its date + time pieces (either may be null). */
export function fromIsoDateTime(
  value: string | null | undefined,
): { date: Date | null; time: TimeParts | null } {
  if (!value) return { date: null, time: null };
  const [datePart, timePart] = String(value).split('T');
  return { date: fromIsoDate(datePart), time: fromIsoTime(timePart) };
}

// ── Tolerant display parsing + token formatting ──────────────────────────────

/** Strip everything but digits. */
function digitsOnly(value: string): string {
  return (value || '').replace(/\D/g, '');
}

/**
 * Parse a typed date string tolerantly against a token format. We don't fully
 * honor arbitrary formats; instead we read the ORDER of the `d`/`m`/`y` tokens in
 * `format` and map the typed digits accordingly. Separators are ignored — the
 * user can type `12.06.2026`, `12062026`, `12/06/2026`, `12-06-2026`. A 2-digit
 * year maps to 2000-2099. Returns a LOCAL `Date` only when a *valid* full date is
 * present, else null (partial / impossible input → null, caller keeps the text).
 */
export function parseDateInput(
  input: string,
  format = 'dd.mm.yyyy',
): Date | null {
  const digits = digitsOnly(input);
  // A 4-digit-year format needs all 8 digits; a 2-digit-year format needs 6.
  // We never guess from a short string (that produced wrong years before).
  const fourDigitYear = format.includes('yyyy');
  const required = fourDigitYear ? 8 : 6;
  if (digits.length < required) return null;

  const order = tokenOrder(format); // e.g. ['d','m','y']
  const yearLen = fourDigitYear ? 4 : 2;

  // Consume widths in token order: day=2, month=2, year=2|4.
  let cursor = 0;
  const parts: Record<'d' | 'm' | 'y', number> = { d: NaN, m: NaN, y: NaN };
  for (const token of order) {
    const width = token === 'y' ? yearLen : 2;
    const slice = digits.slice(cursor, cursor + width);
    if (slice.length < width) return null;
    parts[token] = Number(slice);
    cursor += width;
  }

  let year = parts.y;
  if (yearLen === 2) year = 2000 + year;
  const month = parts.m - 1;
  const day = parts.d;
  if (year < 1 || month < 0 || month > 11 || day < 1 || day > 31) return null;
  const d = makeDate(year, month, day);
  if (d.getMonth() !== month || d.getDate() !== day) return null;
  return d;
}

/** Read the d/m/y token order out of a format like `mm/dd/yyyy`. */
function tokenOrder(format: string): Array<'d' | 'm' | 'y'> {
  const order: Array<'d' | 'm' | 'y'> = [];
  const seen = new Set<string>();
  for (const ch of format.toLowerCase()) {
    const t = ch === 'd' ? 'd' : ch === 'm' ? 'm' : ch === 'y' || ch === 'r' ? 'y' : null;
    if (t && !seen.has(t)) {
      seen.add(t);
      order.push(t);
    }
  }
  return order.length === 3 ? order : ['d', 'm', 'y'];
}

/**
 * Format a `Date` to a token format string. Supported tokens: `yyyy`, `yy`,
 * `mm`, `m`, `dd`, `d`. Anything else (separators) is emitted verbatim. Small +
 * dependency-free; for localized *names* use the Intl helpers above instead.
 */
export function formatDate(date: Date | null, format = 'dd.mm.yyyy'): string {
  if (!date) return '';
  const y = date.getFullYear();
  const mo = date.getMonth() + 1;
  const d = date.getDate();
  return format
    .replace(/yyyy/gi, pad4(y))
    .replace(/yy/gi, pad2(y % 100))
    .replace(/mm/gi, pad2(mo))
    .replace(/\bm\b/gi, String(mo))
    .replace(/dd/gi, pad2(d))
    .replace(/\bd\b/gi, String(d));
}

/** As-you-type masking helper for `dd.mm.yyyy`-style input (separator inserted). */
export function maskDateInput(input: string, format = 'dd.mm.yyyy'): string {
  const digits = digitsOnly(input).slice(0, format.includes('yyyy') ? 8 : 6);
  if (!digits) return '';
  const sep = format.match(/[^dmyr]/i)?.[0] ?? '.';
  const yearLen = format.includes('yyyy') ? 4 : 2;
  const widths = [2, 2, yearLen];
  const chunks: string[] = [];
  let cursor = 0;
  for (const w of widths) {
    if (cursor >= digits.length) break;
    chunks.push(digits.slice(cursor, cursor + w));
    cursor += w;
  }
  return chunks.join(sep);
}

// ── Time parsing / formatting (typed `HH:mm`) ────────────────────────────────

/** Tolerantly parse typed `HH:mm[:ss]` (also `1230`, `9`, `9:5`) → TimeParts|null. */
export function parseTimeInput(
  input: string,
  withSeconds = false,
): TimeParts | null {
  const digits = digitsOnly(input);
  if (!digits) return null;
  // Colon form first (handles `9:5`).
  const colon = String(input).match(/^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/);
  if (colon) {
    const hours = Number(colon[1]);
    const minutes = Number(colon[2]);
    const seconds = colon[3] != null ? Number(colon[3]) : 0;
    if (hours > 23 || minutes > 59 || seconds > 59) return null;
    return { hours, minutes, seconds };
  }
  // Bare digits: HHmm[ss].
  const want = withSeconds ? 6 : 4;
  if (digits.length < (withSeconds ? 4 : 3)) return null;
  const padded = digits.padEnd(want, '0').slice(0, want);
  const hours = Number(padded.slice(0, 2));
  const minutes = Number(padded.slice(2, 4));
  const seconds = withSeconds ? Number(padded.slice(4, 6)) : 0;
  if (hours > 23 || minutes > 59 || seconds > 59) return null;
  return { hours, minutes, seconds };
}

/** Format TimeParts for display, honoring `hour12` (adds AM/PM) + seconds. */
export function formatTime(
  parts: TimeParts | null,
  options: { hour12?: boolean; withSeconds?: boolean } = {},
): string {
  if (!parts) return '';
  const { hour12 = false, withSeconds = false } = options;
  if (hour12) {
    const period = parts.hours < 12 ? 'AM' : 'PM';
    const h12 = parts.hours % 12 === 0 ? 12 : parts.hours % 12;
    const base = `${h12}:${pad2(parts.minutes)}`;
    return withSeconds ? `${base}:${pad2(parts.seconds)} ${period}` : `${base} ${period}`;
  }
  return toIsoTime(parts, withSeconds);
}

/** Convert a 24h hour into its 12h display hour (1..12). */
export function to12Hour(hours24: number): { hour: number; period: 'AM' | 'PM' } {
  const period = hours24 < 12 ? 'AM' : 'PM';
  const hour = hours24 % 12 === 0 ? 12 : hours24 % 12;
  return { hour, period };
}
/** Convert a 12h hour + period back to a 24h hour (0..23). */
export function from12Hour(hour12: number, period: 'AM' | 'PM'): number {
  const h = hour12 % 12;
  return period === 'AM' ? h : h + 12;
}

/** Round minutes to the nearest valid step (used by `minuteStep`). */
export function snapMinuteToStep(minute: number, step: number): number {
  if (step <= 1) return minute;
  return (Math.round(minute / step) * step) % 60;
}
