// workflowSchedule — the PURE, testable schedule helpers for the descriptor-driven
// progressive builder + AI assist (§4.5, B4). This module NEVER hard-codes a family's
// inputs: it reads the /meta descriptors so it can never drift from what the backend
// accepts. It owns:
//   • descriptor lookup (find a family / a param descriptor),
//   • CLIENT-side validators (the `lt` ordering invariants + min/max bounds, the
//     weekday_list / times / exclusions rules) so an invalid schedule is caught
//     BEFORE a 422,
//   • describeSchedule(config, t) — the human cadence sentence (with the multi-time
//     clause + the exclusions clause) reused by the detail Trigger panel (§3.2),
//   • configToDraft / draftToConfig — the assist-apply + save mapping between the
//     wire ScheduleConfig and the builder's local ScheduleDraft (times/exclusions
//     unified: times[] always holds the hours; exclusions default to empty arrays).
//   • simple-mode representability + family→intent mapping for the progressive tabs.
//
// All labels are FE-owned i18n: describeSchedule takes a `t` translator so it stays
// locale-reactive.
import type {
  ScheduleConfig,
  ScheduleFamilyDescriptor,
  ScheduleParamDescriptor,
  WorkflowScheduleConfig,
  WorkflowScheduleExclusions,
  WorkflowScheduleFamily,
} from './types';

type Translate = (key: string, defaultValue?: string, params?: Record<string, string | number>) => string;

/** A single draft param value (int/time → number|string; weekday_list → number[]). */
export type ScheduleParamValue = number | string | number[];

/** The exclusions block on the draft — always the three arrays (possibly empty). */
export interface ScheduleDraftExclusions {
  months: number[];
  weekdays: number[];
  dates: string[];
}

/**
 * The schedule builder's LOCAL editable state (§4.5, B4). `family` drives which param
 * controls render; `params` holds one value per the family's NON-time descriptors
 * (int → number, weekday → number 0..6, weekday_list → number[]); `times` UNIFIES the
 * hour(s): for a family carrying a `time` param the draft ALWAYS keeps its hours in
 * `times[]` (length ≥1) instead of `params.time`, so single- and multi-time modes
 * share one control. `exclusions` is always the three arrays (empty when unused).
 * `tz` is the optional override ('' = server UTC).
 */
export interface ScheduleDraft {
  family: WorkflowScheduleFamily;
  params: Record<string, ScheduleParamValue>;
  tz: string;
  times: string[];
  exclusions: ScheduleDraftExclusions;
}

/** One client-side validation error: the offending param + an i18n key (+ params). */
export interface ScheduleValidationError {
  /** The descriptor param name (or 'times' / 'exclusions.<key>') the error belongs to. */
  param: string;
  /** The i18n key the builder renders via t(). */
  key: string;
  /** Interpolation params for the message (e.g. {min}, {max}, {field}, {other}). */
  messageParams?: Record<string, string | number>;
}

// --- Exclusion limits (mirror the backend) ----------------------------------
export const EXCLUSION_LIMITS = { months: 11, weekdays: 6, dates: 50 } as const;
/** A schedule may carry at most 6 distinct times. */
export const MAX_TIMES = 6;

/** A fresh, empty exclusions block. */
export function emptyExclusions(): ScheduleDraftExclusions {
  return { months: [], weekdays: [], dates: [] };
}

// --- Descriptor lookup ------------------------------------------------------

/** Find a family's descriptor in the /meta catalog (null when absent/unknown). */
export function findFamilyDescriptor(
  families: ScheduleFamilyDescriptor[],
  family: WorkflowScheduleFamily | string | null | undefined,
): ScheduleFamilyDescriptor | null {
  if (!family) return null;
  return families.find((f) => f.family === family) ?? null;
}

/** The param descriptors for a family (empty when the family is absent/unknown). */
export function paramDescriptorsFor(
  families: ScheduleFamilyDescriptor[],
  family: WorkflowScheduleFamily | string | null | undefined,
): ScheduleParamDescriptor[] {
  return findFamilyDescriptor(families, family)?.params ?? [];
}

/** Find a single param descriptor by name within a family (null when absent). */
export function findParamDescriptor(
  families: ScheduleFamilyDescriptor[],
  family: WorkflowScheduleFamily | string | null | undefined,
  paramName: string,
): ScheduleParamDescriptor | null {
  return paramDescriptorsFor(families, family).find((p) => p.name === paramName) ?? null;
}

/** Whether a family's descriptors include a `time` param (⇒ the family uses `times[]`). */
export function familyHasTime(
  families: ScheduleFamilyDescriptor[],
  family: WorkflowScheduleFamily | string | null | undefined,
): boolean {
  return paramDescriptorsFor(families, family).some((p) => p.type === 'time');
}

// --- Client-side validation (bounds + lt + list + times + exclusions) -------

/** Coerce a draft param value to a number for bound/lt checks (NaN when non-numeric). */
function toNumber(value: ScheduleParamValue | undefined | null): number {
  if (typeof value === 'number') return value;
  if (typeof value === 'string' && value.trim() !== '' && !Number.isNaN(Number(value))) {
    return Number(value);
  }
  return Number.NaN;
}

/** Whether a value is "present" (a required check). */
function isPresent(value: ScheduleParamValue | undefined | null): boolean {
  if (value === null || value === undefined) return false;
  if (Array.isArray(value)) return value.length > 0;
  if (typeof value === 'string') return value.trim() !== '';
  return !Number.isNaN(value);
}

const TIME_RE = /^([01]\d|2[0-3]):[0-5]\d$/;

/** True when a string is a valid 'HH:mm' 24h time. */
export function isValidTime(value: string): boolean {
  return TIME_RE.test(value);
}

/**
 * Validate a schedule DRAFT against its family's descriptors + the B4 additions —
 * the same rules the backend enforces, run client-side so the user never hits a 422
 * first (§4.5.2):
 *   • required params present; int/weekday bounds; the `lt` ordering invariant,
 *   • `weekday_list`: non-empty, unique, each 0..6,
 *   • `times`: 1..6 entries, unique, each 'HH:mm' (a duplicate flags 'times'),
 *   • `exclusions`: bounds (months 1..12, weekdays 0..6) + limits (11 / 6 / 50) +
 *     uniqueness.
 * Returns one error per offending field (empty ⇒ valid).
 */
export function validateScheduleDraft(
  families: ScheduleFamilyDescriptor[],
  draft: ScheduleDraft,
): ScheduleValidationError[] {
  const descriptors = paramDescriptorsFor(families, draft.family);
  const errors: ScheduleValidationError[] = [];
  const usesTime = descriptors.some((d) => d.type === 'time');

  for (const descriptor of descriptors) {
    // The `time` param is represented by the draft's `times[]`, validated below.
    if (descriptor.type === 'time') continue;

    const value = draft.params[descriptor.name];

    // A weekday LIST owns its own emptiness message (non-empty / unique / each 0..6).
    if (descriptor.type === 'weekday_list') {
      const list = Array.isArray(value) ? value : [];
      if (list.length === 0) {
        errors.push({ param: descriptor.name, key: 'workflows.schedule.validation.weekdayListRequired' });
      } else if (new Set(list).size !== list.length) {
        errors.push({ param: descriptor.name, key: 'workflows.schedule.validation.weekdayListDuplicate' });
      } else if (list.some((d) => d < 0 || d > 6)) {
        errors.push({ param: descriptor.name, key: 'workflows.schedule.validation.weekdayListRange' });
      }
      continue;
    }

    // Required presence.
    if (descriptor.required && !isPresent(value)) {
      errors.push({ param: descriptor.name, key: 'workflows.schedule.validation.required' });
      continue;
    }
    if (!isPresent(value)) continue;

    // Numeric bounds (int + weekday).
    if (descriptor.type === 'int' || descriptor.type === 'weekday') {
      const n = toNumber(value);
      if (Number.isNaN(n)) {
        errors.push({ param: descriptor.name, key: 'workflows.schedule.validation.number' });
        continue;
      }
      if (descriptor.min !== undefined && n < descriptor.min) {
        errors.push({
          param: descriptor.name,
          key: 'workflows.schedule.validation.min',
          messageParams: { min: descriptor.min },
        });
      }
      if (descriptor.max !== undefined && n > descriptor.max) {
        errors.push({
          param: descriptor.name,
          key: 'workflows.schedule.validation.max',
          messageParams: { max: descriptor.max },
        });
      }
    }

    // The `lt` ordering invariant — strictly less than the named sibling.
    if (descriptor.lt) {
      const a = toNumber(value);
      const b = toNumber(draft.params[descriptor.lt]);
      if (!Number.isNaN(a) && !Number.isNaN(b) && a >= b) {
        errors.push({
          param: descriptor.name,
          key: 'workflows.schedule.validation.lt',
          messageParams: { field: descriptor.name, other: descriptor.lt },
        });
      }
    }
  }

  // Times (only meaningful for families with a `time` param).
  if (usesTime) {
    const times = draft.times ?? [];
    if (times.length === 0) {
      errors.push({ param: 'times', key: 'workflows.schedule.validation.timesRequired' });
    } else if (times.length > MAX_TIMES) {
      errors.push({
        param: 'times',
        key: 'workflows.schedule.validation.timesMax',
        messageParams: { max: MAX_TIMES },
      });
    } else if (times.some((tm) => !isValidTime(tm))) {
      errors.push({ param: 'times', key: 'workflows.schedule.validation.timesFormat' });
    } else if (new Set(times).size !== times.length) {
      errors.push({ param: 'times', key: 'workflows.schedule.validation.timesDuplicate' });
    }
  }

  // Exclusions.
  errors.push(...validateExclusions(draft.exclusions));

  return errors;
}

/** Validate the exclusions block (bounds + limits + uniqueness). */
export function validateExclusions(exclusions: ScheduleDraftExclusions): ScheduleValidationError[] {
  const errors: ScheduleValidationError[] = [];
  const { months, weekdays, dates } = exclusions;

  if (months.length > EXCLUSION_LIMITS.months) {
    errors.push({ param: 'exclusions.months', key: 'workflows.schedule.validation.exclusionsMonthsMax', messageParams: { max: EXCLUSION_LIMITS.months } });
  }
  if (months.some((m) => m < 1 || m > 12) || new Set(months).size !== months.length) {
    errors.push({ param: 'exclusions.months', key: 'workflows.schedule.validation.exclusionsMonths' });
  }

  if (weekdays.length > EXCLUSION_LIMITS.weekdays) {
    errors.push({ param: 'exclusions.weekdays', key: 'workflows.schedule.validation.exclusionsWeekdaysMax', messageParams: { max: EXCLUSION_LIMITS.weekdays } });
  }
  if (weekdays.some((d) => d < 0 || d > 6) || new Set(weekdays).size !== weekdays.length) {
    errors.push({ param: 'exclusions.weekdays', key: 'workflows.schedule.validation.exclusionsWeekdays' });
  }

  if (dates.length > EXCLUSION_LIMITS.dates) {
    errors.push({ param: 'exclusions.dates', key: 'workflows.schedule.validation.exclusionsDatesMax', messageParams: { max: EXCLUSION_LIMITS.dates } });
  }
  if (new Set(dates).size !== dates.length) {
    errors.push({ param: 'exclusions.dates', key: 'workflows.schedule.validation.exclusionsDatesDuplicate' });
  }

  return errors;
}

/** True when the draft has NO client-side validation errors for its family. */
export function isScheduleDraftValid(
  families: ScheduleFamilyDescriptor[],
  draft: ScheduleDraft,
): boolean {
  return validateScheduleDraft(families, draft).length === 0;
}

// --- describeSchedule (§4.5.6 — the human cadence sentence) -----------------

/** A number param, or a fallback (used when a param is absent while building text). */
function num(params: Record<string, ScheduleParamValue>, name: string, fallback = 0): number {
  const n = toNumber(params[name]);
  return Number.isNaN(n) ? fallback : n;
}

/** A time param as-is ("HH:mm"), or '' when absent. */
function timeOf(params: Record<string, ScheduleParamValue>, name: string): string {
  const v = params[name];
  return typeof v === 'string' ? v : v == null ? '' : String(v);
}

/** The weekday LIST param as number[] (empty when absent). */
function weekdayList(params: Record<string, ScheduleParamValue>, name: string): number[] {
  const v = params[name];
  return Array.isArray(v) ? v : [];
}

/** The ordinal LABEL for nth_weekday_of_month (1..5 → first..fifth). */
function ordinalLabel(ordinal: number, t: Translate): string {
  return t(`workflows.schedule.ordinal.${ordinal}`);
}

/**
 * Join a list of localized labels into a natural-language conjunction
 * ("a", "a and b", "a, b and c") using the shared i18n `and` connector.
 */
function joinList(items: string[], t: Translate): string {
  if (items.length === 0) return '';
  if (items.length === 1) return items[0];
  const and = t('workflows.schedule.and');
  return `${items.slice(0, -1).join(', ')} ${and} ${items[items.length - 1]}`;
}

/** The time CLAUSE: single time → "at HH:mm"; several → "at h1, h2 and h3". */
function timeClause(config: ScheduleConfigLike, t: Translate): string {
  const times = timesOf(config);
  if (times.length === 0) return '';
  return t('workflows.schedule.describe.timeClause', '', { times: joinList(times, t) });
}

/** The exclusions CLAUSE — only the present arrays are stitched in. */
function exclusionsClause(exclusions: WorkflowScheduleExclusions | undefined, t: Translate): string {
  if (!exclusions) return '';
  const parts: string[] = [];
  const months = exclusions.months ?? [];
  const weekdays = exclusions.weekdays ?? [];
  const dates = exclusions.dates ?? [];
  if (weekdays.length) parts.push(joinList(weekdays.map((d) => t(`workflows.schedule.weekday.${d}`)), t));
  if (months.length) parts.push(joinList(months.map((m) => t(`workflows.schedule.month.${m}`)), t));
  if (dates.length) parts.push(joinList(dates.map(formatIsoDate), t));
  if (parts.length === 0) return '';
  const sep = t('workflows.schedule.describe.exclusionSeparator');
  return t('workflows.schedule.describe.exclusionClause', '', { list: parts.join(` ${sep} `) });
}

/** 'YYYY-MM-DD' → 'DD.MM.YYYY' (a locale-neutral compact rendering for the sentence). */
function formatIsoDate(iso: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  return m ? `${m[3]}.${m[2]}.${m[1]}` : iso;
}

// --- Occurrence formatting (shared by the builder preview + the assist) -------

/** Map an i18n NextLocale ('pl' | 'en') onto the BCP-47 tag the formatter uses. */
function occurrenceLocaleTag(locale: string): string {
  return locale === 'pl' ? 'pl-PL' : 'en-GB';
}

/** The shared Intl options: weekday (short) + date + time — the preview cadence rows. */
function occurrenceFormatOptions(timeZone: string): Intl.DateTimeFormatOptions {
  return {
    weekday: 'short',
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone,
  };
}

/**
 * Build the localized occurrence formatter reused by BOTH the builder preview and the
 * assist alternative preview (B5). `tz` is the config's IANA zone ('' / null → UTC); an
 * invalid tz falls back to UTC so the list still renders instead of throwing.
 */
export function occurrenceFormatter(locale: string, tz: string | null | undefined): Intl.DateTimeFormat {
  const tag = occurrenceLocaleTag(locale);
  const zone = tz && tz.trim() !== '' ? tz.trim() : 'UTC';
  try {
    return new Intl.DateTimeFormat(tag, occurrenceFormatOptions(zone));
  } catch {
    return new Intl.DateTimeFormat(tag, occurrenceFormatOptions('UTC'));
  }
}

/**
 * Format a single ISO8601 occurrence with a prepared formatter (weekday + date + time
 * in the config tz). An unparseable value falls back to the raw string.
 */
export function formatOccurrence(iso: string, formatter: Intl.DateTimeFormat): string {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? iso : formatter.format(d);
}

/** A config-ish shape describeSchedule accepts (wire config, assist config, or a draft-derived config). */
type ScheduleConfigLike =
  | (Pick<WorkflowScheduleConfig, 'family' | 'params'> & Partial<Pick<WorkflowScheduleConfig, 'tz' | 'times' | 'exclusions'>>)
  | ScheduleConfig;

/** The times of a config: prefer `times[]`, else the scalar `params.time`, else []. */
function timesOf(config: ScheduleConfigLike): string[] {
  if (Array.isArray(config.times) && config.times.length) return config.times;
  const single = timeOf(config.params ?? {}, 'time');
  return single ? [single] : [];
}

/**
 * Produce the human cadence sentence from a WIRE config (§4.5.6), i18n-driven so
 * adding a family later needs one label, not new rendering code. Accepts the full
 * config (family + params + tz + times + exclusions) so the detail panel shows the
 * complete opis (with the multi-time + exclusions clauses).
 *
 * @param config the wire schedule config (family, params, tz?, times?, exclusions?)
 * @param t      the i18n translator
 */
export function describeSchedule(config: ScheduleConfigLike, t: Translate): string {
  const params = config.params ?? {};
  const tz = config.tz;
  const zone = tz && tz.trim() !== '' ? tz.trim() : t('workflows.schedule.utc');
  const weekday = (index: number): string => t(`workflows.schedule.weekday.${index}`);
  const time = timeClause(config, t);
  const exclusions = exclusionsClause(config.exclusions, t);

  let base: string;
  switch (config.family) {
    case 'every_n_minutes':
      base = t('workflows.schedule.describe.every_n_minutes', '', { n: num(params, 'n', 1), tz: zone });
      break;
    case 'hourly':
      base = t('workflows.schedule.describe.hourly', '', { tz: zone });
      break;
    case 'hourly_at':
      base = t('workflows.schedule.describe.hourly_at', '', { minute: num(params, 'minute'), tz: zone });
      break;
    case 'every_n_hours':
      base = t('workflows.schedule.describe.every_n_hours', '', { n: num(params, 'n', 2), minute: num(params, 'minute'), tz: zone });
      break;
    case 'daily':
      base = t('workflows.schedule.describe.daily', '', { time, tz: zone });
      break;
    case 'twice_daily': {
      // Compose full HH:mm strings (the shared minute included) — a template with a
      // hardcoded ':00' would lie about a schedule running at e.g. 09:30/17:30.
      const pad = (n: number): string => String(n).padStart(2, '0');
      const minute = pad(num(params, 'minute'));
      base = t('workflows.schedule.describe.twice_daily', '', {
        first: `${pad(num(params, 'first_hour'))}:${minute}`,
        second: `${pad(num(params, 'second_hour'))}:${minute}`,
        tz: zone,
      });
      break;
    }
    case 'weekly':
      base = t('workflows.schedule.describe.weekly', '', {
        weekdays: joinList(weekdayList(params, 'weekdays').map(weekday), t),
        time,
        tz: zone,
      });
      break;
    case 'monthly':
      base = t('workflows.schedule.describe.monthly', '', { day: num(params, 'day', 1), time, tz: zone });
      break;
    case 'twice_monthly':
      base = t('workflows.schedule.describe.twice_monthly', '', {
        first: num(params, 'first_day', 1),
        second: num(params, 'second_day', 1),
        time,
        tz: zone,
      });
      break;
    case 'last_day_of_month':
      base = t('workflows.schedule.describe.last_day_of_month', '', { time, tz: zone });
      break;
    case 'quarterly':
      base = t('workflows.schedule.describe.quarterly', '', { day: num(params, 'day', 1), time, tz: zone });
      break;
    case 'yearly':
      base = t('workflows.schedule.describe.yearly', '', {
        month: t(`workflows.schedule.month.${num(params, 'month', 1)}`),
        day: num(params, 'day', 1),
        time,
        tz: zone,
      });
      break;
    case 'every_n_months':
      base = t('workflows.schedule.describe.every_n_months', '', {
        n: num(params, 'n', 2),
        day: num(params, 'day', 1),
        time,
        tz: zone,
      });
      break;
    case 'nth_weekday_of_month':
      base = t('workflows.schedule.describe.nth_weekday_of_month', '', {
        ordinal: ordinalLabel(num(params, 'ordinal', 1), t),
        weekday: weekday(num(params, 'weekday')),
        time,
        tz: zone,
      });
      break;
    case 'last_weekday_of_month':
      base = t('workflows.schedule.describe.last_weekday_of_month', '', {
        weekday: weekday(num(params, 'weekday')),
        time,
        tz: zone,
      });
      break;
    case 'last_working_day_of_month':
      base = t('workflows.schedule.describe.last_working_day_of_month', '', { time, tz: zone });
      break;
    default:
      base = t('workflows.schedule.describe.unknown', '', { tz: zone });
  }

  return exclusions ? `${base} ${exclusions}` : base;
}

// --- config ⇄ draft mapping (assist-apply + save) ---------------------------

/**
 * Map a wire ScheduleConfig onto the builder's ScheduleDraft (§4.5.5a, B4). Params
 * are cloned as-is EXCEPT the `time` param, which is lifted into `times[]`
 * (params.time → [time]; config.times → times). exclusions default to empty arrays.
 * A null/absent tz becomes '' (the builder's "use UTC" state).
 */
export function configToDraft(config: ScheduleConfig | WorkflowScheduleConfig): ScheduleDraft {
  const rawParams = { ...(config.params ?? {}) } as Record<string, ScheduleParamValue>;

  // Unify the time(s) into times[]: prefer an explicit times[], else the scalar time.
  let times: string[];
  if (Array.isArray(config.times) && config.times.length) {
    times = [...config.times];
  } else if (typeof rawParams.time === 'string' && rawParams.time !== '') {
    times = [rawParams.time];
  } else {
    times = [];
  }
  delete rawParams.time; // times[] is the single source of truth

  const ex = config.exclusions ?? {};
  return {
    family: config.family,
    params: rawParams,
    tz: config.tz ?? '',
    times,
    exclusions: {
      months: [...(ex.months ?? [])],
      weekdays: [...(ex.weekdays ?? [])],
      dates: [...(ex.dates ?? [])],
    },
  };
}

/**
 * Map the builder's ScheduleDraft onto the wire ScheduleConfig for SAVE (B4). Only the
 * family's OWN params are emitted (foreign keys dropped so an impossible combo can
 * never be sent). Time mapping: for a family with a `time` param, times.length===1 →
 * `params.time` (NO `times` key); length>1 → `times[]` (NO `params.time`). `tz` is
 * emitted only when a non-empty override is set. `exclusions` is emitted only when at
 * least one array is non-empty, and inside it only non-empty keys (emit-or-omit).
 */
export function draftToConfig(
  draft: ScheduleDraft,
  families?: ScheduleFamilyDescriptor[],
): WorkflowScheduleConfig {
  const descriptors = families ? paramDescriptorsFor(families, draft.family) : null;
  const allowed = descriptors ? descriptors.map((p) => p.name) : null;
  const usesTime = descriptors ? descriptors.some((p) => p.type === 'time') : draft.times.length > 0;

  const params: Record<string, number | string | number[]> = {};
  for (const [name, value] of Object.entries(draft.params)) {
    if (allowed && !allowed.includes(name)) continue; // drop foreign params
    if (name === 'time') continue; // time is carried via times[]
    if (value === '' || value === null || value === undefined) continue; // drop empties
    if (Array.isArray(value) && value.length === 0) continue; // drop empty lists
    params[name] = value;
  }

  const config: WorkflowScheduleConfig = { family: draft.family, params };

  // Times: single → params.time; multiple → times[].
  if (usesTime) {
    const times = draft.times.filter((tm) => tm !== '');
    if (times.length === 1) {
      params.time = times[0];
    } else if (times.length > 1) {
      config.times = [...times];
    }
  }

  const tz = draft.tz?.trim();
  if (tz) config.tz = tz;

  const exclusions = emitExclusions(draft.exclusions);
  if (exclusions) config.exclusions = exclusions;

  return config;
}

/** Build the wire exclusions object (only non-empty keys), or null when all empty. */
function emitExclusions(exclusions: ScheduleDraftExclusions): WorkflowScheduleExclusions | null {
  const out: WorkflowScheduleExclusions = {};
  if (exclusions.months.length) out.months = [...exclusions.months];
  if (exclusions.weekdays.length) out.weekdays = [...exclusions.weekdays];
  if (exclusions.dates.length) out.dates = [...exclusions.dates];
  return Object.keys(out).length ? out : null;
}

/**
 * A fresh empty draft for a family: seeds each NON-time descriptor param with a
 * sensible default (int/weekday → its `min` when bounded, else 0; weekday_list → []),
 * a single blank time when the family uses one, and empty exclusions.
 */
export function emptyScheduleDraft(
  families: ScheduleFamilyDescriptor[],
  family: WorkflowScheduleFamily,
): ScheduleDraft {
  const params: Record<string, ScheduleParamValue> = {};
  let usesTime = false;
  for (const descriptor of paramDescriptorsFor(families, family)) {
    if (descriptor.type === 'time') {
      usesTime = true;
    } else if (descriptor.type === 'weekday_list') {
      params[descriptor.name] = [];
    } else {
      params[descriptor.name] = descriptor.min ?? 0;
    }
  }
  return {
    family,
    params,
    tz: '',
    times: usesTime ? [''] : [],
    exclusions: emptyExclusions(),
  };
}

// --- Simple/advanced mode representability (§4.5 progressive tabs) -----------

/**
 * The five SIMPLE-mode intents (the SegmentedControl above the simple builder). Each
 * maps to a small, curated slice of families:
 *   minutes → every_n_minutes; hours → hourly_at | every_n_hours; daily → daily;
 *   weekly → weekly; monthly → monthly | last_day_of_month.
 */
export type SimpleIntent = 'minutes' | 'hours' | 'daily' | 'weekly' | 'monthly';

/** The families each simple intent can represent. */
export const SIMPLE_INTENT_FAMILIES: Record<SimpleIntent, WorkflowScheduleFamily[]> = {
  minutes: ['every_n_minutes'],
  hours: ['hourly_at', 'every_n_hours'],
  daily: ['daily'],
  weekly: ['weekly'],
  monthly: ['monthly', 'last_day_of_month'],
};

/** The intent a family belongs to in simple mode, or null when it is advanced-only. */
export function intentForFamily(family: WorkflowScheduleFamily): SimpleIntent | null {
  for (const intent of Object.keys(SIMPLE_INTENT_FAMILIES) as SimpleIntent[]) {
    if (SIMPLE_INTENT_FAMILIES[intent].includes(family)) return intent;
  }
  return null;
}

/**
 * Whether a DRAFT is representable in SIMPLE mode. Simple mode covers the curated
 * intent families ONLY, a single time, and NO exclusions. Anything richer (times>1,
 * any exclusion, or an advanced-only family such as twice_daily / twice_monthly /
 * quarterly / yearly / nth_* / last_* / every_n_months) forces ADVANCED.
 */
export function isSimpleRepresentable(draft: ScheduleDraft): boolean {
  if (intentForFamily(draft.family) === null) return false;
  if (draft.times.length > 1) return false;
  const ex = draft.exclusions;
  if (ex.months.length || ex.weekdays.length || ex.dates.length) return false;
  return true;
}
