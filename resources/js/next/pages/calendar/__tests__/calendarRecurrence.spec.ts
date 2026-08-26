// calendarRecurrence.spec — the pure half of the repeat control, where the expensive mistakes
// live.
//
// Everything asserted here fails SILENTLY in production if it slips: the payload still saves,
// or it 422s on a field the user does not connect with the control they touched.
//
//   • THE EDITOR CANNOT COMPOSE A RULE THE SERVER REFUSES. The Calendar profile is checked
//     against the endpoint's own admitted subset, mode for mode. This is what replaced the old
//     preset list, and it is the reason the "Another rule" state has almost nothing left to do.
//   • THE ANCHOR ALWAYS SATISFIES ITS OWN RULE ON THE ORDINARY PATH. The server refuses
//     otherwise, ON THE START FIELD (`anchor_not_an_occurrence`). The sweep below is what turns
//     that refusal from "unlikely" into "unreachable unless deliberately composed".
//   • A `special` CARRIES ONLY ITS OWN PARAMS. `allowedParams()` gives `last_day` NOTHING; a
//     spare `ordinal` is `field_not_allowed_for_mode`, on a rule that otherwise looks fine.
//   • "DOES NOT REPEAT" IS THE ABSENCE OF THE KEY, NOT AN EMPTY BLOCK. `{day: null}` is
//     `filled()` server-side and compiles to "every day, forever" — a series nobody asked for.
//   • `until` AND `count` NEVER TRAVEL TOGETHER (422 `end_is_one_thing`), and nothing here
//     keeps a counter: a count is a way of saying a date, and only the date ever comes back.
import { describe, it, expect } from 'vitest';

import {
  emptyRecurrenceState,
  recurrenceAnchorSatisfied,
  recurrenceStateFrom,
  recurrenceStateToWire,
  type RecurrenceState,
} from '../calendarRecurrence';
import {
  CALENDAR_RECURRENCE_PROFILE,
  WORKFLOW_SCHEDULE_PROFILE,
  anchorSatisfies,
  seedDay,
  seedMonth,
  type DayAxis,
} from '../../../ui/recurrence/recurrenceAxes';
import { toIsoDate } from '../../../ui/forms/date/dateCore';
import type { CalendarRecurrenceRule } from '../types';

function rule(over: Partial<CalendarRecurrenceRule> = {}): CalendarRecurrenceRule {
  return { day: null, month: null, exclusions: null, until: null, ...over };
}

/** A repeating state on a given cadence, with no end. */
function repeating(over: Partial<RecurrenceState> = {}): RecurrenceState {
  return { ...emptyRecurrenceState(), repeats: true, ...over };
}

describe('the Calendar profile IS the endpoint’s admitted subset', () => {
  /**
   * Read from the rules, not assumed: `CalendarRecurrence::dayModes()` / `daySpecials()` /
   * `monthModes()` in `app/modules/Calendar/DTOs`, with the refusals pinned server-side in
   * `CalendarRecurrenceGrammarTest`. If the backend widens, this is the line that should go
   * red first — a profile lagging the contract is a feature quietly missing, and a profile
   * AHEAD of it is a 422 the user meets instead of a control that refuses.
   */
  it('offers exactly the day sub-modes the Calendar accepts — and no others', () => {
    expect(CALENDAR_RECURRENCE_PROFILE.dayModes).toEqual([
      'every_day',
      'weekdays',
      'month_days',
      'last_day',
      'weekday_in_month',
    ]);
  });

  it('offers exactly the month sub-modes the Calendar accepts', () => {
    expect(CALENDAR_RECURRENCE_PROFILE.monthModes).toEqual(['every_month', 'months']);
  });

  it('hides the three shapes the Calendar refuses, each for its own stated reason', () => {
    // `every_n_days` / `every_n_months`: a modulo grid RESETS every month and every year, so
    // the cadence means something other than it says — refused on `recurrence.<axis>.mode`.
    expect(CALENDAR_RECURRENCE_PROFILE.dayModes).not.toContain('every_n_days');
    expect(CALENDAR_RECURRENCE_PROFILE.monthModes).not.toContain('every_n_months');
    // `last_working_day`: refused on `recurrence.day.special`.
    expect(CALENDAR_RECURRENCE_PROFILE.dayModes).not.toContain('last_working_day');
    // The whole TIME axis: `recurrence.time` is `prohibited` — a repeating event happens at
    // the event's own hour, which the server stamps itself.
    expect(CALENDAR_RECURRENCE_PROFILE.axes).toEqual(['day', 'month']);
  });

  /**
   * The window (`from`/`to`) rides on the two modulo modes and nowhere else, so hiding them is
   * the whole statement about windows — there is no second switch to forget.
   */
  it('needs no separate statement about the od–do window: it rides on the hidden modes', () => {
    const windowed = ['every_n_days', 'every_n_months'];
    for (const mode of windowed) {
      expect([...CALENDAR_RECURRENCE_PROFILE.dayModes, ...CALENDAR_RECURRENCE_PROFILE.monthModes]).not.toContain(mode);
    }
  });

  it('leaves the Workflows profile speaking the whole grammar', () => {
    // The containment is one-way by design, and the Workflows side must not narrow with it.
    expect(WORKFLOW_SCHEDULE_PROFILE.axes).toEqual(['time', 'day', 'month']);
    expect(WORKFLOW_SCHEDULE_PROFILE.dayModes).toContain('every_n_days');
    expect(WORKFLOW_SCHEDULE_PROFILE.dayModes).toContain('last_working_day');
    expect(WORKFLOW_SCHEDULE_PROFILE.monthModes).toContain('every_n_months');
  });
});

describe('the editor can express every rule the endpoint returns', () => {
  /**
   * THE REASON THE "ANOTHER RULE" STATE COULD GO. The old control was NARROWER than the
   * grammar — one weekday, one day of the month — so a rule with two weekdays had to be
   * frozen and echoed. The axis editor is exactly as wide as the endpoint, so every rule that
   * can be STORED round-trips through the editor with `unsupported` staying null.
   */
  const wide: CalendarRecurrenceRule[] = [
    rule({ day: { mode: 'every_day' } }),
    rule({ day: { mode: 'weekdays', weekdays: [1, 3] } }),
    rule({ day: { mode: 'weekdays', weekdays: [0, 6] } }),
    rule({ day: { mode: 'month_days', days: [1, 15, 31] } }),
    rule({ day: { mode: 'special', special: 'last_day' } }),
    rule({ day: { mode: 'special', special: 'nth_weekday', ordinal: 2, weekday: 4 } }),
    rule({ day: { mode: 'special', special: 'last_weekday', weekday: 5 } }),
    rule({ month: { mode: 'months', months: [3, 6, 9, 12] } }),
    rule({
      day: { mode: 'month_days', days: [25] },
      month: { mode: 'months', months: [8] },
    }),
  ];

  it.each(wide.map((r) => [JSON.stringify(r.day ?? r.month), r] as const))(
    'round-trips %s with no frozen state',
    (_label, stored) => {
      const state = recurrenceStateFrom(stored);
      expect(state.unsupported).toBeNull();
      expect(state.repeats).toBe(true);

      const wire = recurrenceStateToWire(state);
      expect(wire?.day).toEqual(stored.day ?? { mode: 'every_day' });
      expect(wire?.month ?? null).toEqual(stored.month ?? null);
    },
  );

  it('reads an ABSENT axis as "every day" / "every month", the way the server does', () => {
    const state = recurrenceStateFrom(rule());
    expect(state.day).toEqual({ mode: 'every_day' });
    expect(state.month).toEqual({ mode: 'every_month' });
    expect(state.unsupported).toBeNull();
  });

  it('compares lists by VALUE — a stringified weekday is the same rule', () => {
    const stringy = rule({ day: { mode: 'weekdays', weekdays: ['2' as unknown as number] } });
    expect(recurrenceStateFrom(stringy).day).toEqual({ mode: 'weekdays', weekdays: [2] });
  });
});

describe('a `special` carries only its own params', () => {
  /**
   * `ScheduleDaySpecial::allowedParams()`: `nth_weekday` → ordinal + weekday, `last_weekday` →
   * weekday, `last_day` → NOTHING. A spare key is `field_not_allowed_for_mode` — a 422 on a
   * rule that reads perfectly well, which is exactly the kind that gets shipped.
   */
  it('emits last_day with no ordinal and no weekday', () => {
    const wire = recurrenceStateToWire(
      repeating({ day: { mode: 'special', special: { kind: 'last_day' } } }),
    );
    expect(wire?.day).toEqual({ mode: 'special', special: 'last_day' });
  });

  it('emits last_weekday with a weekday but NO ordinal, even after coming from an nth rule', () => {
    // The editor's ordinal Select unifies {first…fifth, last}, so this is one click apart.
    const wire = recurrenceStateToWire(
      repeating({ day: { mode: 'special', special: { kind: 'last_weekday', weekday: 2 } } }),
    );
    expect(wire?.day).toEqual({ mode: 'special', special: 'last_weekday', weekday: 2 });
    expect(wire?.day).not.toHaveProperty('ordinal');
  });

  it('emits nth_weekday with both', () => {
    const wire = recurrenceStateToWire(
      repeating({ day: { mode: 'special', special: { kind: 'nth_weekday', ordinal: 3, weekday: 1 } } }),
    );
    expect(wire?.day).toEqual({ mode: 'special', special: 'nth_weekday', ordinal: 3, weekday: 1 });
  });
});

describe('the anchor rule', () => {
  /**
   * THE INVARIANT THE WHOLE CONTROL RESTS ON, rebuilt for a full editor: what the editor SEEDS
   * for a day must have that day as one of its own occurrences — checked for every sub-mode
   * the Calendar profile offers, on every single day of a year, month ends and fifth weekdays
   * included.
   */
  it('every seeded sub-mode is satisfied by the day it was seeded from — all of 2026', () => {
    const offenders: string[] = [];
    for (let cursor = new Date(2026, 0, 1); cursor.getFullYear() === 2026; cursor.setDate(cursor.getDate() + 1)) {
      const day = toIsoDate(cursor);
      for (const mode of CALENDAR_RECURRENCE_PROFILE.dayModes) {
        const seeded = seedDay(mode, day);
        // `last_day` is the one sub-mode a user can pick on a day that does not satisfy it —
        // it names a rule rather than deriving one, so it is judged separately below.
        if (mode === 'last_day') continue;
        if (!anchorSatisfies(seeded, { mode: 'every_month' }, day)) offenders.push(`${day} → ${mode}`);
      }
      for (const mode of CALENDAR_RECURRENCE_PROFILE.monthModes) {
        if (!anchorSatisfies({ mode: 'every_day' }, seedMonth(mode, day), day)) {
          offenders.push(`${day} → month:${mode}`);
        }
      }
    }
    expect(offenders).toEqual([]);
  });

  it('the fifth-weekday seed becomes "the last <weekday>", which the anchor does satisfy', () => {
    // 2026-12-29 is the FIFTH Tuesday of December 2026. "Monthly on the 5th Tuesday" is a rule
    // that looks monthly and fires in roughly four months a year; the last-Tuesday reading is
    // both honest and satisfied by the anchor.
    const seeded = seedDay('weekday_in_month', '2026-12-29');
    expect(seeded).toEqual({ mode: 'special', special: { kind: 'last_weekday', weekday: 2 } });
    expect(anchorSatisfies(seeded, { mode: 'every_month' }, '2026-12-29')).toBe(true);
  });

  it('an ordinary mid-month Tuesday seeds the nth-weekday reading instead', () => {
    // 2026-08-25 is the FOURTH Tuesday — the worked example from the UX spec.
    expect(seedDay('weekday_in_month', '2026-08-25')).toEqual({
      mode: 'special',
      special: { kind: 'nth_weekday', ordinal: 4, weekday: 2 },
    });
  });

  it('catches the rule a deliberate edit can still compose — the case the old control could not express', () => {
    // "Every Monday and Wednesday" on a Tuesday start. The old preset control offered one
    // weekday and therefore could not produce this at all; the axis editor can, so the refusal
    // is reported instead of being structurally impossible.
    const twoWeekdays = repeating({ day: { mode: 'weekdays', weekdays: [1, 3] } });
    expect(recurrenceAnchorSatisfied(twoWeekdays, '2026-08-25')).toBe(false);
    // …and adding the anchor's own weekday settles it.
    expect(recurrenceAnchorSatisfied(repeating({ day: { mode: 'weekdays', weekdays: [1, 2, 3] } }), '2026-08-25')).toBe(true);
  });

  it('catches "the last day of the month" anchored on a day that is not one', () => {
    const lastDay: DayAxis = { mode: 'special', special: { kind: 'last_day' } };
    expect(recurrenceAnchorSatisfied(repeating({ day: lastDay }), '2026-08-30')).toBe(false);
    expect(recurrenceAnchorSatisfied(repeating({ day: lastDay }), '2026-08-31')).toBe(true);
  });

  it('catches a month list that excludes the start’s own month', () => {
    expect(recurrenceAnchorSatisfied(repeating({ month: { mode: 'months', months: [3] } }), '2026-08-25')).toBe(false);
    expect(recurrenceAnchorSatisfied(repeating({ month: { mode: 'months', months: [3, 8] } }), '2026-08-25')).toBe(true);
  });

  it('never blocks on a day it cannot read, or on a rule it is only echoing', () => {
    // A half-typed date must not fight the user, and an echoed rule was already accepted by
    // the server with this very anchor.
    expect(recurrenceAnchorSatisfied(repeating({ day: { mode: 'weekdays', weekdays: [1] } }), null)).toBe(true);
    expect(recurrenceAnchorSatisfied(repeating({ day: { mode: 'weekdays', weekdays: [1] } }), 'not-a-day')).toBe(true);
    expect(recurrenceAnchorSatisfied(emptyRecurrenceState(), '2026-08-25')).toBe(true);
  });

  it('turning repeating ON opens on the one cadence every anchor satisfies', () => {
    const fresh = emptyRecurrenceState();
    expect(fresh.day).toEqual({ mode: 'every_day' });
    expect(fresh.month).toEqual({ mode: 'every_month' });
    expect(recurrenceAnchorSatisfied({ ...fresh, repeats: true }, '2026-08-30')).toBe(true);
  });
});

describe('recurrenceStateFrom / recurrenceStateToWire — the round trip', () => {
  it('emits NOTHING for "does not repeat" — the key must be absent, not empty', () => {
    expect(recurrenceStateToWire(emptyRecurrenceState())).toBeNull();
  });

  it('echoes a rule the profile cannot render byte for byte, rather than flattening it', () => {
    // Not reachable through any supported write path — the endpoint refuses `every_n_days` at
    // the request rules AND at the DTO. It is asserted because the alternative failure is
    // silent: a title edit that quietly reshapes somebody's cadence.
    const stored = rule({
      day: { mode: 'every_n_days' as never, n: 3 } as never,
      exclusions: { dates: ['2026-09-02'] },
      until: '2026-12-31',
    });
    const state = recurrenceStateFrom(stored);
    expect(state.unsupported).toBe(stored);

    const wire = recurrenceStateToWire(state);
    expect(wire?.day).toEqual(stored.day);
    // Carried, never authored: dropping it would resurrect a day somebody deleted.
    expect(wire?.exclusions).toEqual({ dates: ['2026-09-02'] });
    expect(wire?.until).toBe('2026-12-31');
  });

  it('never brings a count back — a stored series can only end on a DATE', () => {
    const state = recurrenceStateFrom(rule({ day: { mode: 'every_day' }, until: '2026-09-30' }));
    expect(state.endMode).toBe('until');
    expect(state.count).toBeNull();
  });

  it('sends `count` alone, and `until` alone — never both', () => {
    const base = recurrenceStateFrom(rule({ day: { mode: 'every_day' } }));

    const counted = recurrenceStateToWire({ ...base, endMode: 'count', count: 10 });
    expect(counted?.count).toBe(10);
    expect(counted).not.toHaveProperty('until');

    const dated = recurrenceStateToWire({ ...base, endMode: 'until', until: '2026-10-01' });
    expect(dated?.until).toBe('2026-10-01');
    expect(dated).not.toHaveProperty('count');
  });

  it('clears a previous end explicitly when the user chooses "never"', () => {
    const state = recurrenceStateFrom(rule({ day: { mode: 'every_day' }, until: '2026-09-30' }));
    const wire = recurrenceStateToWire({ ...state, endMode: 'never' });
    // Explicit null rather than omission: a whole-event write rebuilds the descriptor, so the
    // null is what actually removes the end.
    expect(wire?.until).toBeNull();
  });

  it('carries exclusions through an ordinary cadence edit', () => {
    const state = recurrenceStateFrom(
      rule({ day: { mode: 'weekdays', weekdays: [2] }, exclusions: { dates: ['2026-09-01'] } }),
    );
    const edited = { ...state, day: { mode: 'weekdays', weekdays: [2, 4] } as DayAxis };
    expect(recurrenceStateToWire(edited)?.exclusions).toEqual({ dates: ['2026-09-01'] });
  });

  it('omits exclusions entirely when there are none — an empty list is not the same as none', () => {
    expect(recurrenceStateToWire(repeating())).not.toHaveProperty('exclusions');
  });
});
