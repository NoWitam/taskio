// Unit tests for the PURE v2 schedule helpers (§4.5, REVISION 4). These guard the
// drift-critical logic that keeps the FE aligned with the compositional descriptor
// WITHOUT mounting the builder:
//   • describeSchedule — the human cadence sentence, tested against the REAL i18n
//     catalog in BOTH locales so it is living documentation for §4.5.10 (Polish cases
//     + plurals are the point);
//   • configToDraft / draftToConfig — the FLAT wire ⇄ nested draft mapping + the
//     legacy read-shim + the omit-when-neutral emit discipline;
//   • validateScheduleDraft — the axis bounds + the window `from < to` invariant;
//   • isPreviousOccurrence + formatOccurrenceParts — the strip's helpers.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { setLocale, translate } from '../../../app/i18n';
import {
  configToDraft,
  describeSchedule,
  draftToConfig,
  emptyScheduleDraft,
  formatOccurrenceParts,
  isPreviousOccurrence,
  isScheduleDraftValid,
  occurrencePartsFormatter,
  splitSentenceTemplate,
  validateScheduleDraft,
  type DayAxis,
  type MonthAxis,
  type ScheduleDraft,
  type TimeAxis,
} from '../workflowSchedule';
import type { WorkflowScheduleConfig } from '../types';
import { en } from '../../../app/i18n/en';
import { pl } from '../../../app/i18n/pl';

// REV5: emptyScheduleDraft seeds tz to the resolved browser zone, and describeSchedule
// defaults its `activeTz` to it too (§4.5.8/§4.5.10). Pin the browser zone to 'UTC' so
// the seed + the conditional tz clause are DETERMINISTIC (a foreign zone surfaces the
// "(…)" tail; the viewer's own zone is silent). The real Intl formatters are preserved
// (only `resolvedOptions().timeZone` is overridden) so occurrence formatting still works.
const RealDateTimeFormat = Intl.DateTimeFormat;
function stubBrowserZone(zone: string): void {
  vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(((...args: unknown[]) => {
    const inst = new (RealDateTimeFormat as unknown as { new (...a: unknown[]): Intl.DateTimeFormat })(...args);
    const realResolved = inst.resolvedOptions.bind(inst);
    inst.resolvedOptions = () => ({ ...realResolved(), timeZone: zone });
    return inst;
  }) as unknown as typeof Intl.DateTimeFormat);
}

/** A draft from the neutral seed with axis overrides. */
function draft(over: Partial<ScheduleDraft> = {}): ScheduleDraft {
  return { ...emptyScheduleDraft(), ...over };
}
const time = (t: TimeAxis): Partial<ScheduleDraft> => ({ time: t });
const day = (d: DayAxis): Partial<ScheduleDraft> => ({ day: d });
const month = (m: MonthAxis): Partial<ScheduleDraft> => ({ month: m });

/** describeSchedule against the REAL catalog in the given locale. */
function say(over: Partial<ScheduleDraft>, locale: 'pl' | 'en'): string {
  setLocale(locale);
  return describeSchedule(draft(over), translate);
}

describe('describeSchedule — PL grammar (§4.5.10, living documentation)', () => {
  it('TIME head clauses', () => {
    expect(say({}, 'pl')).toBe('Codziennie o 09:00');
    expect(say(time({ mode: 'at', at: ['09:00', '17:00'] }), 'pl')).toBe('O 09:00 i 17:00');
    expect(say(time({ mode: 'every_minutes', n: 15 }), 'pl')).toBe('Co 15 minut');
    expect(say(time({ mode: 'every_minutes', n: 15, window: { from: '09:30', to: '17:45' } }), 'pl')).toBe(
      'Co 15 minut między 09:30 a 17:45',
    );
    expect(say(time({ mode: 'every_hours', n: 2, minute: 15 }), 'pl')).toBe('Co 2 godziny (o :15)');
    expect(say(time({ mode: 'every_hours', n: 2, minute: 15, window: { from: 8, to: 18 } }), 'pl')).toBe(
      'Co 2 godziny (o :15) między 08:00 a 18:00',
    );
    // minute 0 → no "(o :mm)" suffix.
    expect(say(time({ mode: 'every_hours', n: 3, minute: 0 }), 'pl')).toBe('Co 3 godziny');
  });

  it('DAY clauses (appended, lowercase)', () => {
    const at9: TimeAxis = { mode: 'at', at: ['09:00'] };
    expect(say({ ...time(at9), ...day({ mode: 'every_n_days', n: 2 }) }, 'pl')).toBe('O 09:00, co 2 dni');
    expect(say({ ...time(at9), ...day({ mode: 'every_n_days', n: 2, window: { from: 5, to: 20 } }) }, 'pl')).toBe(
      'O 09:00, co 2 dni od 5. do 20. dnia miesiąca',
    );
    expect(say({ ...time(at9), ...day({ mode: 'weekdays', weekdays: [1, 5] }) }, 'pl')).toBe('O 09:00, w poniedziałki i piątki');
    expect(say({ ...time(at9), ...day({ mode: 'weekdays', weekdays: [1, 2, 3, 4, 5] }) }, 'pl')).toBe('O 09:00, w dni robocze');
    expect(say({ ...time(at9), ...day({ mode: 'weekdays', weekdays: [0, 6] }) }, 'pl')).toBe('O 09:00, w weekendy');
    expect(say({ ...time(at9), ...day({ mode: 'month_days', days: [1, 15] }) }, 'pl')).toBe('O 09:00, 1. i 15. dnia miesiąca');
    expect(say({ ...time(at9), ...day({ mode: 'special', special: { kind: 'last_day' } }) }, 'pl')).toBe('O 09:00, ostatniego dnia miesiąca');
    expect(say({ ...time(at9), ...day({ mode: 'special', special: { kind: 'last_working_day' } }) }, 'pl')).toBe(
      'O 09:00, ostatniego dnia roboczego miesiąca',
    );
    expect(say({ ...time(at9), ...day({ mode: 'special', special: { kind: 'nth_weekday', ordinal: 2, weekday: 2 } }) }, 'pl')).toBe(
      'O 09:00, w 2. wtorek miesiąca',
    );
    expect(say({ ...time(at9), ...day({ mode: 'special', special: { kind: 'last_weekday', weekday: 5 } }) }, 'pl')).toBe(
      'O 09:00, w ostatni piątek miesiąca',
    );
  });

  it('MONTH clauses (appended, lowercase)', () => {
    expect(say(month({ mode: 'every_n_months', n: 2 }), 'pl')).toBe('Codziennie o 09:00, co 2 miesiące');
    expect(say(month({ mode: 'every_n_months', n: 2, window: { from: 3, to: 9 } }), 'pl')).toBe(
      'Codziennie o 09:00, co 2 miesiące od marca do września',
    );
    expect(say(month({ mode: 'months', months: [1, 6] }), 'pl')).toBe('Codziennie o 09:00, w styczniu i czerwcu');
  });

  it('EXCLUSIONS + tz + combined AND', () => {
    expect(say({ exclusions: { months: [7, 8], weekdays: [], dates: [] } }, 'pl')).toBe(
      'Codziennie o 09:00 — z wyjątkami: lipiec i sierpień',
    );
    expect(say({ exclusions: { months: [], weekdays: [0, 6], dates: [] } }, 'pl')).toBe(
      'Codziennie o 09:00 — z wyjątkami: weekendy',
    );
    expect(say({ exclusions: { months: [], weekdays: [], dates: ['2026-12-24'] } }, 'pl')).toBe(
      'Codziennie o 09:00 — z wyjątkami: 24.12.2026',
    );
    // date COUNT (Polish plural: few 2–4 / many 5+).
    expect(say({ exclusions: { months: [], weekdays: [], dates: ['2026-01-01', '2026-02-02', '2026-03-03'] } }, 'pl')).toBe(
      'Codziennie o 09:00 — z wyjątkami: 3 wybrane dni',
    );
    expect(
      say({ exclusions: { months: [], weekdays: [], dates: ['a', 'b', 'c', 'd', 'e'] } }, 'pl'),
    ).toContain('5 wybranych dni');
    expect(say({ tz: 'Europe/Warsaw' }, 'pl')).toBe('Codziennie o 09:00 (Europe/Warsaw)');
    // Full AND across axes.
    expect(
      say({ ...time({ mode: 'at', at: ['08:00', '17:00'] }), ...day({ mode: 'weekdays', weekdays: [1, 3, 5] }), tz: 'Europe/Warsaw' }, 'pl'),
    ).toBe('O 08:00 i 17:00, w poniedziałki, środy i piątki (Europe/Warsaw)');
  });
});

describe('describeSchedule — EN grammar (§4.5.10)', () => {
  const at9: TimeAxis = { mode: 'at', at: ['09:00'] };
  it('TIME / DAY / MONTH / exclusions', () => {
    expect(say({}, 'en')).toBe('Daily at 09:00');
    expect(say(time({ mode: 'at', at: ['09:00', '17:00'] }), 'en')).toBe('At 09:00 and 17:00');
    expect(say(time({ mode: 'every_minutes', n: 15 }), 'en')).toBe('Every 15 minutes');
    expect(say(time({ mode: 'every_minutes', n: 15, window: { from: '09:30', to: '17:45' } }), 'en')).toBe(
      'Every 15 minutes between 09:30 and 17:45',
    );
    expect(say(time({ mode: 'every_hours', n: 2, minute: 15 }), 'en')).toBe('Every 2 hours (at :15)');
    expect(say(time({ mode: 'every_hours', n: 2, minute: 15, window: { from: 8, to: 18 } }), 'en')).toBe(
      'Every 2 hours (at :15) between 08:00 and 18:00',
    );
    expect(say({ ...time(at9), ...day({ mode: 'every_n_days', n: 2, window: { from: 5, to: 20 } }) }, 'en')).toBe(
      'At 09:00, every 2 days from the 5th to the 20th of the month',
    );
    expect(say({ ...time(at9), ...day({ mode: 'weekdays', weekdays: [1, 5] }) }, 'en')).toBe('At 09:00, on Mondays and Fridays');
    expect(say({ ...time(at9), ...day({ mode: 'weekdays', weekdays: [1, 2, 3, 4, 5] }) }, 'en')).toBe('At 09:00, on workdays');
    expect(say({ ...time(at9), ...day({ mode: 'month_days', days: [1, 15] }) }, 'en')).toBe('At 09:00, on the 1st and 15th of the month');
    expect(say({ ...time(at9), ...day({ mode: 'special', special: { kind: 'nth_weekday', ordinal: 2, weekday: 2 } }) }, 'en')).toBe(
      'At 09:00, on the 2nd Tuesday of the month',
    );
    expect(say({ ...time(at9), ...day({ mode: 'special', special: { kind: 'last_weekday', weekday: 5 } }) }, 'en')).toBe(
      'At 09:00, on the last Friday of the month',
    );
    expect(say(month({ mode: 'every_n_months', n: 2, window: { from: 3, to: 9 } }), 'en')).toBe(
      'Daily at 09:00, every 2 months from March to September',
    );
    expect(say(month({ mode: 'months', months: [1, 6] }), 'en')).toBe('Daily at 09:00, in January and June');
    expect(say({ exclusions: { months: [7, 8], weekdays: [], dates: [] } }, 'en')).toBe('Daily at 09:00 — except: July and August');
    expect(
      say({ ...time({ mode: 'at', at: ['08:00', '17:00'] }), ...day({ mode: 'weekdays', weekdays: [1, 3, 5] }), tz: 'Europe/Warsaw' }, 'en'),
    ).toBe('At 08:00 and 17:00, on Mondays, Wednesdays and Fridays (Europe/Warsaw)');
  });
});

describe('describeSchedule — REV5 conditional tz clause (§4.5.10, decision C)', () => {
  it('suppresses "({tz})" when the schedule tz equals the viewer zone', () => {
    setLocale('en');
    // The default activeTz is the stubbed browser zone 'UTC'.
    expect(describeSchedule(draft({ tz: 'UTC' }), translate)).toBe('Daily at 09:00');
    // The browser-seeded neutral draft (tz 'UTC') is likewise silent.
    expect(describeSchedule(emptyScheduleDraft(), translate)).toBe('Daily at 09:00');
  });

  it('shows "({tz})" only for a FOREIGN zone (differs from the viewer)', () => {
    setLocale('en');
    expect(describeSchedule(draft({ tz: 'Europe/Warsaw' }), translate)).toBe('Daily at 09:00 (Europe/Warsaw)');
    // An explicit activeTz overrides the browser default: a matching zone → suppressed.
    expect(describeSchedule(draft({ tz: 'Europe/Warsaw' }), translate, 'Europe/Warsaw')).toBe('Daily at 09:00');
    // A blank activeTz never suppresses.
    expect(describeSchedule(draft({ tz: 'Europe/Warsaw' }), translate, '')).toBe('Daily at 09:00 (Europe/Warsaw)');
  });
});

describe('splitSentenceTemplate — {slot} split, order lives in the string (§4.5.12)', () => {
  it('splits literals + {slot}s in order', () => {
    expect(splitSentenceTemplate('co {n} minut')).toEqual([
      { type: 'text', value: 'co ' },
      { type: 'slot', name: 'n' },
      { type: 'text', value: ' minut' },
    ]);
  });

  it('reads slot ORDER from the string (PL vs EN reorder freely)', () => {
    // A synthetic pair proving the renderer never hardcodes order: same slots, reversed.
    expect(splitSentenceTemplate('{a} X {b}')).toEqual([
      { type: 'slot', name: 'a' },
      { type: 'text', value: ' X ' },
      { type: 'slot', name: 'b' },
    ]);
    expect(splitSentenceTemplate('{b} Y {a}')).toEqual([
      { type: 'slot', name: 'b' },
      { type: 'text', value: ' Y ' },
      { type: 'slot', name: 'a' },
    ]);
  });

  it('drops empty runs (adjacent / leading / trailing tokens)', () => {
    expect(splitSentenceTemplate('{from}{to}')).toEqual([
      { type: 'slot', name: 'from' },
      { type: 'slot', name: 'to' },
    ]);
  });

  it('matches the REAL card templates in BOTH locales (living documentation)', () => {
    const slotNames = (segs: ReturnType<typeof splitSentenceTemplate>) =>
      segs.filter((s) => s.type === 'slot').map((s) => (s as { name: string }).name);

    // The day-window template differs in LITERAL placement between PL and EN, yet both
    // carry the {from}/{to} slots in the same read order — order is the string's job.
    const plDay = splitSentenceTemplate(pl.workflows.schedule.day.card.everyNDays.window);
    const enDay = splitSentenceTemplate(en.workflows.schedule.day.card.everyNDays.window);
    expect(slotNames(plDay)).toEqual(['from', 'to']);
    expect(slotNames(enDay)).toEqual(['from', 'to']);
    // PL ends with a trailing literal ("dnia miesiąca"); EN leads with "from day".
    expect(plDay[plDay.length - 1]).toEqual({ type: 'text', value: ' dnia miesiąca' });
    expect(enDay[0]).toEqual({ type: 'text', value: 'from day ' });

    // The weekday-in-month head carries the ordinal + weekday slots in order.
    expect(slotNames(splitSentenceTemplate(en.workflows.schedule.day.card.weekdayInMonth.head))).toEqual([
      'ordinal',
      'weekday',
    ]);
  });
});

describe('configToDraft / draftToConfig — flat wire ⇄ nested draft', () => {
  it('draftToConfig omits neutral axes / empty exclusions; REV5 seeds the browser tz', () => {
    // REV5: the neutral draft SEEDS the browser zone (stubbed 'UTC'), so it wires an explicit tz.
    expect(draftToConfig(emptyScheduleDraft())).toEqual({ time: { mode: 'at', at: ['09:00'] }, tz: 'UTC' });
    // A BLANK tz is still omitted (the omit-when-blank discipline is unchanged).
    expect(draftToConfig({ ...emptyScheduleDraft(), tz: '' })).toEqual({ time: { mode: 'at', at: ['09:00'] } });
  });

  it('draftToConfig flattens the interval + window', () => {
    // tz '' keeps these axis-focused assertions free of the REV5 browser-tz seed.
    expect(
      draftToConfig(draft({ ...time({ mode: 'every_minutes', n: 15, window: { from: '09:30', to: '17:45' } }), tz: '' })),
    ).toEqual({ time: { mode: 'every_minutes', minutes: 15, from: '09:30', to: '17:45' } });
    expect(
      draftToConfig(draft({ ...time({ mode: 'every_hours', n: 2, minute: 15, window: { from: 8, to: 18 } }), tz: '' })),
    ).toEqual({ time: { mode: 'every_hours', hours: 2, minute: 15, from: 8, to: 18 } });
  });

  it('draftToConfig maps a day special to the FLAT string enum + params', () => {
    expect(draftToConfig(draft(day({ mode: 'special', special: { kind: 'nth_weekday', ordinal: 3, weekday: 4 } }))).day).toEqual({
      mode: 'special',
      special: 'nth_weekday',
      ordinal: 3,
      weekday: 4,
    });
    expect(draftToConfig(draft(day({ mode: 'special', special: { kind: 'last_day' } }))).day).toEqual({ mode: 'special', special: 'last_day' });
  });

  it('draftToConfig emits only non-empty exclusion keys + a set tz', () => {
    const config = draftToConfig(draft({ exclusions: { months: [8], weekdays: [], dates: ['2026-01-01'] }, tz: 'UTC' }));
    expect(config.exclusions).toEqual({ months: [8], dates: ['2026-01-01'] });
    expect(config.tz).toBe('UTC');
  });

  it('round-trips a rich v2 config', () => {
    const config: WorkflowScheduleConfig = {
      time: { mode: 'at', at: ['08:00', '17:00'] },
      day: { mode: 'weekdays', weekdays: [1, 3] },
      month: { mode: 'months', months: [1, 6] },
      exclusions: { weekdays: [0] },
      tz: 'Europe/Warsaw',
    };
    expect(draftToConfig(configToDraft(config))).toEqual(config);
  });

  it('configToDraft reads the neutral wire back to the neutral draft (tz-less wire → tz "")', () => {
    // The wire carries no tz → the draft reads tz '' (distinct from the browser-seeded neutral draft).
    expect(configToDraft({ time: { mode: 'at', at: ['09:00'] } })).toEqual({ ...emptyScheduleDraft(), tz: '' });
  });

  it('configToDraft tolerantly upgrades a LEGACY {family, params} block (seed only)', () => {
    expect(configToDraft({ family: 'daily', params: { time: '09:00' }, tz: null } as never)).toEqual({ ...emptyScheduleDraft(), tz: '' });
    const weekly = configToDraft({ family: 'weekly', params: { weekdays: [1, 3], time: '07:30' } } as never);
    expect(weekly.time).toEqual({ mode: 'at', at: ['07:30'] });
    expect(weekly.day).toEqual({ mode: 'weekdays', weekdays: [1, 3] });
  });
});

describe('validateScheduleDraft — client rules (§4.5.11)', () => {
  const keys = (d: ScheduleDraft): string[] => validateScheduleDraft(d).map((e) => e.key);

  it('the neutral draft is valid', () => {
    expect(isScheduleDraftValid(emptyScheduleDraft())).toBe(true);
  });

  it('time.at: required / format / duplicate / max', () => {
    expect(keys(draft(time({ mode: 'at', at: [''] })))).toContain('workflows.schedule.validation.timeRequired');
    expect(keys(draft(time({ mode: 'at', at: ['9am'] })))).toContain('workflows.schedule.validation.timeFormat');
    expect(keys(draft(time({ mode: 'at', at: ['09:00', '09:00'] })))).toContain('workflows.schedule.validation.timeDuplicate');
    expect(keys(draft(time({ mode: 'at', at: ['1:00', '2:00', '3:00', '4:00', '5:00', '6:00', '7:00'] })))).toContain(
      'workflows.schedule.validation.timesMax',
    );
  });

  it('interval bounds + window order', () => {
    expect(keys(draft(time({ mode: 'every_minutes', n: 0 })))).toContain('workflows.schedule.validation.min');
    expect(keys(draft(time({ mode: 'every_minutes', n: 99 })))).toContain('workflows.schedule.validation.max');
    expect(keys(draft(time({ mode: 'every_minutes', n: 15, window: { from: '17:00', to: '09:00' } })))).toContain(
      'workflows.schedule.validation.windowOrder',
    );
    expect(keys(draft(day({ mode: 'every_n_days', n: 2, window: { from: 20, to: 5 } })))).toContain(
      'workflows.schedule.validation.windowOrder',
    );
  });

  it('non-empty sets + ordinal bounds', () => {
    expect(keys(draft(day({ mode: 'weekdays', weekdays: [] })))).toContain('workflows.schedule.validation.pickAtLeastOne');
    expect(keys(draft(day({ mode: 'month_days', days: [] })))).toContain('workflows.schedule.validation.pickAtLeastOne');
    expect(keys(draft(month({ mode: 'months', months: [] })))).toContain('workflows.schedule.validation.pickAtLeastOne');
    expect(keys(draft(day({ mode: 'special', special: { kind: 'nth_weekday', ordinal: 6, weekday: 1 } })))).toContain(
      'workflows.schedule.validation.max',
    );
  });

  it('exclusions.dates: over-limit + duplicate', () => {
    const many = Array.from({ length: 51 }, (_, i) => `2026-01-${String((i % 28) + 1).padStart(2, '0')}#${i}`);
    expect(keys(draft({ exclusions: { months: [], weekdays: [], dates: many } }))).toContain('workflows.schedule.validation.max');
    expect(keys(draft({ exclusions: { months: [], weekdays: [], dates: ['2026-01-01', '2026-01-01'] } }))).toContain(
      'workflows.schedule.validation.timeDuplicate',
    );
  });
});

describe('strip helpers', () => {
  it('isPreviousOccurrence: at-or-before the anchor, else false', () => {
    expect(isPreviousOccurrence('2026-07-10T08:00:00Z', '2026-07-10T12:00:00Z')).toBe(true);
    expect(isPreviousOccurrence('2026-07-10T12:00:00Z', '2026-07-10T12:00:00Z')).toBe(true);
    expect(isPreviousOccurrence('2026-07-11T08:00:00Z', '2026-07-10T12:00:00Z')).toBe(false);
    expect(isPreviousOccurrence('2026-07-10T08:00:00Z', null)).toBe(false);
  });

  it('formatOccurrenceParts renders weekday / date / time in the tz', () => {
    setLocale('en');
    const parts = formatOccurrenceParts('2026-07-13T09:00:00Z', occurrencePartsFormatter('en', 'UTC'));
    expect(parts.time).toBe('09:00');
    expect(parts.weekday.length).toBeGreaterThan(0);
    expect(parts.date.length).toBeGreaterThan(0);
  });
});

beforeEach(() => {
  setLocale('en');
  stubBrowserZone('UTC');
});
afterEach(() => vi.restoreAllMocks());
