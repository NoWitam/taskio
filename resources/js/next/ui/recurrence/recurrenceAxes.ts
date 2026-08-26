// recurrenceAxes — the PURE core of the shared recurrence editor: one grammar, several profiles.
//
// ─────────────────────────────────────────────────────────────────────────────────────────
// WHY THIS LIVES IN `ui/` AND NOT IN EITHER MODULE
// ─────────────────────────────────────────────────────────────────────────────────────────
// Two screens ask the same question — "how often?" — and used to answer it with two unrelated
// controls: the Workflows schedule trigger (a full three-axis editor) and the Calendar event
// drawer (a short list of presets derived from the start day). They are now ONE editor.
//
// The backend already had this shape: `app/Support/Recurrence` holds one grammar, and each
// module admits a SUBSET of it (ADR-0052 — the shared layer validates shape by code, the
// modules render their own prose). This module is the frontend mirror of that fact, which is
// why the PROFILE below is not a styling preference: it is the client-side statement of what
// each module's endpoint will actually accept.
//
// ─────────────────────────────────────────────────────────────────────────────────────────
// THE EDITOR MUST NOT BE ABLE TO COMPOSE A RULE THE SERVER WILL REFUSE
// ─────────────────────────────────────────────────────────────────────────────────────────
// Every omission in `CALENDAR_RECURRENCE_PROFILE` is a VERIFIED refusal, read from the rules
// rather than assumed (`app/modules/Calendar/DTOs/CalendarRecurrence.php` — `dayModes()`,
// `daySpecials()`, `monthModes()`; refusals pinned in `CalendarRecurrenceGrammarTest`):
//
//   • the whole TIME axis — `recurrence.time` is `prohibited`. A repeating event happens at
//     the event's own hour; the server stamps `time.mode=at` with that one `HH:mm` itself.
//   • `every_n_days` / `every_n_months` — refused on `recurrence.day.mode` /
//     `recurrence.month.mode`. A modulo cadence compiles to a grid that RESETS every month
//     and every year, so "every 5 days" means something other than it says.
//   • `last_working_day` — refused on `recurrence.day.special`.
//
// Because the two windowed modes are the ONLY carriers of the `from`/`to` window, hiding them
// removes the window from the Calendar too — which matches the server, where those keys are
// foreign to every permitted mode and 422 as `field_not_allowed_for_mode`.
//
// This module is Vue-free and i18n-free at its core (labels take a `t` handed in), so both
// profiles can be unit-tested without mounting anything.
import { daysInMonth, fromIsoDate } from '../forms/date/dateCore';

// ── The axis grammar (shared) ────────────────────────────────────────────────
// The nested, editor-friendly shape. It differs from either module's FLAT wire on purpose:
// the interval `n` is named `n` here (Workflows' wire: `minutes`/`hours`), the optional bound
// is a nested `window` (wire: flat `from`/`to`), and a day `special` is an object union
// (wire: a string + flat params). Each module owns its own ⇄ wire mapping.

export type TimeAxis =
  | { mode: 'at'; at: string[] }
  | { mode: 'every_minutes'; n: number; window?: { from: string; to: string } }
  | { mode: 'every_hours'; n: number; minute: number; window?: { from: number; to: number } };

export type DaySpecial =
  | { kind: 'last_day' }
  | { kind: 'last_working_day' }
  | { kind: 'nth_weekday'; ordinal: number; weekday: number }
  | { kind: 'last_weekday'; weekday: number };

export type DayAxis =
  | { mode: 'every_day' }
  | { mode: 'every_n_days'; n: number; window?: { from: number; to: number } }
  | { mode: 'weekdays'; weekdays: number[] }
  | { mode: 'month_days'; days: number[] }
  | { mode: 'special'; special: DaySpecial };

export type MonthAxis =
  | { mode: 'every_month' }
  | { mode: 'every_n_months'; n: number; window?: { from: number; to: number } }
  | { mode: 'months'; months: number[] };

// ── Sub-modes (what the option cards select) ─────────────────────────────────
// The day axis's `special{}` union is presented FLAT: one card per special kind, so a user
// never picks "special" and then picks again.

export type TimeSubmode = 'at' | 'every_minutes' | 'every_hours';

export type DaySubmode =
  | 'every_day'
  | 'every_n_days'
  | 'weekdays'
  | 'month_days'
  | 'last_day'
  | 'last_working_day'
  | 'weekday_in_month';

export type MonthSubmode = 'every_month' | 'every_n_months' | 'months';

/** Which axis a tab shows. The Calendar omits `time`; Workflows shows all three. */
export type RecurrenceAxisId = 'time' | 'day' | 'month';

/** Which sub-mode an axis value currently is (the card the radio group has checked). */
export function timeSubmodeOf(time: TimeAxis): TimeSubmode {
  return time.mode;
}

export function daySubmodeOf(day: DayAxis): DaySubmode {
  switch (day.mode) {
    case 'every_day':
      return 'every_day';
    case 'every_n_days':
      return 'every_n_days';
    case 'weekdays':
      return 'weekdays';
    case 'month_days':
      return 'month_days';
    case 'special':
      if (day.special.kind === 'last_day') return 'last_day';
      if (day.special.kind === 'last_working_day') return 'last_working_day';
      return 'weekday_in_month';
  }
}

export function monthSubmodeOf(month: MonthAxis): MonthSubmode {
  return month.mode;
}

// ── The PROFILE: which subset of the grammar an endpoint accepts ─────────────

/**
 * WHAT ONE MODULE'S ENDPOINT WILL ACCEPT, as the editor's visible vocabulary.
 *
 * The lists are ORDERED — they are the tab order and the card order, not a set membership
 * test — so a profile also decides what a user reads first.
 */
export interface RecurrenceProfile {
  /** The axes that get a tab, in tab order. An axis not listed is never rendered. */
  axes: RecurrenceAxisId[];
  timeModes: TimeSubmode[];
  dayModes: DaySubmode[];
  monthModes: MonthSubmode[];
}

/** WORKFLOWS: the whole grammar. A schedule trigger may say anything the engine can compile. */
export const WORKFLOW_SCHEDULE_PROFILE: RecurrenceProfile = {
  axes: ['time', 'day', 'month'],
  timeModes: ['at', 'every_minutes', 'every_hours'],
  dayModes: [
    'every_day',
    'every_n_days',
    'weekdays',
    'month_days',
    'last_day',
    'last_working_day',
    'weekday_in_month',
  ],
  monthModes: ['every_month', 'every_n_months', 'months'],
};

/**
 * CALENDAR: the subset `StoreCalendarEventRequest` accepts, and nothing beyond it.
 *
 * This list is EXACTLY `CalendarRecurrence::dayModes()` ∪ `daySpecials()` ∪ `monthModes()`
 * flattened into cards. If the server ever widens, widening this constant is the whole
 * frontend change; until then the editor cannot compose a refusal.
 */
export const CALENDAR_RECURRENCE_PROFILE: RecurrenceProfile = {
  axes: ['day', 'month'],
  timeModes: [],
  dayModes: ['every_day', 'weekdays', 'month_days', 'last_day', 'weekday_in_month'],
  monthModes: ['every_month', 'months'],
};

/** Whether a profile can render a given axis value at all (used by the read-side guard). */
export function profileAdmitsDay(profile: RecurrenceProfile, day: DayAxis): boolean {
  return profile.dayModes.includes(daySubmodeOf(day));
}

export function profileAdmitsMonth(profile: RecurrenceProfile, month: MonthAxis): boolean {
  return profile.monthModes.includes(monthSubmodeOf(month));
}

// ── Numeric bounds — mirror `ScheduleLimits` verbatim (a single contract) ─────
// Read from `app/Support/Recurrence/Enums/ScheduleLimits.php`; both modules enforce the SAME
// numbers, so there is one copy here rather than one per profile.
export const RECURRENCE_LIMITS = {
  atTimesMax: 6,
  everyMinutesMin: 1,
  everyMinutesMax: 59,
  everyHoursMin: 1,
  everyHoursMax: 23,
  minuteMin: 0,
  minuteMax: 59,
  hourMin: 0,
  hourMax: 23,
  everyNDaysMin: 1,
  everyNDaysMax: 31,
  monthDayMin: 1,
  monthDayMax: 31,
  weekdayMin: 0,
  weekdayMax: 6,
  ordinalMin: 1,
  ordinalMax: 5,
  everyNMonthsMin: 1,
  everyNMonthsMax: 12,
  monthMin: 1,
  monthMax: 12,
  exclusionsMonthsMax: 11,
  exclusionsWeekdaysMax: 6,
  exclusionsDatesMax: 50,
} as const;

const TIME_RE = /^([01]\d|2[0-3]):[0-5]\d$/;

/** True when a string is a valid 'HH:mm' 24h time. */
export function isValidTime(value: string): boolean {
  return TIME_RE.test(value);
}

/** Minutes-of-day for an 'HH:mm' string (window ordering). */
export function minutesOfDay(time: string): number {
  const [h, m] = time.split(':');
  return Number(h) * 60 + Number(m);
}

// ── Client-side validation ───────────────────────────────────────────────────
// Run client-side so a user never meets a 422 first. The paths are AXIS-RELATIVE
// (`time.at`, `day.weekdays`, `month.months`) — each host prefixes them with whatever its own
// endpoint calls the block (`trigger_config.schedule.` / `recurrence.`).

/** One client-side validation error: an axis path + an i18n key (+ interp params). */
export interface ScheduleValidationError {
  /** The offending control's path, e.g. 'time.at' / 'day.weekdays' / 'exclusions.dates'. */
  path: string;
  /** The i18n key the host renders via t(). */
  key: string;
  messageParams?: Record<string, string | number>;
}

/** The shared validation catalogue namespace — one wording for both modules. */
export const VALIDATION_KEY = 'recurrenceEditor.validation.';

const K = VALIDATION_KEY;

function inRange(n: number, min: number, max: number): boolean {
  return Number.isFinite(n) && n >= min && n <= max;
}

/** Validate an integer field against bounds, pushing number/min/max errors. */
function checkInt(
  errors: ScheduleValidationError[],
  value: number,
  path: string,
  min: number,
  max: number,
): void {
  if (!Number.isFinite(value)) {
    errors.push({ path, key: K + 'number' });
    return;
  }
  if (value < min) errors.push({ path, key: K + 'min', messageParams: { min } });
  if (value > max) errors.push({ path, key: K + 'max', messageParams: { max } });
}

/** TIME axis rules (bounds + the window `from < to` invariant). */
export function validateTimeAxis(errors: ScheduleValidationError[], time: TimeAxis): void {
  if (time.mode === 'at') {
    const at = time.at ?? [];
    const nonEmpty = at.filter((s) => s !== '');
    if (nonEmpty.length === 0) {
      errors.push({ path: 'time.at', key: K + 'timeRequired' });
    } else if (at.length > RECURRENCE_LIMITS.atTimesMax) {
      errors.push({ path: 'time.at', key: K + 'timesMax', messageParams: { max: RECURRENCE_LIMITS.atTimesMax } });
    } else if (at.some((s) => !isValidTime(s))) {
      errors.push({ path: 'time.at', key: K + 'timeFormat' });
    } else if (new Set(at).size !== at.length) {
      errors.push({ path: 'time.at', key: K + 'timeDuplicate' });
    }
    return;
  }
  if (time.mode === 'every_minutes') {
    checkInt(errors, time.n, 'time.n', RECURRENCE_LIMITS.everyMinutesMin, RECURRENCE_LIMITS.everyMinutesMax);
    if (time.window) {
      const { from, to } = time.window;
      if (!isValidTime(from) || !isValidTime(to)) {
        errors.push({ path: 'time.window', key: K + 'timeFormat' });
      } else if (minutesOfDay(from) >= minutesOfDay(to)) {
        errors.push({ path: 'time.window', key: K + 'windowOrder' });
      }
    }
    return;
  }
  // every_hours
  checkInt(errors, time.n, 'time.n', RECURRENCE_LIMITS.everyHoursMin, RECURRENCE_LIMITS.everyHoursMax);
  checkInt(errors, time.minute, 'time.minute', RECURRENCE_LIMITS.minuteMin, RECURRENCE_LIMITS.minuteMax);
  if (time.window) {
    const { from, to } = time.window;
    if (!inRange(from, RECURRENCE_LIMITS.hourMin, RECURRENCE_LIMITS.hourMax) || !inRange(to, RECURRENCE_LIMITS.hourMin, RECURRENCE_LIMITS.hourMax)) {
      errors.push({ path: 'time.window', key: K + 'number' });
    } else if (from >= to) {
      errors.push({ path: 'time.window', key: K + 'windowOrder' });
    }
  }
}

/**
 * DAY axis rules.
 *
 * `timeMode` is the ONE cross-axis coupling, and it is optional because it only exists where
 * a time axis does: `last_working_day` compiles only against set times. A profile with no
 * time axis passes nothing and the check is skipped — it also offers no `last_working_day`
 * card, so the rule has nothing to fire on.
 */
export function validateDayAxis(
  errors: ScheduleValidationError[],
  day: DayAxis,
  timeMode?: TimeAxis['mode'],
): void {
  switch (day.mode) {
    case 'every_day':
      return;
    case 'every_n_days': {
      checkInt(errors, day.n, 'day.n', RECURRENCE_LIMITS.everyNDaysMin, RECURRENCE_LIMITS.everyNDaysMax);
      if (day.window) {
        const { from, to } = day.window;
        if (!inRange(from, RECURRENCE_LIMITS.monthDayMin, RECURRENCE_LIMITS.monthDayMax) || !inRange(to, RECURRENCE_LIMITS.monthDayMin, RECURRENCE_LIMITS.monthDayMax)) {
          errors.push({ path: 'day.window', key: K + 'number' });
        } else if (from >= to) {
          errors.push({ path: 'day.window', key: K + 'windowOrder' });
        }
      }
      return;
    }
    case 'weekdays':
      if ((day.weekdays ?? []).length === 0) errors.push({ path: 'day.weekdays', key: K + 'pickAtLeastOne' });
      return;
    case 'month_days':
      if ((day.days ?? []).length === 0) errors.push({ path: 'day.days', key: K + 'pickAtLeastOne' });
      return;
    case 'special': {
      const s = day.special;
      if (s.kind === 'nth_weekday') {
        checkInt(errors, s.ordinal, 'day.special.ordinal', RECURRENCE_LIMITS.ordinalMin, RECURRENCE_LIMITS.ordinalMax);
        if (!inRange(s.weekday, RECURRENCE_LIMITS.weekdayMin, RECURRENCE_LIMITS.weekdayMax)) {
          errors.push({ path: 'day.special.weekday', key: K + 'pickAtLeastOne' });
        }
      } else if (s.kind === 'last_weekday') {
        if (!inRange(s.weekday, RECURRENCE_LIMITS.weekdayMin, RECURRENCE_LIMITS.weekdayMax)) {
          errors.push({ path: 'day.special.weekday', key: K + 'pickAtLeastOne' });
        }
      } else if (s.kind === 'last_working_day' && timeMode !== undefined && timeMode !== 'at') {
        // Belt-and-braces: the host auto-resets time to `at`, so this is unreachable.
        errors.push({ path: 'time.mode', key: K + 'lastWorkingDayNeedsAt' });
      }
      return;
    }
  }
}

/** MONTH axis rules. */
export function validateMonthAxis(errors: ScheduleValidationError[], month: MonthAxis): void {
  switch (month.mode) {
    case 'every_month':
      return;
    case 'every_n_months': {
      checkInt(errors, month.n, 'month.n', RECURRENCE_LIMITS.everyNMonthsMin, RECURRENCE_LIMITS.everyNMonthsMax);
      if (month.window) {
        const { from, to } = month.window;
        if (!inRange(from, RECURRENCE_LIMITS.monthMin, RECURRENCE_LIMITS.monthMax) || !inRange(to, RECURRENCE_LIMITS.monthMin, RECURRENCE_LIMITS.monthMax)) {
          errors.push({ path: 'month.window', key: K + 'number' });
        } else if (from >= to) {
          errors.push({ path: 'month.window', key: K + 'windowOrder' });
        }
      }
      return;
    }
    case 'months':
      if ((month.months ?? []).length === 0) errors.push({ path: 'month.months', key: K + 'pickAtLeastOne' });
      return;
  }
}

// ── Anchoring a new sub-mode on a date ───────────────────────────────────────

/**
 * THE CALENDAR'S ANCHOR RULE, AS A SEED.
 *
 * The Calendar refuses a rule whose first occurrence is not the event's own start
 * (`anchor_not_an_occurrence`, reported on `start_date`/`starts_at` — a field the user does
 * not associate with "repeat monthly"). The old preset control made that refusal unreachable
 * by only ever OFFERING rules the start day satisfies; a full axis editor cannot do that
 * without amputating the grammar, so it does the next honest thing: every sub-mode a user
 * selects STARTS on the anchor's own weekday / day-of-month / ordinal / month, and only a
 * deliberate edit can move the rule off the start day — at which point the host says so.
 *
 * `null` (no anchor, or an unparseable one mid-typing) yields the same neutral seeds the
 * Workflows editor has always used: empty lists that read as "pick at least one".
 */
export function seedDay(mode: DaySubmode, anchorDay: string | null): DayAxis {
  const date = fromIsoDate(anchorDay);
  const weekday = date ? date.getDay() : null;
  const dayOfMonth = date ? date.getDate() : null;
  const ordinal = dayOfMonth == null ? null : Math.floor((dayOfMonth - 1) / 7) + 1;

  switch (mode) {
    case 'every_day':
      return { mode: 'every_day' };
    case 'every_n_days':
      return { mode: 'every_n_days', n: 1 };
    case 'weekdays':
      return { mode: 'weekdays', weekdays: weekday == null ? [] : [weekday] };
    case 'month_days':
      return { mode: 'month_days', days: dayOfMonth == null ? [] : [dayOfMonth] };
    case 'last_day':
      return { mode: 'special', special: { kind: 'last_day' } };
    case 'last_working_day':
      return { mode: 'special', special: { kind: 'last_working_day' } };
    case 'weekday_in_month':
      // A 5th weekday is a rule that does not fire in most months while LOOKING monthly; an
      // anchor that IS its month's fifth almost certainly meant the LAST one, which is a rule
      // the engine can keep. The card's own note explains the fifth-weekday case either way.
      return ordinal === 5 && weekday != null
        ? { mode: 'special', special: { kind: 'last_weekday', weekday } }
        : {
            mode: 'special',
            special: { kind: 'nth_weekday', ordinal: ordinal ?? 1, weekday: weekday ?? 1 },
          };
  }
}

export function seedMonth(mode: MonthSubmode, anchorDay: string | null): MonthAxis {
  const date = fromIsoDate(anchorDay);
  switch (mode) {
    case 'every_month':
      return { mode: 'every_month' };
    case 'every_n_months':
      return { mode: 'every_n_months', n: 1 };
    case 'months':
      return { mode: 'months', months: date ? [date.getMonth() + 1] : [] };
  }
}

/**
 * DOES THE ANCHOR DAY ITSELF SATISFY THE RULE?
 *
 * Equivalent to the server's `anchorIsFirstOccurrence`, and deliberately so: the engine
 * generates occurrences from the anchor FORWARD, so the first occurrence is the anchor
 * exactly when the anchor matches both axes. This is a MATCH test, not a generation — no
 * engine is reimplemented here, and it is only ever used to explain a refusal early, never to
 * decide what gets sent.
 *
 * A null/unparseable anchor answers `true`: there is nothing to contradict yet, and blocking
 * a save on a half-typed date would fight the user rather than the rule.
 */
export function anchorSatisfies(day: DayAxis, month: MonthAxis, anchorDay: string | null): boolean {
  const date = fromIsoDate(anchorDay);
  if (!date) return true;
  return matchesMonth(month, date) && matchesDay(day, date);
}

function matchesMonth(month: MonthAxis, date: Date): boolean {
  switch (month.mode) {
    case 'every_month':
      return true;
    case 'every_n_months':
      // Not offered by any profile that uses the anchor rule; a modulo grid has no local test.
      return true;
    case 'months':
      return month.months.includes(date.getMonth() + 1);
  }
}

function matchesDay(day: DayAxis, date: Date): boolean {
  switch (day.mode) {
    case 'every_day':
      return true;
    case 'every_n_days':
      return true; // as above — no profile with an anchor offers it
    case 'weekdays':
      return day.weekdays.includes(date.getDay());
    case 'month_days':
      return day.days.includes(date.getDate());
    case 'special': {
      const s = day.special;
      const lengthOfMonth = daysInMonth(date.getFullYear(), date.getMonth());
      switch (s.kind) {
        case 'last_day':
          return date.getDate() === lengthOfMonth;
        case 'last_working_day':
          return true; // not offered where the anchor rule applies
        case 'nth_weekday':
          return date.getDay() === s.weekday && Math.floor((date.getDate() - 1) / 7) + 1 === s.ordinal;
        case 'last_weekday':
          return date.getDay() === s.weekday && date.getDate() + 7 > lengthOfMonth;
      }
    }
  }
}

// ── In-card slotted sentences ────────────────────────────────────────────────
// The `next` i18n is string-only (no component slots), so each in-card sentence is a normal
// translated string with `{slot}` tokens (e.g. "co {n} minut"). The FE renders it by SPLITTING
// on the token regex into an ORDERED list of literal-text and slot segments — a `<span>` per
// literal, the mapped control per slot. Because WORD ORDER lives in the locale STRING (not in
// component markup), PL and EN reorder slots freely and the panels NEVER hardcode order.
// Slot ids: n, minute, from, to, ordinal, weekday.

/** One segment of a split sentence template: a literal run or a `{slot}` placeholder. */
export type SentenceSegment =
  | { type: 'text'; value: string }
  | { type: 'slot'; name: string };

// A capturing group so `String.prototype.split` KEEPS the `{slot}` delimiters.
const SENTENCE_SLOT_RE = /(\{[a-z]+\})/;
const SENTENCE_SLOT_EXACT = /^\{([a-z]+)\}$/;

/**
 * Split a slotted i18n template into an ordered text/slot segment list.
 * Pass the RAW template (call `t(key)` WITHOUT params so the `{slot}` tokens survive).
 * Empty runs (adjacent slots / leading-or-trailing tokens) are dropped.
 */
export function splitSentenceTemplate(template: string): SentenceSegment[] {
  return template
    .split(SENTENCE_SLOT_RE)
    .filter((part) => part !== '')
    .map((part) => {
      const match = SENTENCE_SLOT_EXACT.exec(part);
      return match ? { type: 'slot', name: match[1] } : { type: 'text', value: part };
    });
}
