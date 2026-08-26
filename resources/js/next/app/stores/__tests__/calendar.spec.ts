// calendar store spec — the pure exports (where the write contract lives) plus the one action
// whose URL is a contract of its own.
//
// `buildEventPayload` is the riskiest function in the module: three invariants meet in it
// and all three fail SILENTLY when broken —
//   • an omitted key on a whole-event PUT CLEARS a column (there is no patch semantics),
//   • the unused time group is FORBIDDEN, not ignored (a stray key is a 422),
//   • a moment without an explicit offset is stored in UTC and drawn in the workspace zone.
import { beforeEach, describe, it, expect, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

import { api } from '../../lib/api';
import {
  buildEventPayload,
  serializeWindowQuery,
  useCalendarStore,
  type CalendarEventDraft,
} from '../calendar';
import type { CalendarEvent } from '../../../pages/calendar/types';

function draft(overrides: Partial<CalendarEventDraft> = {}): CalendarEventDraft {
  return {
    title: 'Recording',
    description: '',
    all_day: false,
    start_date: '2026-08-09',
    starts_day: '2026-08-09',
    starts_time: '14:30',
    ends_day: null,
    ends_time: null,
    ...overrides,
  };
}

function existingEvent(overrides: Partial<CalendarEvent> = {}): CalendarEvent {
  return {
    id: 'evt-1',
    title: 'Recording',
    description: 'notes',
    all_day: false,
    start_date: null,
    starts_at: '2026-08-09T12:30:00.000000Z',
    ends_at: null,
    recurrence: null,
    recurrence_timezone: null,
    recurrence_label: null,
    subject: null,
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

describe('serializeWindowQuery', () => {
  it('sends `from`/`to` and repeats `sources[]` per value', () => {
    const params = serializeWindowQuery({
      from: '2026-07-27',
      to: '2026-09-06',
      sources: ['task', 'event'],
      q: 'kampania',
    });
    expect(params.getAll('sources[]')).toEqual(['task', 'event']);
    expect(params.get('from')).toBe('2026-07-27');
    expect(params.get('to')).toBe('2026-09-06');
    expect(params.get('q')).toBe('kampania');
  });

  it('omits `sources` entirely when nothing is selected — which means ALL sources', () => {
    const params = serializeWindowQuery({ from: '2026-07-27', to: '2026-09-06', sources: [] });
    expect(params.getAll('sources[]')).toEqual([]);
    expect(params.has('sources[]')).toBe(false);
  });

  it('omits a blank search rather than sending an empty `q`', () => {
    const params = serializeWindowQuery({ from: '2026-07-27', to: '2026-09-06', q: '   ' });
    expect(params.has('q')).toBe(false);
  });

  it('never sends a `tz` param — the server owns the zone', () => {
    const params = serializeWindowQuery({ from: '2026-07-27', to: '2026-09-06' });
    expect(params.has('tz')).toBe(false);
  });
});

describe('buildEventPayload — the all-day discriminator', () => {
  it('sends ONLY the day for an all-day event; the timed keys are absent, not empty', () => {
    // `validateShapeInTime` reads PRESENCE, and a present `starts_at` on an all-day payload
    // is a 422 naming a field the author is no longer looking at.
    const payload = buildEventPayload(draft({ all_day: true }), null, 'Europe/Warsaw');
    expect(payload.all_day).toBe(true);
    expect(payload.start_date).toBe('2026-08-09');
    expect('starts_at' in payload).toBe(false);
    expect('ends_at' in payload).toBe(false);
  });

  it('sends ONLY the moments for a timed event; the day key is absent', () => {
    const payload = buildEventPayload(draft(), null, 'Europe/Warsaw');
    expect(payload.all_day).toBe(false);
    expect('start_date' in payload).toBe(false);
    expect(payload.starts_at).toBe('2026-08-09T14:30:00+02:00');
  });

  it('keeps BOTH groups in the draft, so flipping the switch loses nothing', () => {
    // The draft still carries a start time while all-day is on; it simply does not ship.
    const both = draft({ all_day: true, starts_time: '14:30' });
    expect(buildEventPayload(both, null, 'Europe/Warsaw').start_date).toBe('2026-08-09');
    expect(buildEventPayload({ ...both, all_day: false }, null, 'Europe/Warsaw').starts_at).toBe(
      '2026-08-09T14:30:00+02:00',
    );
  });
});

describe('buildEventPayload — moments carry an explicit offset', () => {
  it('serialises the wall clock in the WORKSPACE zone, with the offset spelled out', () => {
    const payload = buildEventPayload(draft(), null, 'Europe/Warsaw');
    // Not `2026-08-09T14:30` — that is parsed as UTC server-side and drawn two hours late.
    expect(payload.starts_at).toMatch(/[+-]\d{2}:\d{2}$/);
    expect(payload.starts_at).toBe('2026-08-09T14:30:00+02:00');
  });

  it('uses the same zone for the end, and sends an explicit null when there is none', () => {
    const withEnd = buildEventPayload(
      draft({ ends_day: '2026-08-09', ends_time: '15:45' }),
      null,
      'Europe/Warsaw',
    );
    expect(withEnd.ends_at).toBe('2026-08-09T15:45:00+02:00');

    // A whole-event write: null is how "no end" is expressed, and it CLEARS a stored one.
    expect(buildEventPayload(draft(), null, 'Europe/Warsaw').ends_at).toBeNull();
  });

  it('treats a half-filled end (a day with no time) as no end at all', () => {
    const payload = buildEventPayload(draft({ ends_day: '2026-08-09', ends_time: null }), null, 'UTC');
    expect(payload.ends_at).toBeNull();
  });
});

describe('buildEventPayload — the whole-event write', () => {
  it('CARRIES the subject pointer over from the GET, unmodified', () => {
    // The one field the form does not own. Dropping it silently severs a workflow run's
    // link to the event it created — `CalendarEventService` writes every column every time.
    const existing = existingEvent({ subject: { type: 'task', id: 'task-9' } });
    const payload = buildEventPayload(draft(), existing, 'Europe/Warsaw');
    expect(payload.subject_type).toBe('task');
    expect(payload.subject_id).toBe('task-9');
  });

  it('omits the pointer keys entirely when the event never had one', () => {
    const payload = buildEventPayload(draft(), existingEvent({ subject: null }), 'UTC');
    expect('subject_type' in payload).toBe(false);
    expect('subject_id' in payload).toBe(false);
  });

  it('sends description as null when blank, so an emptied field really empties', () => {
    expect(buildEventPayload(draft({ description: '   ' }), null, 'UTC').description).toBeNull();
    expect(buildEventPayload(draft({ description: 'hi' }), null, 'UTC').description).toBe('hi');
  });

  /**
   * NO COLOUR ON THE WIRE — an absence, asserted, because it is the contract.
   *
   * Colour on this screen is a dictionary of MEANINGS: a task deadline is coloured by its
   * priority, a workflow run by its result, a schedule by the one colour that says "this is
   * a projection, not a fact". An event used to let a human pick from the same six values,
   * and the pick meant nothing — so in one grid red said "urgent", "failed" and nothing at
   * all. The choice is gone from the drawer AND from the write surface; an event's colour is
   * the server's to assign. A `color` key reappearing here is a picker growing back.
   */
  it('sends NO colour at all — the write surface has none', () => {
    for (const payload of [
      buildEventPayload(draft(), null, 'UTC'),
      buildEventPayload(draft({ all_day: true }), existingEvent(), 'Europe/Warsaw'),
    ]) {
      expect('color' in payload).toBe(false);
    }
  });

  it('trims the title', () => {
    expect(buildEventPayload(draft({ title: '  Recording  ' }), null, 'UTC').title).toBe('Recording');
  });
});

/**
 * THE MIXED PAYLOAD — one side zoned, the other not.
 *
 * The server has a hole nailed shut for this shape (`CalendarEventTest::
 * test_a_mixed_zone_pair_that_is_actually_inverted_is_refused`): `after_or_equal:starts_at`
 * parses BOTH sides in the app timezone, so `10:00Z` + a bare `11:00` in a UTC+2 workspace
 * passed validation while genuinely ending an hour BEFORE it began. The ordering check was
 * moved onto the resolved instants because of it.
 *
 * That is the server's guard. This is the client's half of the same contract: it must never
 * PRODUCE such a payload in the first place. Both moments come out of the same function, in
 * the same zone, on the same call — so the guard never has to fire on anything this UI sends.
 */
describe('buildEventPayload — never emits a mixed-zone pair', () => {
  it('gives BOTH ends an explicit offset, from the same zone, on every write', () => {
    for (const zone of ['Europe/Warsaw', 'America/New_York', 'Asia/Kolkata', 'UTC']) {
      const payload = buildEventPayload(
        draft({ starts_time: '10:00', ends_day: '2026-08-09', ends_time: '11:00' }),
        null,
        zone,
      );

      expect(payload.starts_at).toMatch(/[+-]\d{2}:\d{2}$/);
      expect(payload.ends_at).toMatch(/[+-]\d{2}:\d{2}$/);

      // The SAME offset on both ends — the pair is one interval in one zone, never two
      // readings of two clocks.
      const offsetOf = (iso: string): string => iso.slice(-6);
      expect(offsetOf(String(payload.ends_at))).toBe(offsetOf(String(payload.starts_at)));

      // And the interval is genuinely forward once resolved, which is what the server checks.
      expect(new Date(String(payload.ends_at)).getTime()).toBeGreaterThan(
        new Date(String(payload.starts_at)).getTime(),
      );
    }
  });

  it('keeps both ends zoned even when they straddle a DST transition', () => {
    // 01:30 → 03:30 local on the Warsaw spring-forward day: the two ends legitimately carry
    // DIFFERENT offsets, and both must still be explicit. A bare end here would be read by
    // the server in UTC and could invert the pair outright.
    const payload = buildEventPayload(
      draft({
        starts_day: '2026-03-29',
        starts_time: '01:30',
        ends_day: '2026-03-29',
        ends_time: '03:30',
      }),
      null,
      'Europe/Warsaw',
    );

    expect(payload.starts_at).toBe('2026-03-29T01:30:00+01:00');
    expect(payload.ends_at).toBe('2026-03-29T03:30:00+02:00');
    expect(new Date(String(payload.ends_at)).getTime()).toBeGreaterThan(
      new Date(String(payload.starts_at)).getTime(),
    );
  });

  it('never emits a zone-less instant, whatever the draft holds', () => {
    const payload = buildEventPayload(
      draft({ starts_time: '00:00', ends_day: '2026-08-10', ends_time: '23:59' }),
      null,
      'Pacific/Kiritimati',
    );

    for (const value of [payload.starts_at, payload.ends_at]) {
      expect(value).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/);
    }
  });
});

/**
 * THE ALL-DAY DAY IS NEVER CONVERTED — the client half of the invariant the server pins in
 * `CalendarEventTest::test_a_workspace_timezone_never_shifts_an_all_day_date`.
 *
 * A day is not an instant. Passing `start_date` through anything zone-aware moves it a whole
 * day for a workspace far enough east or west, and the resulting event lands on the wrong
 * square with no error anywhere. The widest offsets in the world are used deliberately: at
 * ±14/-11 hours, ANY conversion changes the date, so this cannot pass by luck.
 */
describe('buildEventPayload — an all-day date survives an extreme zone', () => {
  it('sends the typed day verbatim from the far east and the far west', () => {
    for (const zone of ['Pacific/Kiritimati', 'Pacific/Niue', 'UTC', 'Europe/Warsaw']) {
      const payload = buildEventPayload(
        draft({ all_day: true, start_date: '2026-08-09' }),
        null,
        zone,
      );

      expect(payload.start_date).toBe('2026-08-09');
      // …and no instant is emitted at all: the timed group is FORBIDDEN on an all-day write.
      expect('starts_at' in payload).toBe(false);
      expect('ends_at' in payload).toBe(false);
    }
  });

  it('is unaffected by a time sitting in the draft next to it', () => {
    // Both groups live in the draft so flipping the switch loses nothing. The all-day write
    // must ignore the other one entirely rather than fold it into the day.
    const payload = buildEventPayload(
      draft({ all_day: true, start_date: '2026-08-09', starts_day: '2026-08-10', starts_time: '23:30' }),
      null,
      'Pacific/Kiritimati',
    );

    expect(payload.start_date).toBe('2026-08-09');
    expect('starts_at' in payload).toBe(false);
  });
});

/**
 * SCOPE ON THE WIRE.
 *
 * `series` is the wire DEFAULT, and that is a compatibility guarantee rather than a
 * convenience: a request naming no scope behaves byte for byte as it did before series
 * existed. Which is exactly why the client states its scope explicitly and only omits it when
 * it really is the whole series — "I forgot" and "rewrite the series, history included" must
 * never be the same request.
 */
describe('buildEventPayload — how much of a series a write touches', () => {
  const rule = {
    day: { mode: 'weekdays' as const, weekdays: [1] },
    month: null,
    exclusions: { dates: ['2026-08-24'] },
    until: null,
  };

  it('names NOTHING for a whole-series write — the payload is what it always was', () => {
    const payload = buildEventPayload({ ...draft(), recurrence: rule }, existingEvent(), 'Europe/Warsaw', {
      scope: 'series',
      occurrenceDate: null,
    });

    expect(payload).not.toHaveProperty('scope');
    expect(payload).not.toHaveProperty('occurrence_date');
    // The rule travels whole, `exclusions` included: dropping it resurrects every day
    // somebody deleted one at a time.
    expect(payload.recurrence).toEqual(rule);
  });

  it('defaults to the whole series when no scope is passed at all', () => {
    const payload = buildEventPayload({ ...draft(), recurrence: rule }, existingEvent(), 'Europe/Warsaw');
    expect(payload).not.toHaveProperty('scope');
    expect(payload.recurrence).toEqual(rule);
  });

  it('sends NO rule under `occurrence` — one day of a series is not itself a series', () => {
    const payload = buildEventPayload({ ...draft(), recurrence: rule }, existingEvent(), 'Europe/Warsaw', {
      scope: 'occurrence',
      occurrenceDate: '2026-09-14',
    });

    // A `recurrence` block alongside this scope is a 422 (`occurrence_has_no_rule`).
    expect(payload).not.toHaveProperty('recurrence');
    expect(payload.scope).toBe('occurrence');
    expect(payload.occurrence_date).toBe('2026-09-14');
  });

  it('sends the rule AND the day under `following`', () => {
    const payload = buildEventPayload({ ...draft(), recurrence: rule }, existingEvent(), 'Europe/Warsaw', {
      scope: 'following',
      occurrenceDate: '2026-09-14',
    });

    expect(payload.scope).toBe('following');
    expect(payload.occurrence_date).toBe('2026-09-14');
    expect(payload.recurrence).toEqual(rule);
  });

  /**
   * "Does not repeat" is the ABSENCE of the key. An empty-ish block is `filled()` server-side
   * and compiles to "every day, forever" — a series nobody asked for, reported by nothing.
   */
  it('omits the key entirely when the event does not repeat', () => {
    const withNull = buildEventPayload({ ...draft(), recurrence: null }, existingEvent(), 'UTC');
    const withAbsent = buildEventPayload(draft(), existingEvent(), 'UTC');

    expect(withNull).not.toHaveProperty('recurrence');
    expect(withAbsent).not.toHaveProperty('recurrence');
  });

  it('still carries the `subject` pointer under every scope', () => {
    const payload = buildEventPayload(
      { ...draft(), recurrence: rule },
      existingEvent({ subject: { type: 'task', id: 'task-9' } }),
      'UTC',
      { scope: 'occurrence', occurrenceDate: '2026-09-14' },
    );
    // A `create_event` step's link to what it produced must survive a scoped write too.
    expect(payload.subject_type).toBe('task');
  });
});

/**
 * THE DELETE'S OWN WIRE SHAPE. The verb names one of the three operations and the scope names
 * which: `series` removes the row, `occurrence` MODIFIES it (the day joins `exclusions`) and
 * `following` truncates it. The scope travels in the QUERY because not every HTTP client sends
 * a body on a DELETE — and `occurrence_date` alongside `series` is a 422, so the whole-series
 * call has to be bare.
 */
describe('deleteEvent — the scope travels in the query string', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    (api.delete as ReturnType<typeof vi.fn>).mockReset().mockResolvedValue(undefined);
  });

  it('sends a BARE url for the whole series — byte for byte the pre-existing request', async () => {
    await useCalendarStore().deleteEvent('evt-1');
    expect(api.delete).toHaveBeenCalledWith('/calendar/events/evt-1');
  });

  it('never leaks an occurrence day onto a whole-series delete', async () => {
    // `occurrence_date` alongside `scope=series` is a 422 (`occurrence_date_without_scope`).
    await useCalendarStore().deleteEvent('evt-1', { scope: 'series', occurrenceDate: '2026-09-14' });
    expect(api.delete).toHaveBeenCalledWith('/calendar/events/evt-1');
  });

  it('names the scope and the day for the two scoped removals', async () => {
    const store = useCalendarStore();
    await store.deleteEvent('evt-1', { scope: 'occurrence', occurrenceDate: '2026-09-14' });
    expect(api.delete).toHaveBeenCalledWith(
      '/calendar/events/evt-1?scope=occurrence&occurrence_date=2026-09-14',
    );

    await store.deleteEvent('evt-1', { scope: 'following', occurrenceDate: '2026-09-21' });
    expect(api.delete).toHaveBeenCalledWith(
      '/calendar/events/evt-1?scope=following&occurrence_date=2026-09-21',
    );
  });
});
