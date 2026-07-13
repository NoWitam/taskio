// workflowSchedule — the PURE, testable core of the v2 compositional schedule
// (§4.5, REVISION 4). The 16-family model is RETIRED: a schedule is now a
// composition of three independent axes — a TIME rule, a DAY rule and a MONTH rule
// (AND-semantics) — minus a set of `exclusions`, in a `tz`. This module owns:
//   • the local `ScheduleDraft` shape (nested, editor-friendly) + `emptyScheduleDraft`,
//   • CLIENT-side validators (axis bounds + the window `from < to` invariant) so an
//     invalid schedule is caught BEFORE a 422 (§4.5.11),
//   • `describeSchedule(draft, t)` — the human cadence sentence with FULL PL/EN
//     grammar (cased month/weekday names + Polish plurals; ZERO cron jargon, §4.5.10),
//   • `configToDraft` / `draftToConfig` — the wire ⇄ draft mapping (FLAT wire ⇄ nested
//     draft; a tolerant read-shim upgrades a legacy `{family, params}` block only to
//     seed a draft from GET),
//   • occurrence formatting helpers (shared by the preview strip + the AI modal) and
//     `isPreviousOccurrence` (the strip's prev-or-at tile marker).
//
// The FE owns ALL numeric bounds (mirrored from `App\Modules\Workflows\Enums\
// ScheduleLimits` — a single contract, no meta endpoint) and ALL labels (i18n).
import type {
  ScheduleDayConfig,
  ScheduleDaySpecialKind,
  ScheduleMonthConfig,
  ScheduleTimeConfig,
  WorkflowScheduleConfig,
  WorkflowScheduleExclusions,
} from './types';

type Translate = (key: string, defaultValue?: string, params?: Record<string, string | number>) => string;

// ── Draft model (§4.5.1) ─────────────────────────────────────────────────────
// The nested, editor-friendly shape the whole builder manipulates. It differs from
// the FLAT wire (`WorkflowScheduleConfig`) on purpose: the interval `n` is named `n`
// here (wire: `minutes`/`hours`), the optional bound is a nested `window` (wire: flat
// `from`/`to`), and a day `special` is an object union (wire: a string + flat params).

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

export interface ScheduleExclusions {
  months: number[];
  weekdays: number[];
  dates: string[];
}

export interface ScheduleDraft {
  time: TimeAxis;
  day: DayAxis;
  month: MonthAxis;
  exclusions: ScheduleExclusions;
  /** '' ⇒ omit ⇒ server UTC. */
  tz: string;
}

// ── Numeric bounds — mirror `ScheduleLimits` verbatim (a single contract) ─────
export const SCHEDULE_LIMITS = {
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

/** A fresh, empty exclusions block. */
export function emptyExclusions(): ScheduleExclusions {
  return { months: [], weekdays: [], dates: [] };
}

/**
 * The viewer's active IANA zone via `Intl`, or `''` when unavailable (⇒ server UTC).
 * ONE place resolves it: it SEEDS a fresh draft's `tz` (§4.5.8, "nowe = strefa
 * przeglądarki") and is the DEFAULT `activeTz` for the conditional tz clause in
 * `describeSchedule` (§4.5.10), so a user's own new schedule reads with no "(…)" tail.
 */
export function resolveBrowserZone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
  } catch {
    return '';
  }
}

/**
 * The NEUTRAL draft — the seed on a fresh schedule (§4.5.1): once a day at 09:00,
 * every day, every month, no exclusions. Renders as "Codziennie o 09:00".
 * REV5: `tz` SEEDS the resolved browser zone (fallback `''`), so the wall-clock
 * sentence + preview run in the viewer's own zone (§4.5.8). An EDITED schedule keeps
 * its saved zone (seedFromDetail overrides via `configToDraft`).
 */
export function emptyScheduleDraft(): ScheduleDraft {
  return {
    time: { mode: 'at', at: ['09:00'] },
    day: { mode: 'every_day' },
    month: { mode: 'every_month' },
    exclusions: emptyExclusions(),
    tz: resolveBrowserZone(),
  };
}

// ── Client-side validation (§4.5.11) ─────────────────────────────────────────

/** One client-side validation error: an axis path + an i18n key (+ interp params). */
export interface ScheduleValidationError {
  /** The offending control's path, e.g. 'time.at' / 'day.weekdays' / 'exclusions.dates'. */
  path: string;
  /** The i18n key the builder renders via t(). */
  key: string;
  messageParams?: Record<string, string | number>;
}

const K = 'workflows.schedule.validation.';

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

/**
 * Validate a schedule DRAFT against the same rules the backend enforces, run
 * client-side so the user never hits a 422 first (§4.5.11). Returns one error per
 * offending field (empty ⇒ client-valid). The preview `empty` gate is layered on
 * TOP of this by the builder (a preview outage never blocks; an empty schedule does).
 */
export function validateScheduleDraft(draft: ScheduleDraft): ScheduleValidationError[] {
  const errors: ScheduleValidationError[] = [];
  validateTime(errors, draft);
  validateDay(errors, draft);
  validateMonth(errors, draft.month);
  validateExclusions(errors, draft.exclusions);
  return errors;
}

function validateTime(errors: ScheduleValidationError[], draft: ScheduleDraft): void {
  const time = draft.time;
  if (time.mode === 'at') {
    const at = time.at ?? [];
    const nonEmpty = at.filter((s) => s !== '');
    if (nonEmpty.length === 0) {
      errors.push({ path: 'time.at', key: K + 'timeRequired' });
    } else if (at.length > SCHEDULE_LIMITS.atTimesMax) {
      errors.push({ path: 'time.at', key: K + 'timesMax', messageParams: { max: SCHEDULE_LIMITS.atTimesMax } });
    } else if (at.some((s) => !isValidTime(s))) {
      errors.push({ path: 'time.at', key: K + 'timeFormat' });
    } else if (new Set(at).size !== at.length) {
      errors.push({ path: 'time.at', key: K + 'timeDuplicate' });
    }
    return;
  }
  if (time.mode === 'every_minutes') {
    checkInt(errors, time.n, 'time.n', SCHEDULE_LIMITS.everyMinutesMin, SCHEDULE_LIMITS.everyMinutesMax);
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
  checkInt(errors, time.n, 'time.n', SCHEDULE_LIMITS.everyHoursMin, SCHEDULE_LIMITS.everyHoursMax);
  checkInt(errors, time.minute, 'time.minute', SCHEDULE_LIMITS.minuteMin, SCHEDULE_LIMITS.minuteMax);
  if (time.window) {
    const { from, to } = time.window;
    if (!inRange(from, SCHEDULE_LIMITS.hourMin, SCHEDULE_LIMITS.hourMax) || !inRange(to, SCHEDULE_LIMITS.hourMin, SCHEDULE_LIMITS.hourMax)) {
      errors.push({ path: 'time.window', key: K + 'number' });
    } else if (from >= to) {
      errors.push({ path: 'time.window', key: K + 'windowOrder' });
    }
  }
}

function validateDay(errors: ScheduleValidationError[], draft: ScheduleDraft): void {
  const day = draft.day;
  switch (day.mode) {
    case 'every_day':
      return;
    case 'every_n_days': {
      checkInt(errors, day.n, 'day.n', SCHEDULE_LIMITS.everyNDaysMin, SCHEDULE_LIMITS.everyNDaysMax);
      if (day.window) {
        const { from, to } = day.window;
        if (!inRange(from, SCHEDULE_LIMITS.monthDayMin, SCHEDULE_LIMITS.monthDayMax) || !inRange(to, SCHEDULE_LIMITS.monthDayMin, SCHEDULE_LIMITS.monthDayMax)) {
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
        checkInt(errors, s.ordinal, 'day.special.ordinal', SCHEDULE_LIMITS.ordinalMin, SCHEDULE_LIMITS.ordinalMax);
        if (!inRange(s.weekday, SCHEDULE_LIMITS.weekdayMin, SCHEDULE_LIMITS.weekdayMax)) {
          errors.push({ path: 'day.special.weekday', key: K + 'pickAtLeastOne' });
        }
      } else if (s.kind === 'last_weekday') {
        if (!inRange(s.weekday, SCHEDULE_LIMITS.weekdayMin, SCHEDULE_LIMITS.weekdayMax)) {
          errors.push({ path: 'day.special.weekday', key: K + 'pickAtLeastOne' });
        }
      } else if (s.kind === 'last_working_day' && draft.time.mode !== 'at') {
        // Belt-and-braces: the builder auto-resets time to `at`, so this is unreachable.
        errors.push({ path: 'time.mode', key: K + 'lastWorkingDayNeedsAt' });
      }
      return;
    }
  }
}

function validateMonth(errors: ScheduleValidationError[], month: MonthAxis): void {
  switch (month.mode) {
    case 'every_month':
      return;
    case 'every_n_months': {
      checkInt(errors, month.n, 'month.n', SCHEDULE_LIMITS.everyNMonthsMin, SCHEDULE_LIMITS.everyNMonthsMax);
      if (month.window) {
        const { from, to } = month.window;
        if (!inRange(from, SCHEDULE_LIMITS.monthMin, SCHEDULE_LIMITS.monthMax) || !inRange(to, SCHEDULE_LIMITS.monthMin, SCHEDULE_LIMITS.monthMax)) {
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

function validateExclusions(errors: ScheduleValidationError[], ex: ScheduleExclusions): void {
  if (ex.dates.length > SCHEDULE_LIMITS.exclusionsDatesMax) {
    errors.push({ path: 'exclusions.dates', key: K + 'max', messageParams: { max: SCHEDULE_LIMITS.exclusionsDatesMax } });
  }
  if (new Set(ex.dates).size !== ex.dates.length) {
    errors.push({ path: 'exclusions.dates', key: K + 'timeDuplicate' });
  }
}

/** True when the draft has NO client-side validation errors. */
export function isScheduleDraftValid(draft: ScheduleDraft): boolean {
  return validateScheduleDraft(draft).length === 0;
}

// ── describeSchedule (§4.5.10 — the sentence grammar, PL + EN) ────────────────

const D = 'workflows.schedule.describe.';

/** Read the active language via a dedicated i18n probe key ('pl' | 'en'). */
function langOf(t: Translate): string {
  return t(D + 'lang');
}

/** Zero-pad a number to 2 digits. */
function pad2(n: number): string {
  return String(n).padStart(2, '0');
}

/** Minutes-of-day for an 'HH:mm' string (window ordering). */
function minutesOfDay(time: string): number {
  const [h, m] = time.split(':');
  return Number(h) * 60 + Number(m);
}

/** The Polish plural CATEGORY of a count: one / few / many. EN keys resolve one form. */
function pluralCategory(n: number): 'one' | 'few' | 'many' {
  const abs = Math.abs(n);
  if (abs === 1) return 'one';
  const mod10 = abs % 10;
  const mod100 = abs % 100;
  if (mod10 >= 2 && mod10 <= 4 && !(mod100 >= 12 && mod100 <= 14)) return 'few';
  return 'many';
}

/** The pluralized unit noun for a count ('minute'|'hour'|'day'|'month'). */
function unitNoun(unit: 'minute' | 'hour' | 'day' | 'month', n: number, t: Translate): string {
  return t(`${D}unit.${unit}.${pluralCategory(n)}`);
}

/** The English ordinal SUFFIX form (1st, 2nd, 3rd, 21st…). */
function englishOrdinal(n: number): string {
  const s = ['th', 'st', 'nd', 'rd'];
  const v = n % 100;
  return `${n}${s[(v - 20) % 10] ?? s[v] ?? s[0]}`;
}

/** An ordinal used INLINE where the PL template supplies its own dot: PL "{n}", EN "{n}th". */
function ordinalInline(n: number, t: Translate): string {
  return langOf(t) === 'en' ? englishOrdinal(n) : String(n);
}

/** An ordinal used as a STANDALONE list item: PL "{n}.", EN "{n}th". */
function ordinalDay(n: number, t: Translate): string {
  return langOf(t) === 'en' ? englishOrdinal(n) : `${n}.`;
}

// Cased-name accessors — one i18n key per grammatical case; each locale supplies the
// right form so `describeSchedule` stays locale-unaware (it only holds `t`).
const weekdayLong = (i: number, t: Translate): string => t(`workflows.schedule.weekday.long.${i}`);
const weekdayPlural = (i: number, t: Translate): string => t(`${D}weekdayPlural.${i}`);
const weekdayAcc = (i: number, t: Translate): string => t(`${D}weekdayAcc.${i}`);
const lastWeekdayClause = (i: number, t: Translate): string => t(`${D}lastWeekdayClause.${i}`);
const monthLong = (i: number, t: Translate): string => t(`workflows.schedule.month.long.${i}`);
const monthLocative = (i: number, t: Translate): string => t(`${D}monthIn.${i}`);
const monthGenitive = (i: number, t: Translate): string => t(`${D}monthGen.${i}`);

/** Join localized labels into a natural-language conjunction ("a", "a i b", "a, b i c"). */
function joinList(items: string[], t: Translate): string {
  if (items.length === 0) return '';
  if (items.length === 1) return items[0];
  const sep = t(D + 'listSep');
  const init = items.slice(0, -1).join(sep);
  return t(D + 'listLast', undefined, { init, last: items[items.length - 1] });
}

const sortNums = (list: number[]): number[] => [...list].sort((a, b) => a - b);
const isWorkweek = (wds: number[]): boolean => sortNums(wds).join(',') === '1,2,3,4,5';
const isWeekend = (wds: number[]): boolean => sortNums(wds).join(',') === '0,6';

/** 'YYYY-MM-DD' → 'DD.MM.YYYY' (a locale-neutral compact rendering for the sentence). */
function formatIsoDate(iso: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : iso;
}

/** The TIME head clause (capitalized) — always present, heads the sentence. */
function timeHead(time: TimeAxis, day: DayAxis, t: Translate): string {
  if (time.mode === 'at') {
    const times = time.at;
    if (times.length === 1 && day.mode === 'every_day') {
      return t(D + 'daily', undefined, { t: times[0] });
    }
    return t(D + 'at', undefined, { times: joinList(times, t) });
  }
  if (time.mode === 'every_minutes') {
    let s = t(D + 'everyMinutes', undefined, { n: time.n, unit: unitNoun('minute', time.n, t) });
    if (time.window) s += t(D + 'everyMinutesWindow', undefined, { from: time.window.from, to: time.window.to });
    return s;
  }
  // every_hours
  let s = t(D + 'everyHours', undefined, { n: time.n, unit: unitNoun('hour', time.n, t) });
  if (time.minute) s += t(D + 'everyHoursMinute', undefined, { mm: pad2(time.minute) });
  if (time.window) {
    s += t(D + 'everyHoursWindow', undefined, { from: `${pad2(time.window.from)}:00`, to: `${pad2(time.window.to)}:00` });
  }
  return s;
}

/** The DAY clause (lowercase, appended; '' when every_day). */
function dayClause(day: DayAxis, t: Translate): string {
  switch (day.mode) {
    case 'every_day':
      return '';
    case 'every_n_days': {
      let s = t(D + 'everyNDays', undefined, { n: day.n, unit: unitNoun('day', day.n, t) });
      if (day.window) {
        s += t(D + 'everyNDaysWindow', undefined, { from: ordinalInline(day.window.from, t), to: ordinalInline(day.window.to, t) });
      }
      return s;
    }
    case 'weekdays': {
      const wds = sortNums(day.weekdays);
      if (isWorkweek(wds)) return t(D + 'workdays');
      if (isWeekend(wds)) return t(D + 'weekend');
      return t(D + 'weekdays', undefined, { days: joinList(wds.map((w) => weekdayPlural(w, t)), t) });
    }
    case 'month_days': {
      const days = sortNums(day.days);
      return t(D + 'monthDays', undefined, { days: joinList(days.map((d) => ordinalDay(d, t)), t) });
    }
    case 'special': {
      const s = day.special;
      switch (s.kind) {
        case 'last_day':
          return t(D + 'lastDay');
        case 'last_working_day':
          return t(D + 'lastWorkingDay');
        case 'nth_weekday':
          return t(D + 'nthWeekday', undefined, { ordinal: ordinalInline(s.ordinal, t), weekday: weekdayAcc(s.weekday, t) });
        case 'last_weekday':
          return t(D + 'lastWeekday', undefined, { weekday: weekdayLong(s.weekday, t), clause: lastWeekdayClause(s.weekday, t) });
      }
    }
  }
}

/** The MONTH clause (lowercase, appended; '' when every_month). */
function monthClause(month: MonthAxis, t: Translate): string {
  switch (month.mode) {
    case 'every_month':
      return '';
    case 'every_n_months': {
      let s = t(D + 'everyNMonths', undefined, { n: month.n, unit: unitNoun('month', month.n, t) });
      if (month.window) {
        s += t(D + 'everyNMonthsWindow', undefined, { from: monthGenitive(month.window.from, t), to: monthGenitive(month.window.to, t) });
      }
      return s;
    }
    case 'months': {
      const ms = sortNums(month.months);
      return t(D + 'months', undefined, { months: joinList(ms.map((m) => monthLocative(m, t)), t) });
    }
  }
}

/** The EXCLUSION clause (" — z wyjątkami: {list}"), or '' when nothing is excluded. */
function exclusionsClause(ex: ScheduleExclusions, t: Translate): string {
  const parts: string[] = [];
  const wds = sortNums(ex.weekdays);
  const ms = sortNums(ex.months);
  const dates = [...ex.dates].sort();
  if (wds.length) {
    parts.push(isWeekend(wds) ? t(D + 'exclusionWeekend') : joinList(wds.map((w) => weekdayPlural(w, t)), t));
  }
  if (ms.length) {
    parts.push(joinList(ms.map((m) => monthLong(m, t)), t));
  }
  if (dates.length) {
    if (dates.length === 1) {
      parts.push(formatIsoDate(dates[0]));
    } else {
      const key = pluralCategory(dates.length) === 'few' ? 'exclusionDatesFew' : 'exclusionDatesMany';
      parts.push(t(D + key, undefined, { n: dates.length }));
    }
  }
  if (parts.length === 0) return '';
  return t(D + 'exclusionClause', undefined, { list: parts.join(t(D + 'exclusionSep')) });
}

/**
 * Produce the human cadence sentence from a DRAFT (§4.5.10), i18n-driven so the
 * whole grammar (Polish cases + plurals) stays in the catalog + this pure helper.
 * Reused by the summary (§4.5.3), the detail Trigger panel (§3.2) and the AI modal
 * (§4.5.9). It ALWAYS returns a non-empty string (the neutral draft → "Codziennie o
 * 09:00").
 *
 * REV5 tz clause (decision C): the additive optional `activeTz` (defaulting to the
 * viewer's zone) makes the "({tz})" clause CONDITIONAL — it shows ONLY when the
 * schedule's `tz` is non-empty AND differs from the viewer's active zone (§4.5.10), so
 * a user's own new schedule reads clean and only a foreign/legacy zone surfaces the
 * label. An empty/undefined `activeTz` never suppresses. The exclusion clause still
 * renders weekday/month exclusions a legacy/AI/edited config may carry (§4.5.7).
 */
export function describeSchedule(
  draft: ScheduleDraft,
  t: Translate,
  activeTz: string = resolveBrowserZone(),
): string {
  let sentence = timeHead(draft.time, draft.day, t);
  if (draft.day.mode !== 'every_day') sentence += ', ' + dayClause(draft.day, t);
  if (draft.month.mode !== 'every_month') sentence += ', ' + monthClause(draft.month, t);
  sentence += exclusionsClause(draft.exclusions, t);
  const tz = draft.tz.trim();
  if (tz && tz !== activeTz) sentence += t(D + 'tzClause', undefined, { tz });
  return sentence;
}

// ── config ⇄ draft mapping (wire is FLAT; draft is nested) ────────────────────

/** TIME draft → wire (flat `minutes`/`hours` + flat `from`/`to`). */
function timeToWire(time: TimeAxis): ScheduleTimeConfig {
  switch (time.mode) {
    case 'at':
      return { mode: 'at', at: [...time.at] };
    case 'every_minutes':
      return time.window
        ? { mode: 'every_minutes', minutes: time.n, from: time.window.from, to: time.window.to }
        : { mode: 'every_minutes', minutes: time.n };
    case 'every_hours':
      return time.window
        ? { mode: 'every_hours', hours: time.n, minute: time.minute, from: time.window.from, to: time.window.to }
        : { mode: 'every_hours', hours: time.n, minute: time.minute };
  }
}

/** DAY draft → wire, or `undefined` when every_day (omitted from the payload). */
function dayToWire(day: DayAxis): ScheduleDayConfig | undefined {
  switch (day.mode) {
    case 'every_day':
      return undefined;
    case 'every_n_days':
      return day.window
        ? { mode: 'every_n_days', n: day.n, from: day.window.from, to: day.window.to }
        : { mode: 'every_n_days', n: day.n };
    case 'weekdays':
      return { mode: 'weekdays', weekdays: [...day.weekdays] };
    case 'month_days':
      return { mode: 'month_days', days: [...day.days] };
    case 'special': {
      const s = day.special;
      if (s.kind === 'nth_weekday') return { mode: 'special', special: 'nth_weekday', ordinal: s.ordinal, weekday: s.weekday };
      if (s.kind === 'last_weekday') return { mode: 'special', special: 'last_weekday', weekday: s.weekday };
      return { mode: 'special', special: s.kind };
    }
  }
}

/** MONTH draft → wire, or `undefined` when every_month. */
function monthToWire(month: MonthAxis): ScheduleMonthConfig | undefined {
  switch (month.mode) {
    case 'every_month':
      return undefined;
    case 'every_n_months':
      return month.window
        ? { mode: 'every_n_months', n: month.n, from: month.window.from, to: month.window.to }
        : { mode: 'every_n_months', n: month.n };
    case 'months':
      return { mode: 'months', months: [...month.months] };
  }
}

/** Exclusions draft → wire (only non-empty keys), or `undefined` when all empty. */
function exclusionsToWire(ex: ScheduleExclusions): WorkflowScheduleExclusions | undefined {
  const out: WorkflowScheduleExclusions = {};
  if (ex.months.length) out.months = [...ex.months];
  if (ex.weekdays.length) out.weekdays = [...ex.weekdays];
  if (ex.dates.length) out.dates = [...ex.dates];
  return Object.keys(out).length ? out : undefined;
}

/**
 * Map the builder's ScheduleDraft onto the FLAT v2 wire config for SAVE / PREVIEW.
 * `day` is omitted when every_day, `month` when every_month, `exclusions` when empty,
 * `tz` when blank — the idiomatic minimal payload the backend defaults from.
 */
export function draftToConfig(draft: ScheduleDraft): WorkflowScheduleConfig {
  const config: WorkflowScheduleConfig = { time: timeToWire(draft.time) };
  const day = dayToWire(draft.day);
  if (day) config.day = day;
  const month = monthToWire(draft.month);
  if (month) config.month = month;
  const exclusions = exclusionsToWire(draft.exclusions);
  if (exclusions) config.exclusions = exclusions;
  const tz = draft.tz.trim();
  if (tz) config.tz = tz;
  return config;
}

/** TIME wire → draft (nested `window`). */
function timeFromWire(time: ScheduleTimeConfig | undefined): TimeAxis {
  if (!time || time.mode === 'at') {
    const at = time && time.mode === 'at' ? time.at : undefined;
    return { mode: 'at', at: at && at.length ? [...at] : ['09:00'] };
  }
  if (time.mode === 'every_minutes') {
    const axis: TimeAxis = { mode: 'every_minutes', n: time.minutes };
    if (time.from != null && time.to != null) axis.window = { from: time.from, to: time.to };
    return axis;
  }
  const axis: TimeAxis = { mode: 'every_hours', n: time.hours, minute: time.minute ?? 0 };
  if (time.from != null && time.to != null) axis.window = { from: time.from, to: time.to };
  return axis;
}

/** DAY wire → draft. */
function dayFromWire(day: ScheduleDayConfig | undefined): DayAxis {
  if (!day || day.mode === 'every_day') return { mode: 'every_day' };
  if (day.mode === 'every_n_days') {
    const axis: DayAxis = { mode: 'every_n_days', n: day.n };
    if (day.from != null && day.to != null) axis.window = { from: day.from, to: day.to };
    return axis;
  }
  if (day.mode === 'weekdays') return { mode: 'weekdays', weekdays: [...(day.weekdays ?? [])] };
  if (day.mode === 'month_days') return { mode: 'month_days', days: [...(day.days ?? [])] };
  // special
  return { mode: 'special', special: specialFromWire(day.special, day.ordinal, day.weekday) };
}

function specialFromWire(kind: ScheduleDaySpecialKind, ordinal?: number, weekday?: number): DaySpecial {
  switch (kind) {
    case 'last_day':
      return { kind: 'last_day' };
    case 'last_working_day':
      return { kind: 'last_working_day' };
    case 'nth_weekday':
      return { kind: 'nth_weekday', ordinal: ordinal ?? 1, weekday: weekday ?? 1 };
    case 'last_weekday':
      return { kind: 'last_weekday', weekday: weekday ?? 1 };
  }
}

/** MONTH wire → draft. */
function monthFromWire(month: ScheduleMonthConfig | undefined): MonthAxis {
  if (!month || month.mode === 'every_month') return { mode: 'every_month' };
  if (month.mode === 'every_n_months') {
    const axis: MonthAxis = { mode: 'every_n_months', n: month.n };
    if (month.from != null && month.to != null) axis.window = { from: month.from, to: month.to };
    return axis;
  }
  return { mode: 'months', months: [...(month.months ?? [])] };
}

function exclusionsFromWire(ex: WorkflowScheduleExclusions | undefined): ScheduleExclusions {
  return {
    months: [...(ex?.months ?? [])],
    weekdays: [...(ex?.weekdays ?? [])],
    dates: [...(ex?.dates ?? [])],
  };
}

/** A legacy REV3 `{family, params}` block (read-shim source only). */
interface LegacyScheduleConfig {
  family: string;
  params?: Record<string, unknown>;
  tz?: string | null;
  times?: string[];
  exclusions?: WorkflowScheduleExclusions;
}

function isLegacyConfig(config: unknown): config is LegacyScheduleConfig {
  return !!config && typeof config === 'object' && 'family' in (config as object) && !('time' in (config as object));
}

/**
 * A TOLERANT read-shim: seed a v2 draft from a stored legacy `{family, params}` block
 * (§4.5.1 note). Best-effort only — it covers the common families' time + day so an
 * old row still renders/edits; the backend itself upgrades on read, so this only ever
 * seeds the FE draft from a GET, never a write.
 */
function legacyToDraft(config: LegacyScheduleConfig): ScheduleDraft {
  const params = config.params ?? {};
  const times = Array.isArray(config.times) && config.times.length
    ? config.times
    : typeof params.time === 'string' && params.time
      ? [params.time]
      : ['09:00'];
  const draft = emptyScheduleDraft();
  draft.time = { mode: 'at', at: [...times] };
  if (config.family === 'weekly' && Array.isArray(params.weekdays) && params.weekdays.length) {
    draft.day = { mode: 'weekdays', weekdays: (params.weekdays as unknown[]).map((n) => Number(n)) };
  } else if ((config.family === 'monthly' || config.family === 'quarterly') && params.day != null) {
    draft.day = { mode: 'month_days', days: [Number(params.day)] };
  }
  draft.month = { mode: 'every_month' };
  draft.exclusions = exclusionsFromWire(config.exclusions);
  draft.tz = config.tz ?? '';
  return draft;
}

/**
 * Map a wire ScheduleConfig onto the builder's ScheduleDraft (assist-apply + GET
 * seeding). Accepts the FLAT v2 wire; a legacy `{family, params}` block is upgraded
 * via the read-shim so an old cached row still seeds a usable draft.
 */
export function configToDraft(config: WorkflowScheduleConfig | LegacyScheduleConfig): ScheduleDraft {
  if (isLegacyConfig(config)) return legacyToDraft(config);
  const c = config as WorkflowScheduleConfig;
  return {
    time: timeFromWire(c.time),
    day: dayFromWire(c.day),
    month: monthFromWire(c.month),
    exclusions: exclusionsFromWire(c.exclusions),
    tz: c.tz ?? '',
  };
}

// ── Occurrence formatting (shared by the preview strip + the AI modal) ────────

/** Map an i18n locale ('pl'|'en') onto the BCP-47 tag the formatter uses (24h). */
function occurrenceLocaleTag(locale: string): string {
  return locale === 'pl' ? 'pl-PL' : 'en-GB';
}

/** A safe IANA zone: '' / null / invalid → 'UTC' (the list still renders). */
function safeZone(tz: string | null | undefined): string {
  return tz && tz.trim() !== '' ? tz.trim() : 'UTC';
}

/** The three per-tile Intl formatters (weekday / date / time) in the schedule tz. */
export interface OccurrenceFormatters {
  weekday: Intl.DateTimeFormat;
  date: Intl.DateTimeFormat;
  time: Intl.DateTimeFormat;
}

/** Build the per-tile formatters for a locale + tz (invalid tz falls back to UTC). */
export function occurrencePartsFormatter(locale: string, tz: string | null | undefined): OccurrenceFormatters {
  const tag = occurrenceLocaleTag(locale);
  const build = (options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat => {
    try {
      return new Intl.DateTimeFormat(tag, { ...options, timeZone: safeZone(tz) });
    } catch {
      return new Intl.DateTimeFormat(tag, { ...options, timeZone: 'UTC' });
    }
  };
  return {
    weekday: build({ weekday: 'short' }),
    date: build({ day: 'numeric', month: 'short' }),
    time: build({ hour: '2-digit', minute: '2-digit', hour12: false }),
  };
}

/** Format one ISO occurrence into its three tile parts; an unparseable value → '—'. */
export function formatOccurrenceParts(
  iso: string,
  f: OccurrenceFormatters,
): { weekday: string; date: string; time: string } {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return { weekday: '', date: iso, time: '' };
  return { weekday: f.weekday.format(d), date: f.date.format(d), time: f.time.format(d) };
}

/** The shared single-line Intl options (weekday + date + time) — the AI-modal preview. */
function occurrenceFormatOptions(timeZone: string): Intl.DateTimeFormatOptions {
  return { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false, timeZone };
}

/** A single-line occurrence formatter (weekday + date + time) in the config tz. */
export function occurrenceFormatter(locale: string, tz: string | null | undefined): Intl.DateTimeFormat {
  const tag = occurrenceLocaleTag(locale);
  try {
    return new Intl.DateTimeFormat(tag, occurrenceFormatOptions(safeZone(tz)));
  } catch {
    return new Intl.DateTimeFormat(tag, occurrenceFormatOptions('UTC'));
  }
}

/** Format a single ISO8601 occurrence with a prepared single-line formatter. */
export function formatOccurrence(iso: string, formatter: Intl.DateTimeFormat): string {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? iso : formatter.format(d);
}

/**
 * Whether an occurrence is the "previous" (prev-or-at) tile for a given anchor — the
 * strip marks tile[0] as previous when an anchor is active AND the occurrence is at or
 * before it (§4.5.4). Robust to the anchor being an ISO instant or a local datetime.
 */
export function isPreviousOccurrence(iso: string, anchorIso: string | null): boolean {
  if (!anchorIso) return false;
  const occ = new Date(iso).getTime();
  const anchor = new Date(anchorIso).getTime();
  if (Number.isNaN(occ) || Number.isNaN(anchor)) return false;
  return occ <= anchor;
}

// ── In-card slotted sentences (§4.5.5/§4.5.12, REV5) ──────────────────────────
// The `next` i18n is string-only (no component slots), so each in-card sentence is a
// normal translated string with `{slot}` tokens (e.g. "co {n} minut"). The FE renders
// it by SPLITTING on the token regex into an ORDERED list of literal-text and slot
// segments — a `<span>` per literal, the mapped control per slot. Because WORD ORDER
// lives in the locale STRING (not in component markup), PL and EN reorder slots freely
// and the panels NEVER hardcode order. Slot ids: n, minute, from, to, ordinal, weekday.

/** One segment of a split sentence template: a literal run or a `{slot}` placeholder. */
export type SentenceSegment =
  | { type: 'text'; value: string }
  | { type: 'slot'; name: string };

// A capturing group so `String.prototype.split` KEEPS the `{slot}` delimiters.
const SENTENCE_SLOT_RE = /(\{[a-z]+\})/;
const SENTENCE_SLOT_EXACT = /^\{([a-z]+)\}$/;

/**
 * Split a slotted i18n template into an ordered text/slot segment list (§4.5.12).
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
