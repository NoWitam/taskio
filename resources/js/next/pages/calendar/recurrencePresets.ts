// recurrencePresets — the ONE place that turns a calendar date into the handful of repeat
// rules a person planning a meeting actually says out loud, and back again.
//
// Pure, Vue-free and i18n-free at its core (labels take a `t` you hand in), because every
// single rule below fails SILENTLY when it is wrong: the payload still saves, or it still
// 422s on a field the user does not connect with the control they touched.
//
// ─────────────────────────────────────────────────────────────────────────────────────────
// FIVE FACTS OF THE WRITE CONTRACT THIS FILE EXISTS TO KEEP TRUE
// ─────────────────────────────────────────────────────────────────────────────────────────
//
// 1. THE ANCHOR MUST BE THE RULE'S FIRST OCCURRENCE. The server checks it
//    (`anchor_not_an_occurrence`, reported on `start_date`/`starts_at` — a field the user
//    does not associate with "repeat weekly"). Every preset here is therefore DERIVED FROM
//    THE START DAY, and re-derived whenever that day changes (`remapPreset`). A preset that
//    a date cannot satisfy is not offered at all, which is what makes that 422 unreachable
//    from this UI rather than merely unlikely.
//
// 2. THE SUBSET IS NARROW ON PURPOSE. The API accepts more than this (several weekdays,
//    several days of the month, a list of months, `last_weekday` unconditionally). The
//    control offers less — see the UX spec §24.5.4 for each omission and why. The gap is
//    not a bug, it is the reason `presetOf()` can return `null`: a rule this control cannot
//    PRODUCE must never be silently rewritten into the nearest one it can.
//
// 3. "N TIMES" DOES NOT COME BACK AS "N TIMES". `recurrence.count` is walked to a real day
//    once, at write time; only `until` is ever persisted, and only `until` ever returns.
//    `RecurrenceState` therefore has an `endMode` that can be `count` on the way OUT and can
//    only ever be `never`/`until` on the way IN. Nothing here keeps a counter (see L11).
//
// 4. `exclusions` IS CARRIED, NEVER AUTHORED. It grows only through "delete this one
//    occurrence", server-side. A whole-event `PUT` that drops it RESURRECTS every day
//    somebody removed one at a time, so it is copied through verbatim.
//
// 5. AN ABSENT `recurrence` KEY MEANS "HAPPENS ONCE"; AN EMPTY-ISH OBJECT DOES NOT.
//    `{day: null, month: null}` is `filled()` server-side and compiles to "every day,
//    forever" — a series nobody asked for. `recurrenceStateToWire` returns `null` for "does
//    not repeat" so the caller can OMIT the key rather than send an empty one.
import { daysInMonth, fromIsoDate } from '../../ui/forms/date/dateCore';
import type {
  CalendarRecurrenceDayAxis,
  CalendarRecurrenceMonthAxis,
  CalendarRecurrenceRule,
  CalendarRecurrenceWrite,
  IsoDay,
} from './types';

/** The presets this control offers. Deliberately fewer than the API accepts (fact 2). */
export type RecurrencePresetId =
  | 'daily'
  | 'weekly'
  | 'monthlyDay'
  | 'monthlyNth'
  | 'monthlyLastDay'
  | 'monthlyLastWeekday'
  | 'yearly';

/** What the select holds: nothing, one of the presets, or a rule it cannot express. */
export type RecurrenceSelection = 'none' | 'other' | RecurrencePresetId;

/**
 * One preset, already bound to the date it was derived from — so it carries the concrete
 * weekday / day-of-month / ordinal / month that go on the wire, not a shape to fill in later.
 */
export interface RecurrencePreset {
  id: RecurrencePresetId;
  /** `0..6`, 0 = Sunday (the shared engine's convention). Weekday-shaped presets only. */
  weekday?: number;
  /** `1..31`. Day-of-month-shaped presets only. */
  dayOfMonth?: number;
  /** `1..5`. `monthlyNth` only. */
  ordinal?: number;
  /** `1..12`. `yearly` only. */
  month?: number;
  /**
   * True when this rule genuinely skips months that are too short for it — a fact the
   * engine simply does not fire on, so the control has to SAY it or the missing squares
   * read as lost occurrences.
   */
  skipsShortMonths?: boolean;
}

/** The two cadence axes of a rule, as the write endpoint accepts them. */
export interface RecurrenceAxes {
  day: CalendarRecurrenceDayAxis | null;
  month: CalendarRecurrenceMonthAxis | null;
}

/** How a series ends. `count` exists only on the way out — see fact 3. */
export type RecurrenceEndMode = 'never' | 'until' | 'count';

/**
 * Everything the repeat control holds. Kept as ONE object so the drawer can seed it, hand it
 * to the field, and turn it back into a payload without three refs drifting apart.
 */
export interface RecurrenceState {
  selection: RecurrenceSelection;
  endMode: RecurrenceEndMode;
  until: IsoDay | null;
  count: number | null;
  /** Carried verbatim from the GET. The form never builds or clears it (fact 4). */
  exclusions: IsoDay[] | null;
  /**
   * The rule as it was READ, kept so a rule outside this control's vocabulary can be echoed
   * back byte for byte instead of being rewritten into the nearest preset (fact 2).
   */
  stored: CalendarRecurrenceRule | null;
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

/** A minimal `t()` — passed in so this module stays testable without the i18n singleton. */
export type Translate = (
  key: string,
  defaultValue?: string,
  params?: Record<string, string | number>,
) => string;

// ── Deriving the presets from a date ─────────────────────────────────────────

/**
 * Every preset the given start day can legally anchor, in the order the select shows them.
 *
 * WHAT DECIDES WHETHER A PRESET IS IN THE LIST is fact 1 and nothing else: the day has to be
 * the rule's own first occurrence. That is why `monthlyLastDay` appears only for a day that
 * IS its month's last, and why the fifth-weekday case swaps rather than adds (below).
 */
export function presetsFor(day: IsoDay | null): RecurrencePreset[] {
  const date = fromIsoDate(day);
  if (!date) return [];

  const weekday = date.getDay();
  const dayOfMonth = date.getDate();
  const month = date.getMonth() + 1;
  const ordinal = Math.floor((dayOfMonth - 1) / 7) + 1;
  const lengthOfMonth = daysInMonth(date.getFullYear(), date.getMonth());

  const presets: RecurrencePreset[] = [
    { id: 'daily' },
    { id: 'weekly', weekday },
    { id: 'monthlyDay', dayOfMonth, skipsShortMonths: dayOfMonth >= 29 },
    // THE FIFTH-WEEKDAY SWAP, and it is a swap rather than an extra option. "Monthly on the
    // 5th Tuesday" is a rule that does not fire in most months while LOOKING monthly;
    // somebody who pointed at the last Tuesday of a month almost certainly meant the LAST
    // one, and `last_weekday` is inside the accepted subset. Offering both would make the
    // honest one compete with the misleading one.
    ordinal === 5
      ? { id: 'monthlyLastWeekday', weekday }
      : { id: 'monthlyNth', ordinal, weekday },
  ];

  // Only a day that IS its month's last can anchor "every month, on the last day" — anything
  // else would be a guaranteed 422 on the START field (fact 1).
  if (dayOfMonth === lengthOfMonth) presets.push({ id: 'monthlyLastDay' });

  presets.push({ id: 'yearly', dayOfMonth, month });

  return presets;
}

/** The preset with this id for this day, or null when the day cannot anchor it. */
export function presetById(id: RecurrencePresetId, day: IsoDay | null): RecurrencePreset | null {
  return presetsFor(day).find((preset) => preset.id === id) ?? null;
}

/**
 * The same CHOICE, re-derived for a new start day.
 *
 * This is the mechanism that makes `anchor_not_an_occurrence` unreachable (fact 1): pick "on
 * every Tuesday", move the date to a Wednesday, and the select visibly redraws as "on every
 * Wednesday" instead of the save being refused on a field nobody connects with the rule.
 * The redraw is VISIBLE on purpose — a silent substitution would be worse than the refusal.
 *
 * The two shapes that cannot always survive get their nearest honest neighbour rather than
 * disappearing: the fifth-weekday pair trade places, and "the last day of the month" becomes
 * "day N" when the new date is not a month's last. Both changes are readable in the select.
 */
export function remapPreset(
  selection: RecurrencePresetId,
  day: IsoDay | null,
): RecurrencePreset | null {
  const available = presetsFor(day);
  const direct = available.find((preset) => preset.id === selection);
  if (direct) return direct;

  const fallbackId: Partial<Record<RecurrencePresetId, RecurrencePresetId>> = {
    monthlyNth: 'monthlyLastWeekday',
    monthlyLastWeekday: 'monthlyNth',
    monthlyLastDay: 'monthlyDay',
  };
  const fallback = fallbackId[selection];
  return fallback ? (available.find((preset) => preset.id === fallback) ?? null) : null;
}

// ── Preset ⇄ descriptor ──────────────────────────────────────────────────────

/** The two axes this preset compiles to. Exactly the subset `CalendarRecurrence` accepts. */
export function descriptorOf(preset: RecurrencePreset): RecurrenceAxes {
  switch (preset.id) {
    case 'daily':
      return { day: { mode: 'every_day' }, month: null };
    case 'weekly':
      return { day: { mode: 'weekdays', weekdays: [preset.weekday ?? 0] }, month: null };
    case 'monthlyDay':
      return { day: { mode: 'month_days', days: [preset.dayOfMonth ?? 1] }, month: null };
    case 'monthlyNth':
      return {
        day: {
          mode: 'special',
          special: 'nth_weekday',
          ordinal: preset.ordinal ?? 1,
          weekday: preset.weekday ?? 0,
        },
        month: null,
      };
    case 'monthlyLastDay':
      return { day: { mode: 'special', special: 'last_day' }, month: null };
    case 'monthlyLastWeekday':
      return {
        day: { mode: 'special', special: 'last_weekday', weekday: preset.weekday ?? 0 },
        month: null,
      };
    case 'yearly':
      return {
        day: { mode: 'month_days', days: [preset.dayOfMonth ?? 1] },
        month: { mode: 'months', months: [preset.month ?? 1] },
      };
  }
}

/** An absent axis means "every day" / "every month" — the server reads it that way too. */
function canonicalDay(axis: CalendarRecurrenceDayAxis | null | undefined): string {
  const day = axis ?? { mode: 'every_day' as const };
  return JSON.stringify([
    day.mode ?? 'every_day',
    numbers(day.weekdays),
    numbers(day.days),
    day.special ?? null,
    day.ordinal == null ? null : Number(day.ordinal),
    day.weekday == null ? null : Number(day.weekday),
  ]);
}

function canonicalMonth(axis: CalendarRecurrenceMonthAxis | null | undefined): string {
  const month = axis ?? { mode: 'every_month' as const };
  return JSON.stringify([month.mode ?? 'every_month', numbers(month.months)]);
}

/** Lists compare by VALUE, ascending and numeric — `["2"]` and `[2]` are the same rule. */
function numbers(list: unknown): number[] | null {
  if (!Array.isArray(list)) return null;
  return list.map((value) => Number(value)).sort((a, b) => a - b);
}

/**
 * Which preset (if any) a STORED rule is, judged against the presets its own anchor day can
 * produce.
 *
 * `null` is a first-class answer and means "Another rule": the API accepts a wider grammar
 * than this control speaks, and a rule that came from the API, from the `create_event`
 * workflow step or from a future, wider control must be echoed back untouched. Matching
 * against `presetsFor(anchorDay)` rather than against shapes in the abstract is what
 * guarantees the round trip: whatever this says YES to, the control can also re-produce.
 */
export function presetOf(
  rule: CalendarRecurrenceRule | null,
  anchorDay: IsoDay | null,
): RecurrencePreset | null {
  if (!rule) return null;
  const day = canonicalDay(rule.day);
  const month = canonicalMonth(rule.month);
  return (
    presetsFor(anchorDay).find((preset) => {
      const axes = descriptorOf(preset);
      return canonicalDay(axes.day) === day && canonicalMonth(axes.month) === month;
    }) ?? null
  );
}

// ── State ⇄ wire ─────────────────────────────────────────────────────────────

/** "Does not repeat", the shape a create starts from. */
export function emptyRecurrenceState(): RecurrenceState {
  return {
    selection: 'none',
    endMode: 'never',
    until: null,
    count: null,
    exclusions: null,
    stored: null,
  };
}

/**
 * Seed the control from a rule the server returned.
 *
 * `endMode` can only ever come back as `never` or `until` — never `count`, because a count
 * is resolved to a day at write time and the row has no column to remember it by (fact 3).
 */
export function recurrenceStateFrom(
  rule: CalendarRecurrenceRule | null,
  anchorDay: IsoDay | null,
): RecurrenceState {
  if (!rule) return emptyRecurrenceState();
  const preset = presetOf(rule, anchorDay);
  const dates = rule.exclusions?.dates ?? null;
  return {
    selection: preset?.id ?? 'other',
    endMode: rule.until ? 'until' : 'never',
    until: rule.until ?? null,
    count: null,
    exclusions: dates && dates.length > 0 ? [...dates] : null,
    stored: rule,
  };
}

/**
 * The `recurrence` block for the write endpoint, or `null` when the caller must OMIT the key
 * entirely (fact 5 — an empty-ish object compiles to "every day, forever" server-side).
 *
 * `until` and `count` are mutually exclusive on the wire (422 `end_is_one_thing`), so exactly
 * one of them is ever emitted; "never" emits an explicit `until: null`, which CLEARS an end a
 * previous save set, because a whole-event write rebuilds the descriptor from what it is given.
 */
export function recurrenceStateToWire(
  state: RecurrenceState,
  anchorDay: IsoDay | null,
): CalendarRecurrenceWrite | null {
  if (state.selection === 'none') return null;

  const axes = axesFor(state, anchorDay);
  if (!axes) return null;

  const wire: CalendarRecurrenceWrite = { day: axes.day, month: axes.month };

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

/**
 * The axes a state compiles to. A rule outside the control's vocabulary keeps the axes it was
 * READ with — the whole point of the "Another rule" state.
 */
function axesFor(state: RecurrenceState, anchorDay: IsoDay | null): RecurrenceAxes | null {
  if (state.selection === 'other') {
    return state.stored ? { day: state.stored.day, month: state.stored.month } : null;
  }
  if (state.selection === 'none') return null;
  const preset = presetById(state.selection, anchorDay) ?? remapPreset(state.selection, anchorDay);
  return preset ? descriptorOf(preset) : (state.stored ? { day: state.stored.day, month: state.stored.month } : null);
}

// ── Labels ───────────────────────────────────────────────────────────────────

/**
 * The label for one preset, in the reader's language.
 *
 * THESE LABEL AN INTENT, NEVER A STORED RULE. A rule that already exists gets its sentence
 * from the SERVER (`cadence_label` on an occurrence, `recurrence_label` on the event) — this
 * client composes no prose about a cadence anywhere. What it composes here is the wording of
 * a CHOICE, which the server has no opinion about because the rule does not exist yet.
 *
 * Weekdays and "the last X" get a catalogue entry EACH rather than a `{weekday}` placeholder,
 * for the same reason the server's own `lang/{pl,en}/calendar.php` does it: Polish inflects
 * ("W każdy wtorek" but "W każdą środę"), and a language that inflects cannot compose the
 * phrase from a name plus a slot. The date in the yearly label goes through `Intl` for the
 * same reason — "25 sierpnia", never "25 sierpień".
 */
export function presetLabel(preset: RecurrencePreset, t: Translate, locale: string): string {
  switch (preset.id) {
    case 'daily':
      return t('calendar.recurrence.daily');
    case 'weekly':
      return t(`calendar.recurrence.weeklyDays.${preset.weekday ?? 0}`);
    case 'monthlyDay':
      return t('calendar.recurrence.monthlyDay', '', { day: preset.dayOfMonth ?? 1 });
    case 'monthlyNth':
      return t('calendar.recurrence.monthlyNth', '', {
        ordinal: t(`calendar.recurrence.ordinals.${preset.ordinal ?? 1}`),
        weekday: weekdayName(preset.weekday ?? 0, locale),
      });
    case 'monthlyLastDay':
      return t('calendar.recurrence.monthlyLastDay');
    case 'monthlyLastWeekday':
      return t(`calendar.recurrence.monthlyLastWeekdays.${preset.weekday ?? 0}`);
    case 'yearly':
      return t('calendar.recurrence.yearly', '', {
        date: dayMonthLabel(preset.dayOfMonth ?? 1, preset.month ?? 1, locale),
      });
  }
}

/** A weekday's own name, `0` = Sunday. */
function weekdayName(weekday: number, locale: string): string {
  // 2024-01-07 is a Sunday, so adding the weekday index lands on that weekday exactly.
  const date = new Date(2024, 0, 7 + (((weekday % 7) + 7) % 7));
  return new Intl.DateTimeFormat(locale, { weekday: 'long' }).format(date);
}

/** "25 sierpnia" / "August 25" — the genitive is `Intl`'s problem, not a catalogue's. */
function dayMonthLabel(dayOfMonth: number, month: number, locale: string): string {
  // 2024 is a leap year, so every (month, day) pair a preset can carry — 29 February
  // included — formats without rolling into the next month.
  const date = new Date(2024, month - 1, dayOfMonth);
  return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long' }).format(date);
}
