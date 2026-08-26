// calendarRecurrence — the Calendar's half of the shared recurrence editor: its wire mapping,
// its end-of-series state, and the one rule Workflows does not have (the anchor).
//
// It REPLACES `recurrencePresets.ts`. That file existed because the repeat control was a short
// list of rules derived from the start day — deliberately NARROWER than the endpoint accepts —
// so it needed a vocabulary of presets, a way to recognise a stored rule as one of them, and a
// read-only state for every rule it could not name. The control is now the same axis editor
// the Workflows schedule trigger uses (`ui/recurrence/`), on a profile that admits EXACTLY
// what `StoreCalendarEventRequest` admits, so the presets, the recogniser and the remapper all
// have nothing left to do.
//
// ─────────────────────────────────────────────────────────────────────────────────────────
// WHAT THE WRITE CONTRACT STILL DEMANDS, AND WHAT KEEPS IT TRUE
// ─────────────────────────────────────────────────────────────────────────────────────────
//
// 1. THE ANCHOR MUST BE THE RULE'S FIRST OCCURRENCE (`anchor_not_an_occurrence`, reported on
//    `start_date`/`starts_at` — a field the user does not associate with "repeat monthly").
//    The preset control made that unreachable by never OFFERING a rule the start day fails.
//    A full editor cannot, so the guarantee is rebuilt in two halves: every sub-mode SEEDS
//    from the anchor (`seedDay`/`seedMonth`), and `anchorSatisfies` refuses the save with a
//    message on the RULE — both in `ui/recurrence/recurrenceAxes`, because the seeding is the
//    editor's own behaviour and only the refusal is the Calendar's.
//
// 2. THE `special` PARAMS ARE PER-KIND, AND A SPARE ONE IS A 422. `ScheduleDaySpecial::
//    allowedParams()` gives `nth_weekday` → ordinal + weekday, `last_weekday` → weekday,
//    `last_day` → NOTHING. Sending `ordinal` alongside `last_day` is refused as
//    `field_not_allowed_for_mode`, so `dayToWire` emits one shape per kind rather than one
//    object with optional keys.
//
// 3. "N TIMES" DOES NOT COME BACK AS "N TIMES". `recurrence.count` is walked to a real day
//    once, at write time; only `until` is ever persisted, and only `until` ever returns.
//    `endMode` can be `count` on the way OUT and only `never`/`until` on the way IN.
//
// 4. `exclusions` IS CARRIED, NEVER AUTHORED. It grows only through "delete this one
//    occurrence", server-side. A whole-event `PUT` that drops it RESURRECTS every day
//    somebody removed one at a time, so it is copied through verbatim.
//
// 5. AN ABSENT `recurrence` KEY MEANS "HAPPENS ONCE"; AN EMPTY-ISH OBJECT DOES NOT.
//    `{day: null, month: null}` is `filled()` server-side and compiles to "every day,
//    forever" — a series nobody asked for. `recurrenceStateToWire` returns `null` for "does
//    not repeat" so the caller can OMIT the key rather than send an empty one.
//
// 6. A RULE THIS EDITOR CANNOT EXPRESS IS STILL NEVER REWRITTEN. The profile mirrors the
//    endpoint, so no rule the Calendar can STORE lands there — but a descriptor that somehow
//    falls outside it is echoed back byte for byte rather than being flattened into the
//    nearest expressible rule. See `unsupported`.
import {
  anchorSatisfies,
  type DayAxis,
  type MonthAxis,
} from '../../ui/recurrence/recurrenceAxes';
import type {
  CalendarRecurrenceDayAxis,
  CalendarRecurrenceMonthAxis,
  CalendarRecurrenceRule,
  CalendarRecurrenceWrite,
  IsoDay,
} from './types';

/** How a series ends. `count` exists only on the way out — see fact 3. */
export type RecurrenceEndMode = 'never' | 'until' | 'count';

/**
 * Everything the repeat control holds. Kept as ONE object so the drawer can seed it, hand it
 * to the field, and turn it back into a payload without several refs drifting apart.
 */
export interface RecurrenceState {
  /**
   * WHETHER IT REPEATS AT ALL — the one question the shared editor does not ask. A schedule
   * trigger always repeats, so the axis grammar has no way to say "once": `every_day` +
   * `every_month` is "every day forever", not "one day". This flag is that missing bit, and
   * it is what decides between sending the `recurrence` key and omitting it (fact 5).
   */
  repeats: boolean;
  day: DayAxis;
  month: MonthAxis;
  endMode: RecurrenceEndMode;
  until: IsoDay | null;
  count: number | null;
  /** Carried verbatim from the GET. The form never builds or clears it (fact 4). */
  exclusions: IsoDay[] | null;
  /**
   * A rule READ from the server that this editor's profile cannot render — kept whole so it
   * can be echoed back untouched (fact 6). `null` in every ordinary case, and it should stay
   * that way: the profile admits exactly what the endpoint admits, and the endpoint refuses
   * the rest at BOTH the request rules and the `CalendarRecurrence` DTO, so no supported
   * write path can produce one. It exists for the two that would otherwise corrupt data
   * silently — a hand-edited row, and a backend that widens the grammar before this does.
   */
  unsupported: CalendarRecurrenceRule | null;
  /**
   * THE CADENCE AS THE SERVER ALREADY ACCEPTED IT, or null on a create.
   *
   * It exists for one job: deciding whether the anchor rule is this form's to enforce. THE
   * RULE'S CLOCK IS NOT ALWAYS THE FORM'S CLOCK — a rule is stamped with the workspace zone at
   * the moment of the write and never re-derived (`recurrence_timezone`), while this form
   * reads its anchor day on the workspace's CURRENT clock. Change the workspace zone by
   * enough to move an instant across midnight and a perfectly good "every Monday" series is
   * being looked at on a Sunday.
   *
   * Judged naively, that series looks anchor-violating and the form would refuse to save a
   * TITLE edit — over a cadence the server is entirely happy with, because the server checked
   * it against the series' own clock. So an UNTOUCHED cadence is never re-judged here. The
   * anchor gate applies to a rule composed in this session, which is the only rule this form
   * has standing to refuse.
   */
  accepted: { day: DayAxis; month: MonthAxis } | null;
}

/**
 * THE LARGEST "REPEAT N TIMES" THE FORM OFFERS — A MIRROR, NOT THE TRUTH.
 *
 * THE SOURCE OF TRUTH IS THE SERVER, in `config/calendar.php` as `calendar.recurrence_count_max`
 * (env `CALENDAR_RECURRENCE_COUNT_MAX`, default 366), enforced by
 * `StoreCalendarEventRequest::maxRecurrenceCount()`. That value IS NOT PUBLISHED ANYWHERE THIS
 * CLIENT CAN READ IT — not in `GET /calendar/occurrences`' `meta` (timezone, truncations,
 * sources, unavailable_sources) and not on `CalendarEventResource`. It was checked rather than
 * assumed; there is no key to bind to, and three digits do not justify inventing an endpoint or
 * widening the contract to carry them.
 *
 * SO THE NUMBER IS COPIED, AND THE COPY IS DELIBERATELY NOT THE AUTHORITY. It is the field's
 * `max`, which drives the spinner's ceiling and the out-of-range flag — a HINT, not a gate:
 * `NumberInput` is not given `clampOnBlur`, so a larger number can still be typed and still
 * reaches the server. That matters in exactly the direction the drift hurts. If the env RAISES
 * the cap, this control marks a legal value out of range but still lets it through, and the
 * save succeeds; if the env LOWERS it, the server's own refusal comes back on
 * `recurrence.count` and renders under this very field (`RecurrenceField`'s `endError`). The
 * server decides either way; only the affordance is ever stale.
 *
 * IF THIS EVER NEEDS TO BE EXACT, publish it — one number on the occurrences `meta` beside
 * `timezone`, read here, fallback to this constant — rather than editing this line to match a
 * deployment.
 */
export const RECURRENCE_COUNT_MAX = 366;

// ── Axes ⇄ the Calendar's wire shape ─────────────────────────────────────────

/**
 * The DAY axis as `recurrence.day`, or `null` when this axis is one the endpoint refuses.
 * `null` is NOT "every day" here — it is "cannot be expressed", and the only caller treats it
 * as a reason to echo the stored rule instead of inventing one.
 */
function dayToWire(day: DayAxis): CalendarRecurrenceDayAxis | null {
  switch (day.mode) {
    case 'every_day':
      return { mode: 'every_day' };
    case 'weekdays':
      return { mode: 'weekdays', weekdays: [...day.weekdays] };
    case 'month_days':
      return { mode: 'month_days', days: [...day.days] };
    case 'special': {
      const s = day.special;
      // One shape per kind — a spare param is `field_not_allowed_for_mode` (fact 2).
      if (s.kind === 'nth_weekday') {
        return { mode: 'special', special: 'nth_weekday', ordinal: s.ordinal, weekday: s.weekday };
      }
      if (s.kind === 'last_weekday') return { mode: 'special', special: 'last_weekday', weekday: s.weekday };
      if (s.kind === 'last_day') return { mode: 'special', special: 'last_day' };
      return null; // last_working_day — refused on `recurrence.day.special`
    }
    case 'every_n_days':
      return null; // refused on `recurrence.day.mode`
  }
}

/**
 * The MONTH axis as `recurrence.month`. "Every month" is the ABSENT axis (`null` on the wire),
 * which is how the server reads it too — so the two nulls this function can return are told
 * apart by the wrapper below, never by the caller.
 */
function monthToWire(month: MonthAxis): { value: CalendarRecurrenceMonthAxis | null } | null {
  if (month.mode === 'every_month') return { value: null };
  if (month.mode === 'months') return { value: { mode: 'months', months: [...month.months] } };
  return null; // every_n_months — refused on `recurrence.month.mode`
}

/** The DAY axis as read. `null` ⇒ this editor cannot render it (see `unsupported`). */
function dayFromWire(day: CalendarRecurrenceDayAxis | null | undefined): DayAxis | null {
  if (!day || day.mode === 'every_day') return { mode: 'every_day' };
  if (day.mode === 'weekdays') return { mode: 'weekdays', weekdays: numbers(day.weekdays) };
  if (day.mode === 'month_days') return { mode: 'month_days', days: numbers(day.days) };
  if (day.mode === 'special') {
    if (day.special === 'last_day') return { mode: 'special', special: { kind: 'last_day' } };
    if (day.special === 'nth_weekday') {
      return {
        mode: 'special',
        special: { kind: 'nth_weekday', ordinal: Number(day.ordinal ?? 1), weekday: Number(day.weekday ?? 0) },
      };
    }
    if (day.special === 'last_weekday') {
      return { mode: 'special', special: { kind: 'last_weekday', weekday: Number(day.weekday ?? 0) } };
    }
  }
  return null;
}

/** The MONTH axis as read. `null` ⇒ unrenderable. An absent axis is "every month". */
function monthFromWire(month: CalendarRecurrenceMonthAxis | null | undefined): MonthAxis | null {
  if (!month || month.mode === 'every_month') return { mode: 'every_month' };
  if (month.mode === 'months') return { mode: 'months', months: numbers(month.months) };
  return null;
}

/** Lists arrive as numbers, but `["2"]` and `[2]` are the same rule — normalise on read. */
function numbers(list: unknown): number[] {
  if (!Array.isArray(list)) return [];
  return list.map((value) => Number(value)).sort((a, b) => a - b);
}

// ── State ⇄ wire ─────────────────────────────────────────────────────────────

/** "Does not repeat", the shape a create starts from. */
export function emptyRecurrenceState(): RecurrenceState {
  return {
    repeats: false,
    // The NEUTRAL rule, and the one every "turn repeating on" starts at: it is the only
    // cadence that satisfies EVERY anchor, so switching the control on can never open with a
    // rule the start day already contradicts.
    day: { mode: 'every_day' },
    month: { mode: 'every_month' },
    endMode: 'never',
    until: null,
    count: null,
    exclusions: null,
    unsupported: null,
    accepted: null,
  };
}

/**
 * Seed the control from a rule the server returned.
 *
 * `endMode` can only ever come back as `never` or `until` — never `count`, because a count is
 * resolved to a day at write time and the row has no column to remember it by (fact 3).
 */
export function recurrenceStateFrom(rule: CalendarRecurrenceRule | null): RecurrenceState {
  if (!rule) return emptyRecurrenceState();

  const dates = rule.exclusions?.dates ?? null;
  const base = {
    repeats: true,
    endMode: (rule.until ? 'until' : 'never') as RecurrenceEndMode,
    until: rule.until ?? null,
    count: null,
    exclusions: dates && dates.length > 0 ? [...dates] : null,
  };

  const day = dayFromWire(rule.day);
  const month = monthFromWire(rule.month);
  if (!day || !month) {
    // Not flattened into the nearest expressible rule (fact 6). The axes below are only what
    // the frozen editor would show if it were shown at all; `unsupported` is what gets sent.
    return {
      ...base,
      day: { mode: 'every_day' },
      month: { mode: 'every_month' },
      unsupported: rule,
      accepted: null,
    };
  }
  return { ...base, day, month, unsupported: null, accepted: { day, month } };
}

/**
 * The `recurrence` block for the write endpoint, or `null` when the caller must OMIT the key
 * entirely (fact 5 — an empty-ish object compiles to "every day, forever" server-side).
 *
 * `until` and `count` are mutually exclusive on the wire (422 `end_is_one_thing`), so exactly
 * one of them is ever emitted; "never" emits an explicit `until: null`, which CLEARS an end a
 * previous save set, because a whole-event write rebuilds the descriptor from what it is given.
 */
export function recurrenceStateToWire(state: RecurrenceState): CalendarRecurrenceWrite | null {
  if (!state.repeats) return null;

  const wire: CalendarRecurrenceWrite = state.unsupported
    ? { day: state.unsupported.day, month: state.unsupported.month }
    : axesToWire(state);
  if (!wire) return null;

  // Carried, never authored (fact 4). Omitted when there is nothing to carry, which is not
  // the same as clearing a list that exists — there is none.
  if (state.exclusions && state.exclusions.length > 0) {
    wire.exclusions = { dates: [...state.exclusions] };
  }

  if (state.endMode === 'count' && state.count != null) {
    wire.count = state.count;
    return wire;
  }
  wire.until = state.endMode === 'until' ? (state.until ?? null) : null;
  return wire;
}

/** The two axes as the endpoint takes them, or `null` when either cannot be expressed. */
function axesToWire(state: RecurrenceState): CalendarRecurrenceWrite | null {
  const day = dayToWire(state.day);
  const month = monthToWire(state.month);
  if (!day || !month) return null;
  return { day, month: month.value };
}

/**
 * Whether the event's own start day is an occurrence of the rule the form currently holds —
 * the client-side reading of `anchor_not_an_occurrence` (fact 1).
 *
 * THREE CASES ANSWER `true` WITHOUT LOOKING AT THE CADENCE AT ALL, and each is a case where
 * this form has no standing to refuse:
 *   • it does not repeat — there is no cadence to contradict the anchor;
 *   • the rule is one this editor is only ECHOING (fact 6) — untouched by definition;
 *   • the cadence is exactly what was LOADED. The server accepted it against the series' own
 *     stamped clock, which is not always the clock this form reads its anchor on, so
 *     re-judging it here would block a title edit over a rule nothing is wrong with.
 */
export function recurrenceAnchorSatisfied(state: RecurrenceState, anchorDay: IsoDay | null): boolean {
  if (!state.repeats || state.unsupported) return true;
  if (state.accepted && sameAxes(state.accepted, { day: state.day, month: state.month })) return true;
  return anchorSatisfies(state.day, state.month, anchorDay);
}

/** Structural equality for a cadence — small, closed objects of scalars and number lists. */
function sameAxes(a: { day: DayAxis; month: MonthAxis }, b: { day: DayAxis; month: MonthAxis }): boolean {
  return JSON.stringify(a) === JSON.stringify(b);
}
