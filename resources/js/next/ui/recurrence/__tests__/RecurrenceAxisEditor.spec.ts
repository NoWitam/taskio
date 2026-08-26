// @vitest-environment happy-dom
// RecurrenceAxisEditor.spec — ONE editor, two profiles.
//
// The Workflows schedule trigger and the Calendar event drawer mount this same component. What
// separates them is the PROFILE, and the profile is not a styling preference: it is the
// client-side statement of what each endpoint accepts. The tests below are therefore about a
// refusal that must be unreachable, not about which cards look nicer.
//
// The Calendar refuses, verifiably (`CalendarRecurrence::dayModes()` / `daySpecials()` /
// `monthModes()`, pinned server-side in `CalendarRecurrenceGrammarTest`):
//   • the whole TIME axis — `recurrence.time` is `prohibited`,
//   • `every_n_days` / `every_n_months` — a modulo grid resets every month and every year,
//   • `last_working_day`.
// If any of those reappears in the Calendar's editor, a user can compose a rule that 422s.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import { setLocale } from '../../../app/i18n';
import { en } from '../../../app/i18n/en';
import RecurrenceAxisEditor from '../RecurrenceAxisEditor.vue';
import {
  CALENDAR_RECURRENCE_PROFILE,
  WORKFLOW_SCHEDULE_PROFILE,
  type DayAxis,
  type MonthAxis,
  type RecurrenceProfile,
  type TimeAxis,
} from '../recurrenceAxes';

const RE = en.recurrenceEditor;

interface MountOptions {
  profile: RecurrenceProfile;
  time?: TimeAxis;
  day?: DayAxis;
  month?: MonthAxis;
  anchorDay?: string | null;
  errors?: Record<string, string | undefined>;
}

function mountEditor(options: MountOptions) {
  const wrapper: VueWrapper = mount(RecurrenceAxisEditor, {
    props: {
      profile: options.profile,
      anchorDay: options.anchorDay ?? null,
      errors: options.errors ?? {},
      day: options.day ?? ({ mode: 'every_day' } as DayAxis),
      month: options.month ?? ({ mode: 'every_month' } as MonthAxis),
      ...(options.time ? { time: options.time } : {}),
      'onUpdate:day': (v: DayAxis) => wrapper.setProps({ day: v }),
      'onUpdate:month': (v: MonthAxis) => wrapper.setProps({ month: v }),
      'onUpdate:time': (v: TimeAxis) => wrapper.setProps({ time: v }),
    },
  });
  return wrapper;
}

/**
 * The option-card titles of ONE axis. Scoped by the radiogroup's own accessible name because
 * `Tabs` keeps every panel mounted — an unscoped query returns the day and month cards at once
 * and would happily "pass" while a hidden panel still offered a refused mode.
 */
function cardTitles(w: VueWrapper, axisLabel: string): string[] {
  const group = w.findAll('[role="radiogroup"]').find((g) => g.attributes('aria-label') === axisLabel);
  if (!group) throw new Error(`no radiogroup for axis "${axisLabel}"`);
  return group.findAll('button[role="radio"]').map((b) => b.text().trim());
}

/** Every tab label. */
const tabLabels = (w: VueWrapper): string[] =>
  w.findAll('[role="tab"]').map((b) => b.text().trim());

async function openTab(w: VueWrapper, label: string): Promise<void> {
  const tab = w.findAll('[role="tab"]').find((b) => b.text().trim() === label);
  if (!tab) throw new Error(`no tab "${label}"`);
  await tab.trigger('click');
  await nextTick();
}

async function pickCard(w: VueWrapper, title: string): Promise<void> {
  const card = w.findAll('button[role="radio"]').find((b) => b.text().trim() === title);
  if (!card) throw new Error(`no card "${title}"`);
  await card.trigger('click');
  await nextTick();
}

describe('RecurrenceAxisEditor', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('en');
  });
  afterEach(() => restoreBrowserMocks());

  describe('the Workflows profile speaks the whole grammar', () => {
    it('shows all three axes as tabs', () => {
      const w = mountEditor({ profile: WORKFLOW_SCHEDULE_PROFILE, time: { mode: 'at', at: ['09:00'] } });
      expect(tabLabels(w)).toEqual([RE.tab.time, RE.tab.day, RE.tab.month]);
      w.unmount();
    });

    it('offers the modulo and last-working-day cards the Calendar refuses', async () => {
      const w = mountEditor({ profile: WORKFLOW_SCHEDULE_PROFILE, time: { mode: 'at', at: ['09:00'] } });
      await openTab(w, RE.tab.day);
      expect(cardTitles(w, RE.tab.day)).toContain(RE.day.mode.everyNDays);
      expect(cardTitles(w, RE.tab.day)).toContain(RE.day.mode.lastWorkingDay);
      await openTab(w, RE.tab.month);
      expect(cardTitles(w, RE.tab.month)).toContain(RE.month.mode.everyNMonths);
      w.unmount();
    });
  });

  describe('the Calendar profile cannot compose a rule the endpoint refuses', () => {
    it('has no TIME tab at all — the event’s own hour is the series’ hour', () => {
      const w = mountEditor({ profile: CALENDAR_RECURRENCE_PROFILE });
      expect(tabLabels(w)).toEqual([RE.tab.day, RE.tab.month]);
      expect(tabLabels(w)).not.toContain(RE.tab.time);
      w.unmount();
    });

    it('hides "every N days" and "last working day" on the day axis', () => {
      const w = mountEditor({ profile: CALENDAR_RECURRENCE_PROFILE });
      const titles = cardTitles(w, RE.tab.day);
      expect(titles).toEqual([
        RE.day.mode.everyDay,
        RE.day.mode.weekdays,
        RE.day.mode.monthDays,
        RE.day.mode.lastDay,
        RE.day.mode.weekdayInMonth,
      ]);
      w.unmount();
    });

    it('hides "every N months" on the month axis', async () => {
      const w = mountEditor({ profile: CALENDAR_RECURRENCE_PROFILE });
      await openTab(w, RE.tab.month);
      expect(cardTitles(w, RE.tab.month)).toEqual([RE.month.mode.everyMonth, RE.month.mode.months]);
      w.unmount();
    });

    /**
     * The od–do window rides on the two modulo modes and nowhere else, so hiding them removes
     * it with no second switch to forget — which is why the profile says nothing about windows.
     */
    it('renders no window toggle anywhere, because the modes that carry one are gone', async () => {
      const w = mountEditor({ profile: CALENDAR_RECURRENCE_PROFILE });
      expect(w.find('button[role="switch"]').exists()).toBe(false);
      await openTab(w, RE.tab.month);
      expect(w.find('button[role="switch"]').exists()).toBe(false);
      w.unmount();
    });

    it('opens on the day axis when no tab is steered — the first axis of the profile', () => {
      const w = mountEditor({ profile: CALENDAR_RECURRENCE_PROFILE });
      const selected = w.findAll('[role="tab"]').find((t) => t.attributes('aria-selected') === 'true');
      expect(selected?.text().trim()).toBe(RE.tab.day);
      w.unmount();
    });
  });

  describe('sub-modes seed from the anchor when the caller supplies one', () => {
    /**
     * THIS IS WHAT KEEPS `anchor_not_an_occurrence` OFF THE ORDINARY PATH. The Calendar's
     * server refuses a rule whose first occurrence is not the event's own start, on the START
     * field. Seeding means the rule a user gets by picking a card already includes their start
     * day, so only a deliberate edit can move it off.
     */
    it('seeds "on weekdays" with the anchor’s own weekday', async () => {
      // 2026-08-25 is a Tuesday (2 in the 0 = Sunday convention).
      const w = mountEditor({ profile: CALENDAR_RECURRENCE_PROFILE, anchorDay: '2026-08-25' });
      await pickCard(w, RE.day.mode.weekdays);
      expect(w.props('day')).toEqual({ mode: 'weekdays', weekdays: [2] });
      w.unmount();
    });

    it('seeds "on days of the month" with the anchor’s own day', async () => {
      const w = mountEditor({ profile: CALENDAR_RECURRENCE_PROFILE, anchorDay: '2026-08-25' });
      await pickCard(w, RE.day.mode.monthDays);
      expect(w.props('day')).toEqual({ mode: 'month_days', days: [25] });
      w.unmount();
    });

    it('seeds "in selected months" with the anchor’s own month', async () => {
      const w = mountEditor({ profile: CALENDAR_RECURRENCE_PROFILE, anchorDay: '2026-08-25' });
      await openTab(w, RE.tab.month);
      await pickCard(w, RE.month.mode.months);
      expect(w.props('month')).toEqual({ mode: 'months', months: [8] });
      w.unmount();
    });

    /**
     * WITHOUT AN ANCHOR THE SEEDS STAY NEUTRAL — which is exactly the Workflows behaviour, and
     * it has to stay that way: a schedule trigger has no start day to derive from, and an empty
     * list is what makes the "pick at least one" rule fire instead of a weekday nobody chose.
     */
    it('leaves the lists empty when there is no anchor (the Workflows case)', async () => {
      const w = mountEditor({ profile: WORKFLOW_SCHEDULE_PROFILE, time: { mode: 'at', at: ['09:00'] } });
      await openTab(w, RE.tab.day);
      await pickCard(w, RE.day.mode.weekdays);
      expect(w.props('day')).toEqual({ mode: 'weekdays', weekdays: [] });
      w.unmount();
    });
  });

  describe('errors route to the axis they belong to', () => {
    it('renders a day-axis message inside the day panel, keyed axis-relative', async () => {
      const w = mountEditor({
        profile: CALENDAR_RECURRENCE_PROFILE,
        day: { mode: 'weekdays', weekdays: [] },
        errors: { 'day.weekdays': 'Pick at least one' },
      });
      expect(w.text()).toContain('Pick at least one');
      w.unmount();
    });

    it('renders a month-axis message inside the month panel', async () => {
      const w = mountEditor({
        profile: CALENDAR_RECURRENCE_PROFILE,
        month: { mode: 'months', months: [] },
        errors: { 'month.months': 'Pick a month' },
      });
      await openTab(w, RE.tab.month);
      expect(w.text()).toContain('Pick a month');
      w.unmount();
    });
  });
});
