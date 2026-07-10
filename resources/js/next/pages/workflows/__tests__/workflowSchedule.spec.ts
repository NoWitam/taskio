// Unit tests for the PURE schedule helpers (B7a + B4, §4.5, §8.4 REQUIREMENT). These
// guard the drift-critical logic that keeps the FE aligned with the descriptor
// contract WITHOUT mounting the builder: descriptor lookup, the client-side
// bound + `lt` + weekday_list + times + exclusions validators, the human
// describeSchedule sentence (with the multi-time + exclusions clauses + the four new
// families), and the configToDraft / draftToConfig assist-apply + save mapping
// (time↔times[] unification + exclusions emit-or-omit + simple-mode representability).
import { describe, it, expect } from 'vitest';
import {
  configToDraft,
  describeSchedule,
  draftToConfig,
  emptyScheduleDraft,
  findFamilyDescriptor,
  findParamDescriptor,
  intentForFamily,
  isScheduleDraftValid,
  isSimpleRepresentable,
  paramDescriptorsFor,
  validateScheduleDraft,
  type ScheduleDraft,
} from '../workflowSchedule';
import type { ScheduleFamilyDescriptor } from '../types';

// The descriptor catalog mirrors the B4 backend WorkflowScheduleFamily::paramDescriptors
// for the families the tests exercise (bounds, the two `lt` invariants, weekday_list).
const FAMILIES: ScheduleFamilyDescriptor[] = [
  { family: 'every_n_minutes', params: [{ name: 'n', type: 'int', required: true, min: 1, max: 59 }] },
  { family: 'hourly', params: [] },
  { family: 'daily', params: [{ name: 'time', type: 'time', required: true }] },
  {
    family: 'twice_daily',
    params: [
      { name: 'first_hour', type: 'int', required: true, min: 0, max: 23, lt: 'second_hour' },
      { name: 'second_hour', type: 'int', required: true, min: 0, max: 23 },
      { name: 'minute', type: 'int', required: false, min: 0, max: 59 },
    ],
  },
  {
    family: 'weekly',
    params: [
      { name: 'weekdays', type: 'weekday_list', required: true },
      { name: 'time', type: 'time', required: true },
    ],
  },
  {
    family: 'twice_monthly',
    params: [
      { name: 'first_day', type: 'int', required: true, min: 1, max: 31, lt: 'second_day' },
      { name: 'second_day', type: 'int', required: true, min: 1, max: 31 },
      { name: 'time', type: 'time', required: true },
    ],
  },
  {
    family: 'yearly',
    params: [
      { name: 'month', type: 'int', required: true, min: 1, max: 12 },
      { name: 'day', type: 'int', required: true, min: 1, max: 31 },
      { name: 'time', type: 'time', required: true },
    ],
  },
  {
    family: 'nth_weekday_of_month',
    params: [
      { name: 'ordinal', type: 'int', required: true, min: 1, max: 5 },
      { name: 'weekday', type: 'weekday', required: true, min: 0, max: 6 },
      { name: 'time', type: 'time', required: true },
    ],
  },
  {
    family: 'last_weekday_of_month',
    params: [
      { name: 'weekday', type: 'weekday', required: true, min: 0, max: 6 },
      { name: 'time', type: 'time', required: true },
    ],
  },
  { family: 'last_working_day_of_month', params: [{ name: 'time', type: 'time', required: true }] },
  {
    family: 'every_n_months',
    params: [
      { name: 'n', type: 'int', required: true, min: 2, max: 6 },
      { name: 'day', type: 'int', required: true, min: 1, max: 31 },
      { name: 'time', type: 'time', required: true },
    ],
  },
];

/** A translator that echoes `key|param1=v1,param2=v2` so assertions read the wiring. */
function fakeT(key: string, _def?: string, params?: Record<string, string | number>): string {
  if (!params) return key;
  const parts = Object.entries(params)
    .map(([k, v]) => `${k}=${v}`)
    .join(',');
  return `${key}|${parts}`;
}

/** A translator that returns human-ish tokens so describe clauses read naturally. */
function labelT(key: string, _def?: string, params?: Record<string, string | number>): string {
  const table: Record<string, string> = {
    'workflows.schedule.utc': 'UTC',
    'workflows.schedule.and': 'and',
    'workflows.schedule.describe.timeClause': 'at {times}',
    'workflows.schedule.describe.exclusionClause': 'except: {list}',
    'workflows.schedule.describe.exclusionSeparator': '·',
    'workflows.schedule.weekday.0': 'Sunday',
    'workflows.schedule.weekday.1': 'Monday',
    'workflows.schedule.weekday.3': 'Wednesday',
    'workflows.schedule.weekday.6': 'Saturday',
    'workflows.schedule.month.8': 'August',
    'workflows.schedule.ordinal.2': 'second',
    'workflows.schedule.describe.weekly': 'Weekly on {weekdays} {time} ({tz})',
    'workflows.schedule.describe.every_n_months': 'Every {n} months on day {day} {time} ({tz})',
    'workflows.schedule.describe.nth_weekday_of_month': 'On the {ordinal} {weekday} of each month {time} ({tz})',
    'workflows.schedule.describe.last_weekday_of_month': 'On the last {weekday} of each month {time} ({tz})',
    'workflows.schedule.describe.last_working_day_of_month': 'On the last working day of each month {time} ({tz})',
    'workflows.schedule.describe.daily': 'Daily {time} ({tz})',
  };
  let text = table[key] ?? key;
  if (params) {
    for (const [k, v] of Object.entries(params)) text = text.replace(`{${k}}`, String(v));
  }
  return text;
}

describe('descriptor lookup', () => {
  it('finds a family + its params, and a single param descriptor', () => {
    expect(findFamilyDescriptor(FAMILIES, 'daily')?.family).toBe('daily');
    expect(findFamilyDescriptor(FAMILIES, 'nope')).toBeNull();
    expect(paramDescriptorsFor(FAMILIES, 'twice_daily').map((p) => p.name)).toEqual([
      'first_hour',
      'second_hour',
      'minute',
    ]);
    expect(findParamDescriptor(FAMILIES, 'every_n_minutes', 'n')?.max).toBe(59);
    expect(findParamDescriptor(FAMILIES, 'every_n_minutes', 'missing')).toBeNull();
  });
});

describe('validateScheduleDraft — bounds + required + lt + times', () => {
  function draft(overrides: Partial<ScheduleDraft>): ScheduleDraft {
    return { family: 'daily', params: {}, tz: '', times: [], exclusions: { months: [], weekdays: [], dates: [] }, ...overrides };
  }

  it('flags a missing required time via the times editor', () => {
    const errs = validateScheduleDraft(FAMILIES, draft({ family: 'daily', params: {}, times: [] }));
    expect(errs).toContainEqual({ param: 'times', key: 'workflows.schedule.validation.timesRequired' });
  });

  it('accepts a valid daily draft (single time in times[])', () => {
    const d = draft({ family: 'daily', params: {}, times: ['09:00'] });
    expect(validateScheduleDraft(FAMILIES, d)).toEqual([]);
    expect(isScheduleDraftValid(FAMILIES, d)).toBe(true);
  });

  it('enforces the descriptor min/max bounds', () => {
    const tooLow = validateScheduleDraft(FAMILIES, draft({ family: 'every_n_minutes', params: { n: 0 } }));
    expect(tooLow).toContainEqual({ param: 'n', key: 'workflows.schedule.validation.min', messageParams: { min: 1 } });
    const tooHigh = validateScheduleDraft(FAMILIES, draft({ family: 'every_n_minutes', params: { n: 99 } }));
    expect(tooHigh).toContainEqual({ param: 'n', key: 'workflows.schedule.validation.max', messageParams: { max: 59 } });
  });

  it('enforces the twice_daily lt invariant (first_hour < second_hour)', () => {
    const bad = validateScheduleDraft(
      FAMILIES,
      draft({ family: 'twice_daily', params: { first_hour: 17, second_hour: 9 } }),
    );
    expect(bad).toContainEqual({
      param: 'first_hour',
      key: 'workflows.schedule.validation.lt',
      messageParams: { field: 'first_hour', other: 'second_hour' },
    });
    const equal = validateScheduleDraft(
      FAMILIES,
      draft({ family: 'twice_daily', params: { first_hour: 9, second_hour: 9 } }),
    );
    expect(equal.some((e) => e.key === 'workflows.schedule.validation.lt')).toBe(true);
  });

  it('validates weekday_list: non-empty / unique / range', () => {
    const empty = validateScheduleDraft(FAMILIES, draft({ family: 'weekly', params: { weekdays: [] }, times: ['08:00'] }));
    expect(empty).toContainEqual({ param: 'weekdays', key: 'workflows.schedule.validation.weekdayListRequired' });

    const dup = validateScheduleDraft(FAMILIES, draft({ family: 'weekly', params: { weekdays: [1, 1] }, times: ['08:00'] }));
    expect(dup).toContainEqual({ param: 'weekdays', key: 'workflows.schedule.validation.weekdayListDuplicate' });

    const ok = validateScheduleDraft(FAMILIES, draft({ family: 'weekly', params: { weekdays: [1, 3] }, times: ['08:00'] }));
    expect(ok).toEqual([]);
  });

  it('validates times: duplicate, over limit, and bad format', () => {
    const dup = validateScheduleDraft(FAMILIES, draft({ family: 'daily', times: ['08:00', '08:00'] }));
    expect(dup).toContainEqual({ param: 'times', key: 'workflows.schedule.validation.timesDuplicate' });

    const tooMany = validateScheduleDraft(
      FAMILIES,
      draft({ family: 'daily', times: ['01:00', '02:00', '03:00', '04:00', '05:00', '06:00', '07:00'] }),
    );
    expect(tooMany).toContainEqual({ param: 'times', key: 'workflows.schedule.validation.timesMax', messageParams: { max: 6 } });

    const bad = validateScheduleDraft(FAMILIES, draft({ family: 'daily', times: ['99:99'] }));
    expect(bad).toContainEqual({ param: 'times', key: 'workflows.schedule.validation.timesFormat' });
  });

  it('validates exclusions bounds + limits', () => {
    const months = validateScheduleDraft(FAMILIES, draft({ family: 'daily', times: ['08:00'], exclusions: { months: [0, 13], weekdays: [], dates: [] } }));
    expect(months).toContainEqual({ param: 'exclusions.months', key: 'workflows.schedule.validation.exclusionsMonths' });

    const tooManyDates = validateScheduleDraft(
      FAMILIES,
      draft({ family: 'daily', times: ['08:00'], exclusions: { months: [], weekdays: [], dates: Array.from({ length: 51 }, (_, i) => `2026-01-${String((i % 28) + 1).padStart(2, '0')}-${i}`) } }),
    );
    expect(tooManyDates.some((e) => e.param === 'exclusions.dates')).toBe(true);
  });
});

describe('describeSchedule — i18n-driven cadence sentence (§4.5.6, B4)', () => {
  function base(overrides: Record<string, unknown> = {}) {
    return { family: 'daily' as const, params: {}, tz: 'UTC', ...overrides };
  }

  it('interpolates n + tz for every_n_minutes', () => {
    expect(describeSchedule({ family: 'every_n_minutes', params: { n: 15 }, tz: 'Europe/Warsaw' }, fakeT)).toBe(
      'workflows.schedule.describe.every_n_minutes|n=15,tz=Europe/Warsaw',
    );
  });

  it('falls back to the UTC label when tz is blank', () => {
    expect(describeSchedule({ family: 'hourly', params: {}, tz: '' }, fakeT)).toBe(
      'workflows.schedule.describe.hourly|tz=workflows.schedule.utc',
    );
  });

  it('weekly renders a LIST of days + a time clause', () => {
    const text = describeSchedule({ family: 'weekly', params: { weekdays: [1, 3] }, times: ['08:00'], tz: 'UTC' }, labelT);
    expect(text).toBe('Weekly on Monday and Wednesday at 08:00 (UTC)');
  });

  it('multiple times render a joined time clause', () => {
    const text = describeSchedule({ family: 'daily', params: {}, times: ['08:00', '12:30', '17:00'], tz: 'UTC' }, labelT);
    expect(text).toBe('Daily at 08:00, 12:30 and 17:00 (UTC)');
  });

  it('appends an exclusions clause (only present arrays)', () => {
    const text = describeSchedule(
      { family: 'daily', params: {}, times: ['08:00'], tz: 'UTC', exclusions: { weekdays: [0, 6], months: [8], dates: ['2026-12-24'] } },
      labelT,
    );
    expect(text).toBe('Daily at 08:00 (UTC) except: Sunday and Saturday · August · 24.12.2026');
  });

  it('describes the four new families', () => {
    expect(describeSchedule({ family: 'every_n_months', params: { n: 3, day: 1 }, times: ['08:00'], tz: 'UTC' }, labelT)).toBe(
      'Every 3 months on day 1 at 08:00 (UTC)',
    );
    expect(describeSchedule({ family: 'nth_weekday_of_month', params: { ordinal: 2, weekday: 3 }, times: ['08:00'], tz: 'UTC' }, labelT)).toBe(
      'On the second Wednesday of each month at 08:00 (UTC)',
    );
    expect(describeSchedule({ family: 'last_weekday_of_month', params: { weekday: 6 }, times: ['08:00'], tz: 'UTC' }, labelT)).toBe(
      'On the last Saturday of each month at 08:00 (UTC)',
    );
    expect(describeSchedule({ family: 'last_working_day_of_month', params: {}, times: ['08:00'], tz: 'UTC' }, labelT)).toBe(
      'On the last working day of each month at 08:00 (UTC)',
    );
  });

  it('twice_daily composes full HH:mm times including the shared minute (U3 regression)', () => {
    // The template used to hardcode ':00', so a 09:30/17:30 schedule read as 9:00/17:00.
    expect(
      describeSchedule({ family: 'twice_daily', params: { first_hour: 9, second_hour: 17, minute: 30 }, tz: 'UTC' }, fakeT),
    ).toBe('workflows.schedule.describe.twice_daily|first=09:30,second=17:30,tz=UTC');
  });
});

describe('configToDraft / draftToConfig — time↔times[] + exclusions mapping', () => {
  it('configToDraft lifts params.time into times[] and defaults empty exclusions', () => {
    expect(configToDraft({ family: 'daily', params: { time: '09:00' }, tz: null })).toEqual({
      family: 'daily',
      params: {},
      tz: '',
      times: ['09:00'],
      exclusions: { months: [], weekdays: [], dates: [] },
    });
  });

  it('configToDraft keeps a multi-time times[] and hydrates exclusions', () => {
    const draft = configToDraft({
      family: 'daily',
      params: {},
      times: ['08:00', '17:00'],
      tz: 'UTC',
      exclusions: { weekdays: [0, 6] },
    });
    expect(draft.times).toEqual(['08:00', '17:00']);
    expect(draft.exclusions).toEqual({ months: [], weekdays: [0, 6], dates: [] });
  });

  it('draftToConfig: single time → params.time (no times key)', () => {
    const config = draftToConfig(
      { family: 'daily', params: {}, tz: '', times: ['09:00'], exclusions: { months: [], weekdays: [], dates: [] } },
      FAMILIES,
    );
    expect(config).toEqual({ family: 'daily', params: { time: '09:00' } });
    expect('times' in config).toBe(false);
  });

  it('draftToConfig: multiple times → times[] (no params.time)', () => {
    const config = draftToConfig(
      { family: 'daily', params: {}, tz: '', times: ['08:00', '17:00'], exclusions: { months: [], weekdays: [], dates: [] } },
      FAMILIES,
    );
    expect(config.times).toEqual(['08:00', '17:00']);
    expect('time' in config.params).toBe(false);
  });

  it('draftToConfig emits exclusions only when non-empty, with only present keys', () => {
    const none = draftToConfig(
      { family: 'daily', params: {}, tz: '', times: ['08:00'], exclusions: { months: [], weekdays: [], dates: [] } },
      FAMILIES,
    );
    expect('exclusions' in none).toBe(false);

    const some = draftToConfig(
      { family: 'daily', params: {}, tz: '', times: ['08:00'], exclusions: { months: [8], weekdays: [], dates: [] } },
      FAMILIES,
    );
    expect(some.exclusions).toEqual({ months: [8] });
  });

  it('draftToConfig drops FOREIGN params and a blank tz', () => {
    const config = draftToConfig(
      { family: 'daily', params: { weekday: 3 }, tz: '  ', times: ['09:00'], exclusions: { months: [], weekdays: [], dates: [] } },
      FAMILIES,
    );
    expect(config).toEqual({ family: 'daily', params: { time: '09:00' } });
    expect('tz' in config).toBe(false);
  });

  it('weekly round-trips a weekdays list + time', () => {
    const original = { family: 'weekly' as const, params: { weekdays: [1, 3] }, times: ['07:30'], tz: 'UTC' };
    const roundTripped = draftToConfig(configToDraft(original), FAMILIES);
    expect(roundTripped).toEqual({ family: 'weekly', params: { weekdays: [1, 3], time: '07:30' }, tz: 'UTC' });
  });
});

describe('emptyScheduleDraft', () => {
  it('seeds int/weekday params, a weekday_list as [], and a single blank time', () => {
    // twice_daily has no `time` descriptor in this fixture → times stays [].
    expect(emptyScheduleDraft(FAMILIES, 'twice_daily')).toEqual({
      family: 'twice_daily',
      params: { first_hour: 0, second_hour: 0, minute: 0 },
      tz: '',
      times: [],
      exclusions: { months: [], weekdays: [], dates: [] },
    });
    expect(emptyScheduleDraft(FAMILIES, 'weekly')).toEqual({
      family: 'weekly',
      params: { weekdays: [] },
      tz: '',
      times: [''],
      exclusions: { months: [], weekdays: [], dates: [] },
    });
    expect(emptyScheduleDraft(FAMILIES, 'every_n_minutes')).toEqual({
      family: 'every_n_minutes',
      params: { n: 1 },
      tz: '',
      times: [],
      exclusions: { months: [], weekdays: [], dates: [] },
    });
  });
});

describe('simple-mode representability', () => {
  function draft(overrides: Partial<ScheduleDraft>): ScheduleDraft {
    return { family: 'daily', params: {}, tz: '', times: ['09:00'], exclusions: { months: [], weekdays: [], dates: [] }, ...overrides };
  }

  it('maps families to intents', () => {
    expect(intentForFamily('every_n_minutes')).toBe('minutes');
    expect(intentForFamily('every_n_hours')).toBe('hours');
    expect(intentForFamily('daily')).toBe('daily');
    expect(intentForFamily('weekly')).toBe('weekly');
    expect(intentForFamily('monthly')).toBe('monthly');
    expect(intentForFamily('last_day_of_month')).toBe('monthly');
    expect(intentForFamily('twice_daily')).toBeNull();
    expect(intentForFamily('yearly')).toBeNull();
  });

  it('is representable for a simple family + single time + no exclusions', () => {
    expect(isSimpleRepresentable(draft({ family: 'daily' }))).toBe(true);
  });

  it('is NOT representable for advanced-only families / multi-time / exclusions', () => {
    expect(isSimpleRepresentable(draft({ family: 'twice_daily' }))).toBe(false);
    expect(isSimpleRepresentable(draft({ family: 'daily', times: ['08:00', '17:00'] }))).toBe(false);
    expect(isSimpleRepresentable(draft({ family: 'daily', exclusions: { months: [8], weekdays: [], dates: [] } }))).toBe(false);
  });
});
